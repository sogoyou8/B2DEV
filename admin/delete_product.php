<?php
// Démarrage session + buffer pour éviter "headers already sent"
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();

// Garantit la fermeture du buffer à la fin du script (même après exit)
register_shutdown_function(function () {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

// Vérification accès admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'admin_demo_guard.php';
include '../includes/db.php';
include 'includes/header.php';

// Récupérer et valider l'ID
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID de produit invalide.";
    header("Location: list_products.php");
    exit;
}

// Vérifier que le produit existe
try {
    $q = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $q->execute([$id]);
    $product = $q->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // journaliser et rediriger
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Erreur lecture produit ID $id : " . $e->getMessage()
        ]);
    } catch (Exception $_) {
        // ignore
    }
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_products.php");
    exit;
}

if (!$product) {
    // Notification persistante en cas d'échec
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Échec suppression produit : ID $id introuvable (admin ID " . ($_SESSION['admin_id'] ?? 'inconnu') . ")"
        ]);
    } catch (Exception $_) {
        // ignorer
    }
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Nom du produit sécurisé pour logs/notifications
$product_name = $product['name'] ?? ('ID ' . (int)$id);

// Protection mode démo
if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
    $_SESSION['error'] = "Action désactivée en mode démo.";
    header("Location: list_products.php");
    exit;
}

try {
    $pdo->beginTransaction();

    // Vérifier références dans order_details
    $refStmt = $pdo->prepare("SELECT COUNT(*) FROM order_details WHERE item_id = ?");
    $refStmt->execute([$id]);
    $refCount = (int)$refStmt->fetchColumn();

    if ($refCount > 0) {
        // Produit référencé par des commandes -> soft-delete
        $upd = $pdo->prepare("UPDATE items SET is_active = 0, deleted_at = NOW() WHERE id = ?");
        $ok = $upd->execute([$id]);

        if ($ok) {
            // Nettoyer paniers/favoris pour empêcher nouvel achat depuis le front
            try {
                $pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$id]);
            } catch (Exception $_) {
                // ne pas bloquer la désactivation si ces suppressions échouent
            }

            // Notification non persistante indiquant la désactivation
            try {
                $note = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
                $note->execute([
                    'info',
                    "Produit '{$product_name}' désactivé par " . ($_SESSION['admin_name'] ?? 'admin') . " (présent dans {$refCount} commande(s))"
                ]);
            } catch (Exception $_) {
                // ignore logging failure
            }

            $pdo->commit();
            $_SESSION['success'] = "Produit désactivé car lié à des commandes existantes.";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de la désactivation du produit.";
        }
    } else {
        // Aucun lien dans order_details -> suppression physique
        // Supprimer les prévisions liées (FK sans ON DELETE CASCADE)
        $stmt = $pdo->prepare("DELETE FROM previsions WHERE item_id = ?");
        $stmt->execute([$id]);

        // Supprimer les entrées panier liées (si existant)
        $stmt = $pdo->prepare("DELETE FROM cart WHERE item_id = ?");
        $stmt->execute([$id]);

        // Supprimer favoris
        $stmt = $pdo->prepare("DELETE FROM favorites WHERE item_id = ?");
        $stmt->execute([$id]);

        // Supprimer images physiques et enregistrements product_images
        $stmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ?");
        $stmt->execute([$id]);
        $imgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $failed_unlinks = [];
        foreach ($imgs as $img) {
            $paths = [
                __DIR__ . "/../assets/images/" . $img['image'],
                __DIR__ . "/../assets/images/products/" . $img['image'],
                __DIR__ . "/../assets/images/" . basename($img['image'])
            ];
            foreach ($paths as $p) {
                if (file_exists($p)) {
                    try {
                        @unlink($p);
                    } catch (Exception $e) {
                        $failed_unlinks[] = $p;
                    }
                }
            }
        }
        $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$id]);

        // Supprimer le produit
        $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
        $ok = $stmt->execute([$id]);

        if ($ok) {
            $_SESSION['success'] = "Produit supprimé avec succès.";
            // Notification non persistante (journal)
            try {
                $note = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
                $note->execute([
                    'info',
                    "Produit '{$product_name}' supprimé par " . ($_SESSION['admin_name'] ?? 'admin')
                ]);
            } catch (Exception $_) {
                // ignore logging failure
            }

            // Informer si certains fichiers n'ont pas pu être supprimés
            if (!empty($failed_unlinks)) {
                $_SESSION['success'] = "Produit supprimé. Certains fichiers n'ont pas pu être supprimés : " . implode(', ', array_slice($failed_unlinks, 0, 5)) . (count($failed_unlinks) > 5 ? '...' : '');
            }

            $pdo->commit();
        } else {
            $pdo->rollBack();
            // Notification persistante en cas d'échec de suppression
            try {
                $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
                $stmt->execute([
                    'error',
                    "Erreur lors de la suppression du produit ID $id (admin ID " . ($_SESSION['admin_id'] ?? 'inconnu') . ")"
                ]);
            } catch (Exception $_) {
                // ignore
            }
            $_SESSION['error'] = "Erreur lors de la suppression du produit.";
        }
    }
} catch (Exception $e) {
    // Rollback et journalisation
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Exception suppression produit ID $id : " . $e->getMessage()
        ]);
    } catch (Exception $_) {
        // ignore
    }
    $_SESSION['error'] = "Erreur lors de la suppression : " . $e->getMessage();
}

// Redirection vers la liste
header("Location: list_products.php");
exit;
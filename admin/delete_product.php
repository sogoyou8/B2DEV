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
    $query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $query->execute([$id]);
    $product = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Erreur BD : journaliser la notification persistante
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Erreur lecture produit ID $id : " . $e->getMessage()
        ]);
    } catch (Exception $_) {
        // ignorer erreur de journalisation
    }
    $_SESSION['error'] = "Erreur base de données.";
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

    // Supprimer les prévisions liées (FK sans ON DELETE CASCADE)
    $stmt = $pdo->prepare("DELETE FROM previsions WHERE item_id = ?");
    $stmt->execute([$id]);

    // Supprimer les entrées panier liées (si existant)
    $stmt = $pdo->prepare("DELETE FROM cart WHERE item_id = ?");
    $stmt->execute([$id]);

    // Supprimer les favoris liés
    $stmt = $pdo->prepare("DELETE FROM favorites WHERE item_id = ?");
    $stmt->execute([$id]);

    // Supprimer les images associées au produit (fichiers physiques)
    $stmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ?");
    $stmt->execute([$id]);
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($images as $image) {
        $file_path = __DIR__ . "/../assets/images/" . ($image['image'] ?? '');
        if (!empty($image['image']) && file_exists($file_path)) {
            // tenter suppression, ignorer erreur si inaccessible
            @unlink($file_path);
        }
    }

    // Supprimer les enregistrements product_images
    $stmt = $pdo->prepare("DELETE FROM product_images WHERE product_id = ?");
    $stmt->execute([$id]);

    // Supprimer le produit (doit maintenant réussir si toutes les dépendances supprimées)
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
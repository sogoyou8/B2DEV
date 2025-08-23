<?php
// Démarrer la session si nécessaire et activer le buffering pour éviter les erreurs "headers already sent"
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
// Garantit la vidange du buffer à la fin du script (même après un exit/erreur fatale)
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

// Includes
include '../includes/db.php';
include 'admin_demo_guard.php';
include 'includes/header.php';

// Récupérer et valider l'ID passé en paramètre
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID d'utilisateur invalide.";
    header("Location: list_users.php");
    exit;
}

// Vérifiez que l'utilisateur existe
try {
    $query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $query->execute([$id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Journaliser l'erreur dans notifications (persistante) sans exposer la stack à l'utilisateur
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Erreur lecture utilisateur ID $id : " . $e->getMessage()
        ]);
    } catch (Exception $_) {
        // ignorer l'échec de journalisation
    }
    $_SESSION['error'] = "Erreur base de données.";
    header("Location: list_users.php");
    exit;
}

if (!$user) {
    // Notification persistante en cas d'échec
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Échec suppression utilisateur : ID $id introuvable (admin ID " . ($_SESSION['admin_id'] ?? 'inconnu') . ")"
        ]);
    } catch (Exception $_) {
        // ignorer
    }
    $_SESSION['error'] = "Utilisateur introuvable.";
    header("Location: list_users.php");
    exit;
}

// Protection démo : empêcher la suppression si admin demo
if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
    $_SESSION['error'] = "Action désactivée en mode démo.";
    header("Location: list_users.php");
    exit;
}

try {
    // Commencer transaction pour sécurité
    $pdo->beginTransaction();

    // Supprimez les enregistrements associés dans les tables référencées
    // (adapter si d'autres tables référencent users dans ta base)
    $delFavorites = $pdo->prepare("DELETE FROM favorites WHERE user_id = ?");
    $delFavorites->execute([$id]);

    // Si vous avez une table 'cart' reliant user_id, la supprimer aussi (optionnel)
    if ($pdo->query("SHOW TABLES LIKE 'cart'")->rowCount() > 0) {
        $delCart = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $delCart->execute([$id]);
    }

    // Supprimez l'utilisateur
    $delUser = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $deleted = $delUser->execute([$id]);

    if ($deleted) {
        // Commit de la transaction
        $pdo->commit();

        // Notification non persistante (journal)
        try {
            $note = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
            $note->execute([
                'admin_action',
                "Utilisateur '" . ($user['name'] ?? ('ID '.$id)) . "' supprimé par " . ($_SESSION['admin_name'] ?? 'admin')
            ]);
        } catch (Exception $_) {
            // ignorer l'échec de journalisation
        }

        $_SESSION['success'] = "Utilisateur supprimé avec succès.";
    } else {
        // rollback et journalisation d'erreur persistante
        $pdo->rollBack();
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
            $stmt->execute([
                'error',
                "Erreur lors de la suppression de l'utilisateur ID $id (admin ID " . ($_SESSION['admin_id'] ?? 'inconnu') . ")"
            ]);
        } catch (Exception $_) {
            // ignorer
        }
        $_SESSION['error'] = "Erreur lors de la suppression de l'utilisateur.";
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Exception suppression utilisateur ID $id : " . $e->getMessage()
        ]);
    } catch (Exception $_) {
        // ignorer
    }
    $_SESSION['error'] = "Erreur lors de la suppression : " . $e->getMessage();
}

// Redirection vers la liste (buffering empêche le warning "headers already sent")
header("Location: list_users.php");
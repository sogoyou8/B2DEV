<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include '../includes/db.php';
include 'admin_demo_guard.php';

if (!guardDemoAdmin()) {
    $_SESSION['error_message'] = "Action désactivée en mode démo.";
    header("Location: notifications.php");
    exit;
}

try {
    // Marquer toutes les notifications comme lues
    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
    $success = $stmt->execute();
    
    if ($success) {
        // Compter combien ont été marquées
        $count = $stmt->rowCount();
        $_SESSION['success_message'] = "✅ $count notification(s) marquée(s) comme lue(s)";
    } else {
        $_SESSION['error_message'] = "❌ Erreur lors du marquage des notifications";
    }
} catch (Exception $e) {
    $_SESSION['error_message'] = "❌ Erreur : " . $e->getMessage();
}

header("Location: notifications.php");
exit;
?>
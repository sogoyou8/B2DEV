<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';
include 'admin_demo_guard.php';

$id = $_GET['id'];

// Vérifier que la commande existe avant suppression
$query = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
$query->execute([$id]);
$order = $query->fetch(PDO::FETCH_ASSOC);

if ($order) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: list_orders.php");
        exit;
    }
    $query = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $query->execute([$id]);
    $_SESSION['success'] = "Commande supprimée avec succès.";
} else {
    // Notification persistante en cas d'échec
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Échec suppression commande : ID $id introuvable (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Commande introuvable.";
}

header("Location: list_orders.php");
exit;
?>
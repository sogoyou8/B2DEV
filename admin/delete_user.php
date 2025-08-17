<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';
include 'includes/header.php';
include 'admin_demo_guard.php';

$id = $_GET['id'];

// Vérifiez que l'utilisateur existe
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$id]);
$user = $query->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Notification persistante en cas d'échec
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Échec suppression utilisateur : ID $id introuvable (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Utilisateur introuvable.";
    header("Location: list_users.php");
    exit;
}

// Protection démo : empêcher la suppression si admin demo
if (!guardDemoAdmin()) {
    $_SESSION['error'] = "Action désactivée en mode démo.";
    header("Location: list_users.php");
    exit;
}

// Supprimez les enregistrements associés dans les tables référencées
$query = $pdo->prepare("DELETE FROM favorites WHERE user_id = ?");
$query->execute([$id]);

// Supprimez l'utilisateur
$query = $pdo->prepare("DELETE FROM users WHERE id = ?");
if ($query->execute([$id])) {
    $_SESSION['success'] = "Utilisateur supprimé avec succès.";
} else {
    // Notification persistante en cas d'échec de suppression
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Erreur lors de la suppression de l'utilisateur ID $id (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Erreur lors de la suppression de l'utilisateur.";
}

header("Location: list_users.php");
exit;
?>
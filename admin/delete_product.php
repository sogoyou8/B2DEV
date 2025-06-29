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

$id = $_GET['id'];

// Vérifier que le produit existe
$query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$query->execute([$id]);
$product = $query->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    // Notification persistante en cas d'échec
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Échec suppression produit : ID $id introuvable (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Supprimer les enregistrements associés dans la table favorites
$query = $pdo->prepare("DELETE FROM favorites WHERE item_id = ?");
$query->execute([$id]);

// Supprimer les images associées au produit
$query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ?");
$query->execute([$id]);
$images = $query->fetchAll(PDO::FETCH_ASSOC);

foreach ($images as $image) {
    $file_path = "../assets/images/" . $image['image'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
}

$query = $pdo->prepare("DELETE FROM product_images WHERE product_id = ?");
$query->execute([$id]);

// Supprimer le produit
$query = $pdo->prepare("DELETE FROM items WHERE id = ?");
if ($query->execute([$id])) {
    $_SESSION['success'] = "Produit supprimé avec succès.";
} else {
    // Notification persistante en cas d'échec de suppression
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Erreur lors de la suppression du produit ID $id (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Erreur lors de la suppression du produit.";
}

$stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
$stmt->execute([
    'info',
    "Produit '{$product_name}' supprimé par " . $_SESSION['admin_name']
]);

header("Location: list_products.php");
exit;
?>
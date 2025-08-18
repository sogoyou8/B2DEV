<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'admin_demo_guard.php';
include '../includes/db.php';
include 'includes/header.php';

$id = $_GET['id'];
$query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$query->execute([$id]);
$product = $query->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    // Notification persistante en cas d'échec
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'error',
        "Échec modification produit : ID $id introuvable (admin ID " . $_SESSION['admin_id'] . ")"
    ]);
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Protection démo sur modification
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_product'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: edit_product.php?id=$id");
        exit;
    }
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = $_POST['category'];
    $stock_alert_threshold = $_POST['stock_alert_threshold'];

    $query = $pdo->prepare("UPDATE items SET name = ?, description = ?, price = ?, stock = ?, category = ?, stock_alert_threshold = ? WHERE id = ?");
    $query->execute([$name, $description, $price, $stock, $category, $stock_alert_threshold, $id]);

    $_SESSION['success'] = "Produit modifié avec succès.";
    header("Location: list_products.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Modifier un produit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
</head>
<body>
    <main class="container py-4">
        <section class="bg-light p-5 rounded shadow-sm">
            <h2 class="h3 mb-4 font-weight-bold">Modifier un produit</h2>
            <a href="manage_product_images.php?id=<?php echo $id; ?>" class="btn btn-outline-primary mb-3">
                <i class="bi bi-images"></i> Gérer les images du produit
            </a>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger text-center">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success text-center">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <form action="edit_product.php?id=<?php echo $id; ?>" method="post">
                <div class="mb-3">
                    <label for="name" class="form-label">Nom :</label>
                    <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($product['name']); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">Description :</label>
                    <textarea name="description" id="description" class="form-control" required><?php echo htmlspecialchars($product['description']); ?></textarea>
                </div>
                <div class="mb-3">
                    <label for="price" class="form-label">Prix :</label>
                    <input type="number" name="price" id="price" class="form-control" step="0.01" value="<?php echo htmlspecialchars($product['price']); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="stock" class="form-label">Stock :</label>
                    <input type="number" name="stock" id="stock" class="form-control" value="<?php echo htmlspecialchars($product['stock']); ?>" required>
                </div>
                <div class="mb-3">
                    <label for="category" class="form-label">Catégorie :</label>
                    <input type="text" name="category" id="category" class="form-control" value="<?php echo htmlspecialchars($product['category']); ?>">
                </div>
                <div class="mb-3">
                    <label for="stock_alert_threshold" class="form-label">Seuil d'alerte stock :</label>
                    <input type="number" name="stock_alert_threshold" id="stock_alert_threshold" class="form-control" value="<?php echo htmlspecialchars($product['stock_alert_threshold']); ?>" min="1">
                </div>
                <button type="submit" name="update_product" class="btn btn-primary">Modifier</button>
            </form>
        </section>
    </main>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php include 'includes/footer.php';
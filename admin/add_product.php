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
include 'includes/footer.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = $_POST['name'];
    $description = $_POST['description'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $category = $_POST['category'];
    $stock_alert_threshold = $_POST['stock_alert_threshold'];
    $images = $_FILES['images'];
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];

    $query = $pdo->prepare("INSERT INTO items (name, description, price, stock, category, stock_alert_threshold) VALUES (?, ?, ?, ?, ?, ?)");
    if ($query->execute([$name, $description, $price, $stock, $category, $stock_alert_threshold])) {
        $product_id = $pdo->lastInsertId();

        // Ajouter de nouvelles images
        for ($i = 0; $i < count($images['name']); $i++) {
            $image = $images['name'][$i];
            $image_type = $images['type'][$i];

            if (in_array($image_type, $allowed_types)) {
                $target = "../assets/images/" . basename($image);
                if (move_uploaded_file($images['tmp_name'][$i], $target)) {
                    $query = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
                    $query->execute([$product_id, $image, $i]);
                }
            } else {
                echo "Seuls les fichiers d'image (JPEG, PNG, GIF) sont autorisés.";
                exit;
            }
        }

        $_SESSION['success'] = "Produit ajouté avec succès.";
        header("Location: list_products.php");
        exit;
    } else {
        $_SESSION['error'] = "Erreur lors de l'ajout du produit.";
        // Notification persistante
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Erreur SQL lors de l'ajout d'un produit (admin ID " . $_SESSION['admin_id'] . ")"
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Ajouter un produit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.js"></script>
</head>
<body>
    <main class="container py-4">
        <section class="bg-light p-5 rounded shadow-sm">
            <h2 class="h3 mb-4 font-weight-bold">Ajouter un produit</h2>
            <form action="add_product.php" method="post" enctype="multipart/form-data" onsubmit="return validateImages()">
                <div class="mb-3">
                    <label for="name" class="form-label">Nom :</label>
                    <input type="text" name="name" id="name" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="description" class="form-label">Description :</label>
                    <textarea name="description" id="description" class="form-control" required></textarea>
                </div>
                <div class="mb-3">
                    <label for="price" class="form-label">Prix :</label>
                    <input type="number" name="price" id="price" class="form-control" step="0.01" required>
                </div>
                <div class="mb-3">
                    <label for="stock" class="form-label">Stock :</label>
                    <input type="number" name="stock" id="stock" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="category" class="form-label">Catégorie :</label>
                    <input type="text" name="category" id="category" class="form-control">
                </div>
                <div class="mb-3">
                    <label for="stock_alert_threshold" class="form-label">Seuil d'alerte stock :</label>
                    <input type="number" name="stock_alert_threshold" id="stock_alert_threshold" class="form-control" value="5" min="1">
                </div>
                <div class="mb-3">
                    <label for="images" class="form-label">Ajouter des images :</label>
                    <input type="file" name="images[]" id="images" class="form-control" accept="image/jpeg, image/png, image/gif" multiple>
                </div>
                <button type="submit" class="btn btn-primary">Ajouter</button>
            </form>
        </section>
    </main>
    <script>
        function validateImages() {
            const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            const images = document.getElementById('images').files;

            for (let i = 0; i < images.length; i++) {
                if (!allowedTypes.includes(images[i].type)) {
                    alert('Seuls les fichiers d\'image (JPEG, PNG, GIF) sont autorisés.');
                    return false;
                }
            }
            return true;
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
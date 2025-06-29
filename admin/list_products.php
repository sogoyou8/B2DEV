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


$query = $pdo->query("SELECT * FROM items");
$products = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
    <section>
        <h2>Liste des produits</h2>
        <a href="add_product.php" class="btn btn-primary mb-3">Ajouter un produit</a>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nom</th>
                    <th>Description</th>
                    <th>Prix</th>
                    <th>Stock</th>
                    <th>Image</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <?php
                    // Récupérer la première image du produit
                    $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                    $query->execute([$product['id']]);
                    $image = $query->fetch(PDO::FETCH_ASSOC);
                    ?>
                    <tr>
                        <td><?php echo $product['id']; ?></td>
                        <td><?php echo $product['name']; ?></td>
                        <td><?php echo $product['description']; ?></td>
                        <td><?php echo $product['price']; ?> €</td>
                        <td><?php echo $product['stock']; ?></td>
                        <td>
                            <?php if ($image): ?>
                                <img src="../assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" width="50">
                            <?php else: ?>
                                <span>Aucune image</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="edit_product.php?id=<?php echo $product['id']; ?>" class="btn btn-warning btn-sm">Modifier</a>
                            <button onclick="confirmDelete(<?php echo $product['id']; ?>)" class="btn btn-danger btn-sm">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<script>
    function confirmDelete(productId) {
        if (confirm('Êtes-vous sûr de vouloir supprimer ce produit ? Cette action est irréversible.')) {
            window.location.href = 'delete_product.php?id=' + productId;
        }
    }
</script>
<?php include 'includes/footer.php'; ?>
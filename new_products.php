<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/header.php';
include 'includes/db.php';

// Récupérez les produits ajoutés au cours des dernières 12 heures
$query = $pdo->prepare("SELECT * FROM items WHERE created_at >= NOW() - INTERVAL 12 HOUR");
$query->execute();
$new_products = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="p-4">
    <section class="new-products-section bg-gray-100 p-6 rounded-lg shadow-md">
        <h2 class="text-3xl font-bold mb-4">Nouveaux Produits</h2>
        <div class="products grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php if ($new_products): ?>
                <?php foreach ($new_products as $product): ?>
                    <?php
                    // Récupérer les images du produit
                    $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                    $query->execute([$product['id']]);
                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="product bg-white p-4 rounded-lg shadow-md">
                        <div id="carouselNewProduct<?php echo $product['id']; ?>" class="carousel slide mb-3" data-ride="carousel" data-interval="false">
                            <div class="carousel-inner">
                                <?php foreach ($images as $index => $image): ?>
                                    <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                        <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="d-block w-100" style="height: 200px; object-fit: cover;">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <a class="carousel-control-prev" href="#carouselNewProduct<?php echo $product['id']; ?>" role="button" data-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="sr-only">Previous</span>
                            </a>
                            <a class="carousel-control-next" href="#carouselNewProduct<?php echo $product['id']; ?>" role="button" data-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="sr-only">Next</span>
                            </a>
                        </div>
                        <h3 class="text-xl font-semibold mb-2"><?php echo htmlspecialchars($product['name']); ?></h3>
                        <p class="text-lg mb-2"><?php echo htmlspecialchars($product['description']); ?></p>
                        <p class="text-lg font-bold mb-4"><?php echo htmlspecialchars($product['price']); ?> €</p>
                        <a href="product_detail.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="btn btn-primary bg-blue-500 text-white py-2 px-4 rounded-md">Voir le produit</a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="text-lg">Aucun nouveau produit ajouté au cours des dernières 12 heures.</p>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
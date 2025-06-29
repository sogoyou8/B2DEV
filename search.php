<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'includes/header.php';
include 'includes/db.php';

$query = $_GET['query'];
$stmt = $pdo->prepare("SELECT * FROM items WHERE name LIKE ? OR description LIKE ?");
$stmt->execute(["%$query%", "%$query%"]);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="search-results-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Résultats de recherche pour "<?php echo htmlspecialchars($query); ?>"</h2>
        <div class="row">
            <?php if ($products): ?>
                <?php foreach ($products as $product): ?>
                    <?php
                    // Récupérer les images du produit
                    $query_img = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                    $query_img->execute([$product['id']]);
                    $images = $query_img->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <?php if ($images): ?>
                                <!-- Carousel pour les images multiples -->
                                <div id="carouselSearch<?php echo $product['id']; ?>" class="carousel slide" data-ride="carousel" data-interval="false">
                                    <div class="carousel-inner">
                                        <?php foreach ($images as $index => $image): ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" 
                                                     alt="<?php echo htmlspecialchars($product['name']); ?>" 
                                                     class="card-img-top" 
                                                     style="height: 200px; object-fit: cover;">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($images) > 1): ?>
                                        <a class="carousel-control-prev" href="#carouselSearch<?php echo $product['id']; ?>" role="button" data-slide="prev">
                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Previous</span>
                                        </a>
                                        <a class="carousel-control-next" href="#carouselSearch<?php echo $product['id']; ?>" role="button" data-slide="next">
                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Next</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <!-- Image par défaut si aucune image -->
                                <img src="assets/images/default.png" 
                                     alt="Image par défaut" 
                                     class="card-img-top" 
                                     style="height: 200px; object-fit: cover;">
                            <?php endif; ?>
                            
                            <div class="card-body">
                                <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                                <p class="card-text"><?php echo htmlspecialchars($product['description']); ?></p>
                                <p class="card-text font-weight-bold"><?php echo htmlspecialchars($product['price']); ?> €</p>
                                <p class="card-text text-muted">Stock : <?php echo htmlspecialchars($product['stock']); ?></p>
                                <a href="product_detail.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="btn btn-primary">Voir le produit</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <h4>Aucun produit trouvé</h4>
                        <p class="text-muted">Essayez avec d'autres mots-clés</p>
                        <a href="products.php" class="btn btn-primary">Voir tous les produits</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
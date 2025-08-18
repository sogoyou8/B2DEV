<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'includes/header.php';
include 'includes/db.php';

// Récupérez les produits ajoutés au cours des 2 derniers mois
$stmt = $pdo->prepare("SELECT * FROM items WHERE created_at >= NOW() - INTERVAL 2 MONTH ORDER BY created_at DESC");
$stmt->execute();
$new_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="products-section bg-light p-4 p-md-5 rounded shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h3 mb-0 font-weight-bold">Nouveaux produits</h2>
        </div>

        <?php if ($new_products && count($new_products) > 0): ?>
            <div class="row">
                <?php foreach ($new_products as $product): ?>
                    <?php
                    // Récupérer les images du produit (utilise un nom de variable distinct pour la requête)
                    $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                    $imgStmt->execute([(int)$product['id']]);
                    $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

                    // Valeurs sûres
                    $productId = (int)$product['id'];
                    $productName = htmlspecialchars($product['name']);
                    $productDesc = htmlspecialchars($product['description']);
                    $productPrice = htmlspecialchars($product['price']);
                    ?>
                    <div class="col-sm-6 col-md-4 mb-4 d-flex">
                        <div class="card w-100 h-100">
                            <div id="carouselNewProduct<?php echo $productId; ?>" class="carousel slide" data-ride="carousel" data-interval="false">
                                <div class="carousel-inner">
                                    <?php if (!empty($images)): ?>
                                        <?php foreach ($images as $index => $image): ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo $productName; ?>" class="d-block w-100" style="height:200px; object-fit:cover;">
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="carousel-item active">
                                            <img src="assets/images/default.png" alt="Image par défaut" class="d-block w-100" style="height:200px; object-fit:cover;">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($images) && count($images) > 1): ?>
                                    <a class="carousel-control-prev" href="#carouselNewProduct<?php echo $productId; ?>" role="button" data-slide="prev">
                                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                        <span class="sr-only">Previous</span>
                                    </a>
                                    <a class="carousel-control-next" href="#carouselNewProduct<?php echo $productId; ?>" role="button" data-slide="next">
                                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                        <span class="sr-only">Next</span>
                                    </a>
                                <?php endif; ?>
                            </div>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title mb-2"><?php echo $productName; ?></h5>
                                <p class="card-text text-muted mb-2" style="flex:0 0 auto;"><?php echo $productDesc ?: '<span class="text-muted">Aucune description</span>'; ?></p>
                                <div class="mt-auto d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="h5 mb-0"><?php echo $productPrice; ?> €</span>
                                        <?php if (isset($product['stock'])): ?>
                                            <small class="d-block text-muted">Stock : <?php echo (int)$product['stock']; ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <a href="product_detail.php?id=<?php echo $productId; ?>" class="btn btn-primary btn-sm">Voir le produit</a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Aucun nouveau produit ajouté au cours des 2 derniers mois.</div>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>

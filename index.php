<?php
include 'includes/header.php';
include 'includes/db.php';

// Récupérez les produits les plus récents
$query = $pdo->query("SELECT * FROM items ORDER BY created_at DESC LIMIT 4");
$products = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main>
    <section class="banner text-center py-5 bg-light">
        <h1 class="display-4">Bienvenue sur notre site e-commerce</h1>
        <p class="lead">Découvrez nos produits de qualité.</p>
    </section>
    <section class="featured-products py-5">
        <div class="container">
            <h2 class="text-center mb-4">Produits en avant</h2>
            <div id="carouselExampleIndicators" class="carousel slide mx-auto" data-ride="carousel" style="max-width: 800px;">
                <ol class="carousel-indicators">
                    <?php foreach ($products as $index => $product): ?>
                        <li data-target="#carouselExampleIndicators" data-slide-to="<?php echo $index; ?>" class="<?php echo $index === 0 ? 'active' : ''; ?>"></li>
                    <?php endforeach; ?>
                </ol>
                <div class="carousel-inner">
                    <?php foreach ($products as $index => $product): ?>
                        <?php
                        // Récupérer la première image du produit
                        $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                        $query->execute([$product['id']]);
                        $image = $query->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <?php if ($image): ?>
                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" class="d-block w-100 product-img" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                                <img src="assets/images/default.png" class="d-block w-100 product-img" alt="Image par défaut">
                            <?php endif; ?>
                            <div class="carousel-caption d-none d-md-block bg-dark bg-opacity-50 p-3 rounded">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p><?php echo htmlspecialchars($product['description']); ?></p>
                                <p><?php echo htmlspecialchars($product['price']); ?> €</p>
                                <a href="product_detail.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="btn btn-primary">Voir le produit</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a class="carousel-control-prev" href="#carouselExampleIndicators" role="button" data-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="sr-only">Previous</span>
                </a>
                <a class="carousel-control-next" href="#carouselExampleIndicators" role="button" data-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="sr-only">Next</span>
                </a>
            </div>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
<?php
include 'includes/header.php';
include 'includes/db.php';

$query = $pdo->query("SELECT * FROM items");
$products = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="products-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Articles</h2>
        <div class="row">
            <?php foreach ($products as $product): ?>
                <?php
                // Récupérer les images du produit
                $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                $query->execute([$product['id']]);
                $images = $query->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="col-md-4 mb-4">
                    <div class="card h-100">
                        <div id="carouselProduct<?php echo $product['id']; ?>" class="carousel slide" data-ride="carousel" data-interval="false">
                            <div class="carousel-inner">
                                <?php foreach ($images as $index => $image): ?>
                                    <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                        <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="d-block w-100" style="height: 200px; object-fit: cover;">
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <a class="carousel-control-prev" href="#carouselProduct<?php echo $product['id']; ?>" role="button" data-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="sr-only">Previous</span>
                            </a>
                            <a class="carousel-control-next" href="#carouselProduct<?php echo $product['id']; ?>" role="button" data-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="sr-only">Next</span>
                            </a>
                        </div>
                        <div class="card-body">
                            <h5 class="card-title"><?php echo htmlspecialchars($product['name']); ?></h5>
                            <p class="card-text"><?php echo htmlspecialchars($product['description']); ?></p>
                            <p class="card-text font-weight-bold"><?php echo htmlspecialchars($product['price']); ?> €</p>
                            <a href="product_detail.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="btn btn-primary">Voir le produit</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
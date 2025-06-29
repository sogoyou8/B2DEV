<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/header.php';
include 'includes/db.php';

$id = $_GET['id'];
$query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$query->execute([$id]);
$product = $query->fetch(PDO::FETCH_ASSOC);

$query = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY position");
$query->execute([$id]);
$product_images = $query->fetchAll(PDO::FETCH_ASSOC);

// Vérifiez si l'utilisateur est connecté
$is_favorite = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $query = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND item_id = ?");
    $query->execute([$user_id, $id]);
    $is_favorite = $query->fetchColumn() > 0;
} else {
    // Vérifiez si l'article est dans les favoris temporaires
    if (isset($_SESSION['temp_favorites']) && in_array($id, $_SESSION['temp_favorites'])) {
        $is_favorite = true;
    }
}
?>
<main class="container py-4">
    <section class="product-detail-section bg-light p-4 rounded shadow-sm mx-auto" style="max-width: 800px;">
        <h2 class="h4 mb-3 font-weight-bold"><?php echo htmlspecialchars($product['name']); ?></h2>
        <div class="row">
            <div class="col-md-8">
                <div id="productCarousel" class="carousel slide mb-3" data-ride="carousel">
                    <div class="carousel-inner">
                        <?php foreach ($product_images as $index => $image): ?>
                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="d-block w-100" style="max-height: 400px; object-fit: cover;">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <a class="carousel-control-prev" href="#productCarousel" role="button" data-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="sr-only">Previous</span>
                    </a>
                    <a class="carousel-control-next" href="#productCarousel" role="button" data-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="sr-only">Next</span>
                    </a>
                </div>
            </div>
            <div class="col-md-4">
                <div class="thumbnail-container" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach ($product_images as $index => $image): ?>
                        <div class="mb-2">
                            <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="img-thumbnail thumbnail-img" style="cursor: pointer;" data-target="#productCarousel" data-slide-to="<?php echo $index; ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <p class="text-muted mb-3"><?php echo htmlspecialchars($product['description']); ?></p>
        <p class="h5 font-weight-bold mb-2">Prix : <?php echo htmlspecialchars($product['price']); ?> €</p>
        <p class="text-muted mb-3">Stock : <?php echo htmlspecialchars($product['stock']); ?></p>
        <form action="cart.php" method="post" class="mb-3">
            <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
            <div class="form-group">
                <label for="quantity" class="form-label">Quantité :</label>
                <input type="number" name="quantity" id="quantity" min="1" max="<?php echo $product['stock']; ?>" required class="form-control" style="max-width: 100px;">
            </div>
            <button type="submit" name="add" class="btn btn-primary btn-block">Ajouter au panier</button>
        </form>
        <form action="favorites.php" method="post">
            <input type="hidden" name="id" value="<?php echo $product['id']; ?>">
            <?php if ($is_favorite): ?>
                <button type="submit" name="remove" class="btn btn-danger btn-block">Retirer des favoris</button>
            <?php else: ?>
                <button type="submit" name="add" class="btn btn-secondary btn-block">Ajouter aux favoris</button>
            <?php endif; ?>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
<style>
    .thumbnail-img {
        max-height: 80px;
        object-fit: cover;
    }
</style>
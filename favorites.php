<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/header.php';
include 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $item_id = $_POST['id'];

    // Vérifiez si l'utilisateur est connecté
    if (!isset($_SESSION['user_id'])) {
        // Stockez l'article dans une session temporaire
        if (!isset($_SESSION['temp_favorites'])) {
            $_SESSION['temp_favorites'] = [];
        }
        if (!in_array($item_id, $_SESSION['temp_favorites'])) {
            $_SESSION['temp_favorites'][] = $item_id;
        }
        header("Location: login.php");
        exit;
    } else {
        $user_id = $_SESSION['user_id'];
        if (isset($_POST['remove'])) {
            $query = $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND item_id = ?");
            $query->execute([$user_id, $item_id]);
        } else {
            $query = $pdo->prepare("INSERT INTO favorites (user_id, item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE item_id = item_id");
            $query->execute([$user_id, $item_id]);
        }
    }
    // Redirigez vers la même page pour éviter la duplication des données POST
    header("Location: favorites.php");
    exit;
}

$items = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $query = $pdo->prepare("SELECT items.* FROM items JOIN favorites ON items.id = favorites.item_id WHERE favorites.user_id = ?");
    $query->execute([$user_id]);
    $items = $query->fetchAll(PDO::FETCH_ASSOC);
} elseif (isset($_SESSION['temp_favorites'])) {
    $temp_favorites = $_SESSION['temp_favorites'];
    if (!empty($temp_favorites)) {
        $placeholders = implode(',', array_fill(0, count($temp_favorites), '?'));
        $query = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
        $query->execute($temp_favorites);
        $items = $query->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<main class="container py-4">
    <section class="favorites-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Favoris</h2>
        <?php if ($items): ?>
            <div class="row">
                <?php foreach ($items as $item): ?>
                    <?php
                    // Récupérer les images du produit
                    $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                    $query->execute([$item['id']]);
                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100">
                            <div id="carouselFavorite<?php echo $item['id']; ?>" class="carousel slide mb-3" data-ride="carousel" data-interval="false">
                                <div class="carousel-inner">
                                    <?php foreach ($images as $index => $image): ?>
                                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                            <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="d-block w-100" style="height: 200px; object-fit: cover;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <a class="carousel-control-prev" href="#carouselFavorite<?php echo $item['id']; ?>" role="button" data-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Previous</span>
                                </a>
                                <a class="carousel-control-next" href="#carouselFavorite<?php echo $item['id']; ?>" role="button" data-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Next</span>
                                </a>
                            </div>
                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title"><?php echo htmlspecialchars($item['name']); ?></h5>
                                <p class="card-text">Prix : <?php echo htmlspecialchars($item['price']); ?> €</p>
                                <form action="favorites.php" method="post" class="mt-auto">
                                    <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="remove" class="btn btn-danger btn-block mb-2">Retirer des favoris</button>
                                </form>
                                <a href="product_detail.php?id=<?php echo $item['id']; ?>" class="btn btn-primary btn-block">Voir le produit</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-center">Vous n'avez aucun favori.</p>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/header.php';
include 'includes/db.php';

// Vérifiez si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['remove'])) {
        $item_id = $_POST['id'];
        $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND item_id = ?");
        $query->execute([$user_id, $item_id]);
    } elseif (isset($_POST['clear'])) {
        $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $query->execute([$user_id]);
    } elseif (isset($_POST['update'])) {
        $item_id = $_POST['id'];
        $quantity = $_POST['quantity'];
        $query = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND item_id = ?");
        $query->execute([$quantity, $user_id, $item_id]);
    } elseif (isset($_POST['add'])) {
        $item_id = $_POST['id'];
        $quantity = $_POST['quantity'];
        $query = $pdo->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
        $query->execute([$user_id, $item_id, $quantity]);
    }
    // Redirigez vers la même page pour éviter la duplication des données POST
    header("Location: cart.php");
    exit;
}

$query = $pdo->prepare("SELECT items.*, cart.quantity FROM items JOIN cart ON items.id = cart.item_id WHERE cart.user_id = ?");
$query->execute([$user_id]);
$items = $query->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
foreach ($items as $item) {
    $total += $item['price'] * $item['quantity'];
}
?>
<main class="container py-4">
    <section class="cart-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Panier</h2>
        <?php if ($items): ?>
            <ul class="list-group mb-4">
                <?php foreach ($items as $item): ?>
                    <?php
                    // Récupérer les images du produit
                    $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                    $query->execute([$item['id']]);
                    $images = $query->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center">
                            <div id="carouselCart<?php echo $item['id']; ?>" class="carousel slide mr-3" data-ride="carousel" data-interval="false" style="width: 100px;">
                                <div class="carousel-inner">
                                    <?php foreach ($images as $index => $image): ?>
                                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                            <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="d-block w-100" style="height: 100px; object-fit: cover;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <a class="carousel-control-prev" href="#carouselCart<?php echo $item['id']; ?>" role="button" data-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Previous</span>
                                </a>
                                <a class="carousel-control-next" href="#carouselCart<?php echo $item['id']; ?>" role="button" data-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Next</span>
                                </a>
                            </div>
                            <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($item['name']); ?></h5>
                                <p class="mb-1">Quantité : <?php echo htmlspecialchars($item['quantity']); ?></p>
                                <p class="mb-1">Prix : <?php echo htmlspecialchars($item['price']); ?> €</p>
                                <p class="mb-1">Total : <?php echo htmlspecialchars($item['price'] * $item['quantity']); ?> €</p>
                            </div>
                        </div>
                        <div>
                            <form action="cart.php" method="post" class="d-inline-block">
                                <input type="hidden" name="id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                <div class="form-group mb-2">
                                    <label for="quantity" class="sr-only">Quantité :</label>
                                    <input type="number" name="quantity" id="quantity" min="1" max="<?php echo htmlspecialchars($item['stock']); ?>" value="<?php echo htmlspecialchars($item['quantity']); ?>" required class="form-control">
                                </div>
                                <button type="submit" name="update" class="btn btn-primary btn-sm">Mettre à jour</button>
                                <button type="submit" name="remove" class="btn btn-danger btn-sm">Supprimer</button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="cart-summary mb-4">
                <p class="h4">Total du panier : <?php echo htmlspecialchars($total); ?> €</p>
            </div>
            <form action="cart.php" method="post" class="mb-2">
                <button type="submit" name="clear" class="btn btn-danger">Vider le panier</button>
            </form>
            <form action="checkout.php" method="post">
                <button type="submit" class="btn btn-primary">Passer à la caisse</button>
            </form>
        <?php else: ?>
            <p class="text-center">Votre panier est vide.</p>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
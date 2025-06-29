<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/header.php';
include 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Récupérez les détails de la dernière commande
$query = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 1");
$query->execute([$user_id]);
$order = $query->fetch(PDO::FETCH_ASSOC);

$query = $pdo->prepare("SELECT items.*, order_details.quantity, order_details.price FROM order_details JOIN items ON order_details.item_id = items.id WHERE order_details.order_id = ?");
$query->execute([$order['id']]);
$order_items = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="confirmation-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Confirmation de commande</h2>
        <p class="mb-4">Merci pour votre achat ! Votre commande a été passée avec succès.</p>
        <p class="mb-4">Vous recevrez un email de confirmation sous peu.</p>
        <h3 class="h4 mb-4 font-weight-bold">Détails de la commande</h3>
        <ul class="list-group mb-4">
            <?php foreach ($order_items as $item): ?>
                <?php
                // Récupérer les images du produit
                $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                $query->execute([$item['id']]);
                $images = $query->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <li class="list-group-item d-flex align-items-center">
                    <div id="carouselOrderItem<?php echo $item['id']; ?>" class="carousel slide mr-3" data-ride="carousel" data-interval="false" style="width: 100px;">
                        <div class="carousel-inner">
                            <?php foreach ($images as $index => $image): ?>
                                <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="d-block w-100" style="height: 100px; object-fit: cover;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a class="carousel-control-prev" href="#carouselOrderItem<?php echo $item['id']; ?>" role="button" data-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="sr-only">Previous</span>
                        </a>
                        <a class="carousel-control-next" href="#carouselOrderItem<?php echo $item['id']; ?>" role="button" data-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="sr-only">Next</span>
                        </a>
                    </div>
                    <div class="ml-3">
                        <h5 class="mb-1"><?php echo htmlspecialchars($item['name']); ?></h5>
                        <p class="mb-1"><strong>Quantité :</strong> <?php echo htmlspecialchars($item['quantity']); ?></p>
                        <p><strong>Prix :</strong> <?php echo htmlspecialchars($item['price']); ?> €</p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="text-xl font-semibold mb-4">Total payé : <?php echo htmlspecialchars($order['total_price']); ?> €</p>
        <a href="index.php" class="btn btn-primary">Retour à l'accueil</a>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
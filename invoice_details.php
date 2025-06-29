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

$invoice_id = $_GET['id'];

// Récupérez les détails de la facture
$query = $pdo->prepare("SELECT invoice.*, orders.total_price FROM invoice JOIN orders ON invoice.order_id = orders.id WHERE invoice.id = ?");
$query->execute([$invoice_id]);
$invoice = $query->fetch(PDO::FETCH_ASSOC);

// Récupérez les détails des produits pour la commande associée
$query = $pdo->prepare("SELECT items.*, order_details.quantity, order_details.price FROM order_details JOIN items ON order_details.item_id = items.id WHERE order_details.order_id = ?");
$query->execute([$invoice['order_id']]);
$order_items = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="invoice-details-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Détails de la Facture #<?php echo htmlspecialchars($invoice['id']); ?></h2>
        <p class="mb-4"><strong>Date de la transaction :</strong> <?php echo htmlspecialchars($invoice['transaction_date']); ?></p>
        <p class="mb-4"><strong>Montant total :</strong> <?php echo htmlspecialchars($invoice['amount']); ?> €</p>
        <p class="mb-4"><strong>Adresse de facturation :</strong> <?php echo htmlspecialchars($invoice['billing_address']); ?></p>
        <p class="mb-4"><strong>Ville :</strong> <?php echo htmlspecialchars($invoice['city']); ?></p>
        <p class="mb-4"><strong>Code postal :</strong> <?php echo htmlspecialchars($invoice['postal_code']); ?></p>
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
                    <div id="carouselInvoiceItem<?php echo $item['id']; ?>" class="carousel slide mr-3" data-ride="carousel" data-interval="false" style="width: 100px;">
                        <div class="carousel-inner">
                            <?php foreach ($images as $index => $image): ?>
                                <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="d-block w-100" style="height: 100px; object-fit: cover;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a class="carousel-control-prev" href="#carouselInvoiceItem<?php echo $item['id']; ?>" role="button" data-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="sr-only">Previous</span>
                        </a>
                        <a class="carousel-control-next" href="#carouselInvoiceItem<?php echo $item['id']; ?>" role="button" data-slide="next">
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
        <a href="orders_invoices.php" class="btn btn-primary">Retour aux commandes et factures</a>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
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

// Récupérez les commandes de l'utilisateur
$query = $pdo->prepare("SELECT * FROM orders WHERE user_id = ?");
$query->execute([$user_id]);
$orders = $query->fetchAll(PDO::FETCH_ASSOC);

// Récupérez les factures de l'utilisateur
$query = $pdo->prepare("SELECT invoice.*, orders.total_price FROM invoice JOIN orders ON invoice.order_id = orders.id WHERE orders.user_id = ?");
$query->execute([$user_id]);
$invoices = $query->fetchAll(PDO::FETCH_ASSOC);

// Créez un tableau associatif pour les factures
$invoices_by_order_id = [];
foreach ($invoices as $invoice) {
    $invoices_by_order_id[$invoice['order_id']] = $invoice;
}

// Récupérez les détails des produits pour chaque commande
$order_items = [];
foreach ($orders as $order) {
    $query = $pdo->prepare("SELECT items.*, order_details.quantity FROM order_details JOIN items ON order_details.item_id = items.id WHERE order_details.order_id = ?");
    $query->execute([$order['id']]);
    $order_items[$order['id']] = $query->fetchAll(PDO::FETCH_ASSOC);
}
?>
<main class="container py-4">
    <section class="orders-invoices-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Mes Commandes et Factures</h2>
        <?php if ($orders): ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="thead-dark">
                        <tr>
                            <th>ID Commande</th>
                            <th>Date Commande</th>
                            <th>Montant Total</th>
                            <th>Status</th>
                            <th>Produits</th>
                            <th>ID Facture</th>
                            <th>Date Facture</th>
                            <th>Montant Facture</th>
                            <th>Adresse de Facturation</th>
                            <th>Ville</th>
                            <th>Code Postal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td>
                                    <a href="order_details.php?id=<?php echo htmlspecialchars($order['id']); ?>" class="btn btn-primary btn-sm">Voir Commande</a>
                                </td>
                                <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                                <td><?php echo htmlspecialchars($order['total_price']); ?> €</td>
                                <td><?php echo htmlspecialchars($order['status']); ?></td>
                                <td>
                                    <?php foreach ($order_items[$order['id']] as $item): ?>
                                        <?php
                                        // Récupérer les images du produit
                                        $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                                        $query->execute([$item['id']]);
                                        $images = $query->fetchAll(PDO::FETCH_ASSOC);
                                        ?>
                                        <div class="d-flex align-items-center mb-2">
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
                                            <span class="ml-2"><?php echo htmlspecialchars($item['name']); ?> (x<?php echo htmlspecialchars($item['quantity']); ?>)</span>
                                        </div>
                                    <?php endforeach; ?>
                                </td>
                                <?php if (isset($invoices_by_order_id[$order['id']])): ?>
                                    <?php $invoice = $invoices_by_order_id[$order['id']]; ?>
                                    <td>
                                        <a href="invoice_details.php?id=<?php echo htmlspecialchars($invoice['id']); ?>" class="btn btn-secondary btn-sm">Voir Facture</a>
                                    </td>
                                    <td><?php echo htmlspecialchars($invoice['transaction_date']); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['amount']); ?> €</td>
                                    <td><?php echo htmlspecialchars($invoice['billing_address']); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['city']); ?></td>
                                    <td><?php echo htmlspecialchars($invoice['postal_code']); ?></td>
                                <?php else: ?>
                                    <td colspan="6" class="text-center">Pas de facture</td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-lg">Vous n'avez aucune commande.</p>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
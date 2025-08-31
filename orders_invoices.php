<?php
// filepath: c:\xampp\htdocs\B2DEV\B2DEV\orders_invoices.php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/header.php';
include 'includes/db.php';

// Add orders_invoices-specific stylesheet
echo '<link rel="stylesheet" href="assets/css/user/orders_invoices.css">' ;

$user_id = $_SESSION['user_id'] ?? 0;

// Récupérez les commandes de l'utilisateur
try {
    $query = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
    $query->execute([$user_id]);
    $orders = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $orders = [];
}

// Récupérez les factures de l'utilisateur
try {
    $query = $pdo->prepare("SELECT invoice.*, orders.total_price, invoice.order_id FROM invoice JOIN orders ON invoice.order_id = orders.id WHERE orders.user_id = ?");
    $query->execute([$user_id]);
    $invoices = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $invoices = [];
}

// Créez un tableau associatif pour les factures
$invoices_by_order_id = [];
foreach ($invoices as $invoice) {
    $invoices_by_order_id[$invoice['order_id']] = $invoice;
}

// Récupérez les détails des produits pour chaque commande
$order_items = [];
if (!empty($orders)) {
    foreach ($orders as $order) {
        try {
            $q = $pdo->prepare("SELECT items.*, order_details.quantity FROM order_details JOIN items ON order_details.item_id = items.id WHERE order_details.order_id = ?");
            $q->execute([$order['id']]);
            $order_items[$order['id']] = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $order_items[$order['id']] = [];
        }
    }
}

/**
 * Helper pour formatter les badges de statut
 */
function getStatusBadge($status) {
    $classes = [
        'delivered' => 'success',
        'pending' => 'warning', 
        'cancelled' => 'danger',
        'processing' => 'info'
    ];
    $class = $classes[$status] ?? 'secondary';
    $label = ucfirst($status);
    return "<span class=\"badge badge-{$class}\">{$label}</span>";
}

/**
 * Helper pour formatter les dates
 */
function formatDate($dateString) {
    if (!$dateString) return '—';
    try {
        return (new DateTime($dateString))->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $dateString;
    }
}
?>

<main class="container py-4">
    <section class="orders-invoices-section">
        <div class="page-header">
            <h2 class="page-title">Mes Commandes et Factures</h2>
            <p class="page-subtitle">Consultez l'historique de vos commandes et téléchargez vos factures</p>
        </div>

        <?php if (!empty($orders)): ?>
            <div class="orders-cards-layout">
                <?php foreach ($orders as $order): ?>
                    <div class="order-card" data-order-id="<?php echo (int)$order['id']; ?>">
                        <!-- Header de la commande -->
                        <div class="order-header">
                            <div class="order-info-main">
                                <h3 class="order-title">Commande #<?php echo htmlspecialchars($order['id']); ?></h3>
                                <div class="order-meta">
                                    <span class="order-date"><?php echo formatDate($order['order_date']); ?></span>
                                    <?php echo getStatusBadge($order['status']); ?>
                                </div>
                            </div>
                            <div class="order-amount">
                                <div class="amount-value"><?php echo htmlspecialchars(number_format((float)$order['total_price'], 2, ',', ' ')); ?> €</div>
                                <div class="amount-label">Total</div>
                            </div>
                        </div>

                        <!-- Corps principal -->
                        <div class="order-content">
                            <!-- Section produits -->
                            <div class="order-section products-section">
                                <h4 class="section-title">
                                    <i class="bi bi-box-seam"></i>
                                    Produits commandés
                                </h4>
                                <div class="products-showcase">
                                    <?php
                                    $itemsList = $order_items[$order['id']] ?? [];
                                    if (empty($itemsList)) {
                                        echo '<div class="no-products">Aucun produit trouvé</div>';
                                    } else {
                                        foreach ($itemsList as $item):
                                            // Récupérer la première image du produit
                                            try {
                                                $q = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                                                $q->execute([$item['id']]);
                                                $image = $q->fetch(PDO::FETCH_ASSOC);
                                            } catch (Exception $e) {
                                                $image = null;
                                            }
                                    ?>
                                        <div class="product-mini-card">
                                            <div class="product-image-container">
                                                <?php if ($image): ?>
                                                    <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" 
                                                         alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                                         class="product-mini-image">
                                                <?php else: ?>
                                                    <div class="product-mini-image placeholder">
                                                        <i class="bi bi-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="quantity-badge">×<?php echo htmlspecialchars($item['quantity']); ?></div>
                                            </div>
                                            <div class="product-info">
                                                <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                <div class="product-price"><?php echo htmlspecialchars(number_format((float)$item['price'] * (int)$item['quantity'], 2, ',', ' ')); ?> €</div>
                                            </div>
                                        </div>
                                    <?php 
                                        endforeach;
                                    }
                                    ?>
                                </div>
                            </div>

                            <!-- Section facture -->
                            <div class="order-section invoice-section">
                                <?php if (isset($invoices_by_order_id[$order['id']])): ?>
                                    <?php $invoice = $invoices_by_order_id[$order['id']]; ?>
                                    <h4 class="section-title">
                                        <i class="bi bi-receipt"></i>
                                        Informations de facturation
                                    </h4>
                                    <div class="invoice-details">
                                        <div class="invoice-row">
                                            <span class="label">Date de facturation :</span>
                                            <span class="value"><?php echo formatDate($invoice['transaction_date'] ?? null); ?></span>
                                        </div>
                                        <div class="invoice-row">
                                            <span class="label">Montant facturé :</span>
                                            <span class="value amount"><?php echo htmlspecialchars(number_format((float)($invoice['amount'] ?? 0), 2, ',', ' ')); ?> €</span>
                                        </div>
                                        <?php if (!empty($invoice['billing_address']) || !empty($invoice['city'])): ?>
                                            <div class="invoice-row">
                                                <span class="label">Adresse :</span>
                                                <span class="value address">
                                                    <?php if (!empty($invoice['billing_address'])): ?>
                                                        <?php echo htmlspecialchars($invoice['billing_address']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if (!empty($invoice['postal_code']) || !empty($invoice['city'])): ?>
                                                        <?php echo htmlspecialchars(($invoice['postal_code'] ?? '') . ' ' . ($invoice['city'] ?? '')); ?>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <h4 class="section-title">
                                        <i class="bi bi-receipt"></i>
                                        Facturation
                                    </h4>
                                    <div class="no-invoice">
                                        <i class="bi bi-exclamation-circle"></i>
                                        Aucune facture générée pour cette commande
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="order-actions">
                            <a href="order_details.php?id=<?php echo htmlspecialchars($order['id']); ?>" 
                               class="btn btn-primary">
                                <i class="bi bi-eye"></i>
                                Voir les détails
                            </a>
                            <?php if (isset($invoices_by_order_id[$order['id']])): ?>
                                <a href="invoice_details.php?id=<?php echo htmlspecialchars($invoices_by_order_id[$order['id']]['id']); ?>" 
                                   class="btn btn-outline-secondary">
                                    <i class="bi bi-file-earmark-pdf"></i>
                                    Voir la facture
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="bi bi-bag-x"></i>
                </div>
                <h3>Aucune commande</h3>
                <p>Vous n'avez pas encore passé de commande.</p>
                <a href="products.php" class="btn btn-primary">
                    <i class="bi bi-shop"></i>
                    Découvrir nos produits
                </a>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
// Interactions légères pour améliorer l'UX
(function() {
    // Animation d'entrée progressive des cartes
    const cards = document.querySelectorAll('.order-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('animate-in');
    });

    // Copie du numéro de commande au clic
    document.querySelectorAll('.order-title').forEach(title => {
        title.addEventListener('click', function(e) {
            const orderNum = this.textContent.replace('Commande #', '');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(orderNum).then(() => {
                    showToast('Numéro de commande copié !');
                });
            }
        });
        title.style.cursor = 'pointer';
        title.title = 'Cliquer pour copier le numéro';
    });

    // Mini toast pour les notifications
    function showToast(message) {
        const toast = document.createElement('div');
        toast.className = 'toast-notification';
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #28a745;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            z-index: 10000;
            font-size: 14px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideInRight 0.3s ease-out;
        `;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease-in forwards';
            setTimeout(() => toast.remove(), 300);
        }, 2000);
    }

    // Ajout des animations CSS
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        .animate-in {
            animation: fadeInUp 0.6s ease-out both;
        }
        @keyframes fadeInUp {
            from { transform: translateY(30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    `;
    document.head.appendChild(style);
})();
</script>

<?php include 'includes/footer.php'; ?>
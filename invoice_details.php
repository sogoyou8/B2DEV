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

// Add orders_invoices-specific stylesheet for perfect consistency
echo '<link rel="stylesheet" href="assets/css/user/invoice_details.css">';

$invoice_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;

$invoice = false;
$order_items = [];
$related_invoices = [];
$error = '';

if ($invoice_id > 0) {
    try {
        // Verify invoice belongs to current user's orders
        $stmt = $pdo->prepare(
            "SELECT invoice.*, orders.total_price, orders.order_date, orders.status, orders.user_id 
             FROM invoice 
             JOIN orders ON invoice.order_id = orders.id 
             WHERE invoice.id = ? AND orders.user_id = ? 
             LIMIT 1"
        );
        $stmt->execute([$invoice_id, $_SESSION['user_id']]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($invoice) {
            // Get order items
            $detailsStmt = $pdo->prepare(
                "SELECT items.*, order_details.quantity, order_details.price 
                 FROM order_details 
                 JOIN items ON order_details.item_id = items.id 
                 WHERE order_details.order_id = ?"
            );
            $detailsStmt->execute([$invoice['order_id']]);
            $order_items = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);

            // Get related invoices (same billing address, excluding current)
            if (!empty($invoice['billing_address']) || !empty($invoice['city']) || !empty($invoice['postal_code'])) {
                try {
                    $relatedStmt = $pdo->prepare(
                        "SELECT invoice.id AS invoice_id, invoice.transaction_date, invoice.amount,
                                invoice.billing_address, invoice.city, invoice.postal_code,
                                o.id AS order_id, o.total_price, o.order_date, o.status
                         FROM invoice
                         JOIN orders o ON invoice.order_id = o.id
                         WHERE invoice.id != ?
                           AND o.user_id = ?
                           AND (
                              (invoice.billing_address <> '' AND invoice.billing_address = ?)
                              OR (invoice.city <> '' AND invoice.city = ?)
                              OR (invoice.postal_code <> '' AND invoice.postal_code = ?)
                           )
                         ORDER BY invoice.transaction_date DESC
                         LIMIT 8"
                    );
                    $relatedStmt->execute([
                        $invoice_id,
                        $_SESSION['user_id'],
                        $invoice['billing_address'],
                        $invoice['city'],
                        $invoice['postal_code']
                    ]);
                    $related_invoices = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $related_invoices = [];
                }
            }
        } else {
            $error = "Facture introuvable ou non autorisée.";
        }
    } catch (Exception $e) {
        $error = "Erreur lors de la récupération : " . $e->getMessage();
    }
} else {
    $error = "Identifiant de facture invalide.";
}

/**
 * Helper functions (identical to order_details)
 */
function getStatusBadge($status) {
    $classes = [
        'delivered' => 'success',
        'pending' => 'warning',
        'cancelled' => 'danger',
        'processing' => 'info',
        'shipped' => 'info'
    ];
    $class = $classes[$status] ?? 'secondary';
    $label = ucfirst($status);
    return "<span class=\"badge badge-{$class}\">{$label}</span>";
}

function formatDate($dateString) {
    if (!$dateString) return '—';
    try {
        return (new DateTime($dateString))->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $dateString;
    }
}

function formatPrice($p) {
    return number_format((float)$p, 2, ',', ' ') . ' €';
}
?>

<main class="container py-4">
    <section class="orders-invoices-section">
        <?php if ($error): ?>
            <div class="empty-state">
                <div class="empty-icon">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                </div>
                <h3>Facture introuvable</h3>
                <p><?php echo htmlspecialchars($error); ?></p>
                <a href="orders_invoices.php" class="btn btn-primary">
                    <i class="bi bi-arrow-left"></i>
                    Retour aux commandes
                </a>
            </div>
        <?php else: ?>
            <div class="page-header">
                <h2 class="page-title">Facture #<?php echo htmlspecialchars($invoice['id']); ?></h2>
                <p class="page-subtitle">Détails de votre facture et des produits facturés</p>
            </div>

            <div class="orders-cards-layout">
                <div class="order-card" data-invoice-id="<?php echo (int)$invoice['id']; ?>">
                    <!-- Header de la facture -->
                    <div class="order-header">
                        <div class="order-info-main">
                            <h3 class="order-title">Facture #<?php echo htmlspecialchars($invoice['id']); ?></h3>
                            <div class="order-meta">
                                <span class="order-date"><?php echo formatDate($invoice['transaction_date']); ?></span>
                                <span class="invoice-order-link">
                                    <i class="bi bi-link-45deg"></i>
                                    Commande #<?php echo htmlspecialchars($invoice['order_id']); ?>
                                </span>
                                <?php echo getStatusBadge($invoice['status']); ?>
                            </div>
                        </div>
                        <div class="order-amount">
                            <div class="amount-value"><?php echo formatPrice($invoice['amount']); ?></div>
                            <div class="amount-label">Montant facturé</div>
                        </div>
                    </div>

                    <!-- Corps principal -->
                    <div class="order-content">
                        <!-- Section produits détaillée -->
                        <div class="order-section products-section">
                            <h4 class="section-title">
                                <i class="bi bi-box-seam"></i>
                                Produits facturés
                            </h4>

                            <?php if (!empty($order_items)): ?>
                                <div class="products-detailed-showcase">
                                    <?php
                                    $imgQ = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 4");
                                    foreach ($order_items as $item):
                                        $images = [];
                                        try {
                                            $imgQ->execute([$item['id']]);
                                            $images = $imgQ->fetchAll(PDO::FETCH_ASSOC);
                                        } catch (Exception $e) {
                                            $images = [];
                                        }
                                        $subtotal = (float)$item['price'] * (int)$item['quantity'];
                                    ?>
                                        <div class="product-detailed-card">
                                            <div class="product-image-container">
                                                <?php if (!empty($images)): ?>
                                                    <div class="product-image-gallery">
                                                        <?php foreach ($images as $index => $image): ?>
                                                            <div class="gallery-image <?php echo $index === 0 ? 'active' : ''; ?>">
                                                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>"
                                                                     alt="<?php echo htmlspecialchars($item['name']); ?>"
                                                                     class="product-detail-image">
                                                            </div>
                                                        <?php endforeach; ?>

                                                        <?php if (count($images) > 1): ?>
                                                            <div class="gallery-navigation">
                                                                <button class="gallery-nav prev" onclick="changeImage(this.closest('.product-detailed-card'), -1)">
                                                                    <i class="bi bi-chevron-left"></i>
                                                                </button>
                                                                <button class="gallery-nav next" onclick="changeImage(this.closest('.product-detailed-card'), 1)">
                                                                    <i class="bi bi-chevron-right"></i>
                                                                </button>
                                                            </div>

                                                            <div class="gallery-dots">
                                                                <?php for ($i = 0; $i < count($images); $i++): ?>
                                                                    <button class="gallery-dot <?php echo $i === 0 ? 'active' : ''; ?>"
                                                                            onclick="setActiveImage(this.closest('.product-detailed-card'), <?php echo $i; ?>)"></button>
                                                                <?php endfor; ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="product-detail-image placeholder">
                                                        <i class="bi bi-image"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="quantity-badge">×<?php echo htmlspecialchars($item['quantity']); ?></div>
                                            </div>

                                            <div class="product-detail-info">
                                                <div class="product-name"><?php echo htmlspecialchars($item['name']); ?></div>
                                                <?php if (!empty($item['description'])): ?>
                                                    <div class="product-description"><?php echo htmlspecialchars(mb_strimwidth($item['description'], 0, 200, '...')); ?></div>
                                                <?php endif; ?>

                                                <div class="product-pricing-details">
                                                    <div class="pricing-row">
                                                        <span class="pricing-label">Prix unitaire :</span>
                                                        <span class="pricing-value"><?php echo formatPrice($item['price']); ?></span>
                                                    </div>
                                                    <div class="pricing-row">
                                                        <span class="pricing-label">Quantité :</span>
                                                        <span class="pricing-value">×<?php echo (int)$item['quantity']; ?></span>
                                                    </div>
                                                    <div class="pricing-row total-row">
                                                        <span class="pricing-label">Sous-total :</span>
                                                        <span class="pricing-value total-value"><?php echo formatPrice($subtotal); ?></span>
                                                    </div>
                                                </div>

                                                <div class="product-actions">
                                                    <a href="product_detail.php?id=<?php echo (int)$item['id']; ?>" class="btn btn-outline-secondary btn-sm">
                                                        <i class="bi bi-eye"></i>
                                                        Voir le produit
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-products">
                                    <i class="bi bi-box"></i>
                                    <p>Aucun produit trouvé pour cette facture</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Section détails facture -->
                        <div class="order-section invoice-section">
                            <h4 class="section-title">
                                <i class="bi bi-receipt"></i>
                                Informations de facturation
                            </h4>
                            <div class="invoice-details">
                                <div class="invoice-row">
                                    <span class="label">Date de transaction :</span>
                                    <span class="value"><?php echo formatDate($invoice['transaction_date']); ?></span>
                                </div>
                                <div class="invoice-row">
                                    <span class="label">Montant total :</span>
                                    <span class="value amount"><?php echo formatPrice($invoice['amount']); ?></span>
                                </div>
                                <div class="invoice-row">
                                    <span class="label">Commande liée :</span>
                                    <span class="value">
                                        <a href="order_details.php?id=<?php echo (int)$invoice['order_id']; ?>" class="order-link">
                                            #<?php echo htmlspecialchars($invoice['order_id']); ?>
                                        </a>
                                    </span>
                                </div>
                                <?php if (!empty($invoice['billing_address']) || !empty($invoice['city']) || !empty($invoice['postal_code'])): ?>
                                    <div class="invoice-row">
                                        <span class="label">Adresse :</span>
                                        <span class="value address">
                                            <?php if (!empty($invoice['billing_address'])) echo htmlspecialchars($invoice['billing_address']) . '<br>'; ?>
                                            <?php echo htmlspecialchars(trim(($invoice['postal_code'] ?? '') . ' ' . ($invoice['city'] ?? ''))); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Related invoices -->
                            <?php if (!empty($related_invoices)): ?>
                                <div class="related-orders">
                                    <h5 class="related-title"><i class="bi bi-receipt-cutoff"></i> Autres factures apparentées</h5>
                                    <div class="related-list">
                                        <?php foreach ($related_invoices as $r): ?>
                                            <div class="related-item">
                                                <div class="related-left">
                                                    <div class="related-order-id">Facture #<?php echo (int)$r['invoice_id']; ?></div>
                                                    <div class="related-meta"><?php echo formatDate($r['transaction_date']); ?> · Commande #<?php echo (int)$r['order_id']; ?></div>
                                                </div>
                                                <div class="related-right">
                                                    <div class="related-amount"><?php echo formatPrice($r['amount']); ?></div>
                                                    <div class="related-actions">
                                                        <a href="invoice_details.php?id=<?php echo (int)$r['invoice_id']; ?>" class="btn btn-sm btn-outline-secondary">Voir</a>
                                                        <a href="order_details.php?id=<?php echo (int)$r['order_id']; ?>" class="btn btn-sm btn-outline-secondary">Commande</a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="order-actions">
                        <a href="orders_invoices.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i>
                            Retour aux commandes
                        </a>
                        <a href="order_details.php?id=<?php echo (int)$invoice['order_id']; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-box-seam"></i>
                            Voir la commande
                        </a>
                        <!-- Future: PDF download button -->
                        <button class="btn btn-outline-secondary" disabled title="Téléchargement PDF bientôt disponible">
                            <i class="bi bi-file-earmark-pdf"></i>
                            Télécharger PDF
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>
</main>

<script>
// Gallery helpers (identical to order_details)
function changeImage(card, direction) {
    const gallery = card.querySelector('.product-image-gallery');
    if (!gallery) return;
    const images = gallery.querySelectorAll('.gallery-image');
    const dots = gallery.querySelectorAll('.gallery-dot');
    let currentIndex = Array.from(images).findIndex(img => img.classList.contains('active'));
    if (currentIndex === -1) currentIndex = 0;
    images[currentIndex].classList.remove('active');
    if (dots[currentIndex]) dots[currentIndex].classList.remove('active');
    currentIndex += direction;
    if (currentIndex >= images.length) currentIndex = 0;
    if (currentIndex < 0) currentIndex = images.length - 1;
    images[currentIndex].classList.add('active');
    if (dots[currentIndex]) dots[currentIndex].classList.add('active');
}

function setActiveImage(card, index) {
    const gallery = card.querySelector('.product-image-gallery');
    if (!gallery) return;
    const images = gallery.querySelectorAll('.gallery-image');
    const dots = gallery.querySelectorAll('.gallery-dot');
    images.forEach((img, i) => img.classList.toggle('active', i === index));
    dots.forEach((dot, i) => dot.classList.toggle('active', i === index));
}

// Interactions (identical to orders_invoices)
(function() {
    const cards = document.querySelectorAll('.order-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.classList.add('animate-in');
    });

    document.querySelectorAll('.order-title').forEach(title => {
        title.addEventListener('click', function() {
            const invoiceNum = this.textContent.replace('Facture #', '');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(invoiceNum).then(() => {
                    showToast('Numéro de facture copié !');
                }).catch(()=>{});
            }
        });
        title.style.cursor = 'pointer';
        title.title = 'Cliquer pour copier le numéro';
    });

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
        }, 1600);
    }

    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
        @keyframes slideOutRight { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
        .animate-in { animation: fadeInUp 0.6s ease-out both; }
        @keyframes fadeInUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    `;
    document.head.appendChild(style);
})();
</script>

<?php include 'includes/footer.php'; ?>
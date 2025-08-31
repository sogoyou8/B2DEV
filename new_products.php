<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'includes/header.php';
include 'includes/db.php';

// Récupérez les produits ajoutés au cours des 2 derniers mois et actifs
try {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE created_at >= NOW() - INTERVAL 2 MONTH AND IFNULL(is_active,1) = 1 ORDER BY created_at DESC");
    $stmt->execute();
    $new_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $new_products = [];
    $_SESSION['error'] = "Impossible de récupérer les nouveaux produits : " . $e->getMessage();
}

// Précharger les favoris de l'utilisateur (pour afficher coeur plein/vides)
$userFavorites = [];
if (!empty($_SESSION['user_id'])) {
    try {
        $favStmt = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
        $favStmt->execute([$_SESSION['user_id']]);
        $rows = $favStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $userFavorites[] = (int)$r['item_id'];
        }
    } catch (Exception $e) {
        $userFavorites = [];
    }
}

// Placeholder SVG si pas d'image disponible (même que products.php)
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="18">Aucune image</text></svg>';
$placeholderDataUri = 'data:image/svg+xml;utf8,' . rawurlencode($svg);

// Utiliser le style des products pour avoir le même rendu
echo '<link rel="stylesheet" href="assets/css/user/products.css">' ;
?>
<main class="container py-4">
    <section class="products-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Nouveaux produits</h2>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="row">
            <?php if (!empty($new_products)): ?>
                <?php foreach ($new_products as $product): ?>
                    <?php
                    // Récupérer les images du produit
                    try {
                        $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                        $imgStmt->execute([$product['id']]);
                        $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Exception $e) {
                        $images = [];
                    }

                    // Sécuriser les valeurs à afficher
                    $pid = (int)$product['id'];
                    $pname = htmlspecialchars($product['name']);
                    $pdesc = htmlspecialchars($product['description']);
                    $pprice = htmlspecialchars(number_format((float)$product['price'], 2));
                    $isFav = in_array($pid, $userFavorites, true);

                    // Stock (peut être absent)
                    $pstock = isset($product['stock']) ? (int)$product['stock'] : null;

                    // URL cible pour la navigation JS (accessible)
                    $detailUrl = "product_detail.php?id=" . $pid;
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100 position-relative product-card" data-href="<?php echo $detailUrl; ?>" tabindex="0" role="link" aria-label="Voir <?php echo $pname; ?>">
                            <?php if ($pstock !== null && $pstock <= 0): ?>
                                <div class="oos-badge" title="Produit en rupture de stock">Rupture de stock</div>
                            <?php endif; ?>

                            <?php if (!empty($images)): ?>
                                <div id="carouselNewProduct<?php echo $pid; ?>" class="carousel slide" data-ride="carousel" data-interval="false">
                                    <div class="carousel-inner">
                                        <?php foreach ($images as $index => $image): ?>
                                            <?php $imgSrc = 'assets/images/' . htmlspecialchars($image['image']); ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="<?php echo $imgSrc; ?>" alt="<?php echo $pname; ?>" class="d-block w-100 product-img" onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($images) > 1): ?>
                                        <a class="carousel-control-prev" href="#carouselNewProduct<?php echo $pid; ?>" role="button" data-slide="prev">
                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Previous</span>
                                        </a>
                                        <a class="carousel-control-next" href="#carouselNewProduct<?php echo $pid; ?>" role="button" data-slide="next">
                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Next</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="product-img-placeholder">
                                    <img src="<?php echo $placeholderDataUri; ?>" alt="Aucune image" class="d-block w-100 product-img">
                                </div>
                            <?php endif; ?>

                            <div class="card-body d-flex flex-column">
                                <h5 class="card-title mb-1"><?php echo $pname; ?></h5>
                                <p class="card-text text-muted mb-2 product-desc"><?php echo $pdesc ?: '<span class="text-muted">Aucune description</span>'; ?></p>
                                <div class="mt-auto d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="h5 mb-0"><?php echo $pprice; ?> €</span>
                                        <?php if ($pstock !== null): ?>
                                            <?php if ($pstock > 0): ?>
                                                <small class="d-block text-muted">Stock : <?php echo $pstock; ?></small>
                                            <?php else: ?>
                                                <small class="d-block text-danger">Rupture de stock</small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="card-actions">
                                        <!-- reserved for layout -->
                                    </div>
                                </div>

                                <!-- Mini actions (panier + favoris) -->
                                <div class="ultra-actions" aria-hidden="false">
                                    <?php if ($pstock === null || $pstock > 0): ?>
                                        <form action="cart.php" method="post" class="m-0 p-0 ultra-form">
                                            <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                            <input type="hidden" name="quantity" value="1">
                                            <button type="submit" name="add" class="ultra-btn ultra-cart" title="Ajouter au panier" aria-label="Ajouter au panier">
                                                <div class="ultra-inner">
                                                    <div class="ultra-ripple"></div>
                                                    <div class="ultra-glow"></div>
                                                    <svg class="ultra-icon" width="20" height="20" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M7 4h12l-1 7H8L7 4Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                                        <circle cx="10" cy="20" r="1" fill="currentColor"/>
                                                        <circle cx="18" cy="20" r="1" fill="currentColor"/>
                                                        <path d="M5 2H3v2h2l3 8h7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                                                    </svg>
                                                    <div class="ultra-particles"></div>
                                                </div>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form action="favorites.php" method="post" class="m-0 p-0 ultra-form">
                                        <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                        <?php if ($isFav): ?>
                                            <button type="submit" name="remove" class="ultra-btn ultra-fav ultra-fav-active" title="Retirer des favoris" aria-label="Retirer des favoris">
                                                <div class="ultra-inner">
                                                    <div class="ultra-ripple"></div>
                                                    <div class="ultra-glow ultra-heart-glow"></div>
                                                    <svg class="ultra-icon ultra-heart" width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M20.8 6.6c-1.6-1.8-4.2-1.9-5.9-0.4l-0.9 0.8-0.9-0.8c-1.7-1.5-4.3-1.4-5.9 0.4-1.7 1.9-1.6 5 0.3 6.8l6.4 5.2 6.4-5.2c1.9-1.6 2-4.9 0.1-6.8z" fill="currentColor"/>
                                                    </svg>
                                                    <div class="ultra-particles ultra-heart-particles"></div>
                                                    <div class="ultra-heartbeat"></div>
                                                </div>
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="add" class="ultra-btn ultra-fav" title="Ajouter aux favoris" aria-label="Ajouter aux favoris">
                                                <div class="ultra-inner">
                                                    <div class="ultra-ripple"></div>
                                                    <div class="ultra-glow"></div>
                                                    <svg class="ultra-icon ultra-heart" width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path d="M20.8 6.6c-1.6-1.8-4.2-1.9-5.9-0.4l-0.9 0.8-0.9-0.8c-1.7-1.5-4.3-1.4-5.9 0.4-1.7 1.9-1.6 5 0.3 6.8l6.4 5.2 6.4-5.2c1.9-1.6 2-4.9 0.1-6.8z" stroke="currentColor" stroke-width="1.8" fill="none"/>
                                                    </svg>
                                                    <div class="ultra-particles"></div>
                                                </div>
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-info">Aucun nouveau produit ajouté au cours des 2 derniers mois.</div>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<script>
// Rendez la carte cliquable sans bloquer les boutons/inputs/links.
(function () {
    function isInteractive(el) {
        return el && (el.closest && (el.closest('button, a, form, input, select, textarea') !== null) || el.tagName === 'BUTTON' || el.tagName === 'A');
    }

    document.querySelectorAll('.product-card').forEach(function(card){
        var href = card.getAttribute('data-href');
        if (!href) return;

        card.addEventListener('click', function(e){
            var target = e.target;
            if (isInteractive(target)) return;
            if (target.closest && target.closest('button, a, form, input, select, textarea')) return;
            window.location.href = href;
        });

        card.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                var active = document.activeElement;
                if (active && active !== card && active.closest && active.closest('button, a, form, input, select, textarea')) {
                    return;
                }
                e.preventDefault();
                window.location.href = href;
            }
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
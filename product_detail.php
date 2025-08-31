<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/*
 * Charger la connexion DB en premier pour pouvoir effectuer les vérifications
 * avant d'inclure includes/header.php (évite "headers already sent" si on redirige).
 */
include 'includes/db.php';

// Récupérer et valider l'ID produit depuis la query string
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "Produit invalide.";
    header("Location: products.php");
    exit;
}

// Charger le produit uniquement s'il est actif (IFNULL(is_active,1)=1)
try {
    $query = $pdo->prepare("SELECT * FROM items WHERE id = ? AND IFNULL(is_active,1) = 1 LIMIT 1");
    $query->execute([$id]);
    $product = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: products.php");
    exit;
}

// Si produit non trouvé ou désactivé -> redirection vers la liste publique
if (!$product) {
    $_SESSION['error'] = "Produit introuvable ou désactivé.";
    header("Location: products.php");
    exit;
}

/*
 * À présent inclure le header (HTML) et ajouter les feuilles de style.
 * Tous les contrôles précédents sont faits avant l'émission d'entêtes/HTML.
 */
include 'includes/header.php';

// Placeholder SVG pour images manquantes (harmonisé avec products.php)
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="18">Aucune image</text></svg>';
$placeholderDataUri = 'data:image/svg+xml;utf8,' . rawurlencode($svg);

// Charger les styles : products.css (composants communs) + product_detail.css (spécifique)
echo '<link rel="stylesheet" href="assets/css/user/products.css">' ;
echo '<link rel="stylesheet" href="assets/css/user/product_detail.css">' ;

// Récupérer les images du produit
try {
    $query = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY position");
    $query->execute([$id]);
    $product_images = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $product_images = [];
}

// Vérifiez si l'utilisateur est connecté pour la gestion des favoris
$is_favorite = false;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    try {
        $query = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ? AND item_id = ?");
        $query->execute([$user_id, $id]);
        $is_favorite = $query->fetchColumn() > 0;
    } catch (Exception $e) {
        $is_favorite = false;
    }
} else {
    // Vérifiez si l'article est dans les favoris temporaires de session
    if (isset($_SESSION['temp_favorites']) && is_array($_SESSION['temp_favorites']) && in_array($id, $_SESSION['temp_favorites'])) {
        $is_favorite = true;
    }
}

// Récupérer les produits similaires (même catégorie, excluant le produit actuel)
$similar_products = [];
try {
    $similar_query = $pdo->prepare("SELECT * FROM items WHERE category = ? AND id != ? AND IFNULL(is_active,1) = 1 ORDER BY RAND() LIMIT 4");
    $similar_query->execute([$product['category'], $id]);
    $similar_products = $similar_query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $similar_products = [];
}

// Précharger les favoris de l'utilisateur pour les produits similaires
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

// Variables sécurisées pour l'affichage
$pid = (int)$product['id'];
$pname = htmlspecialchars($product['name']);
$pdesc = htmlspecialchars($product['description']);
$pprice = htmlspecialchars(number_format((float)$product['price'], 2));
$pstock = isset($product['stock']) ? (int)$product['stock'] : null;
$pcategory = htmlspecialchars($product['category']);
$pcreated = htmlspecialchars($product['created_at']);
?>

<main class="container py-4">
    <div class="product-detail-container">
        
        <!-- Navigation de retour -->
        <div class="back-navigation mb-3">
            <a href="products.php" class="btn-back">
                ← Retour aux produits
            </a>
        </div>

        <!-- Layout horizontal principal -->
        <div class="product-horizontal-layout">
            
            <!-- Section images gauche -->
            <div class="product-images-section">
                <?php if ($pstock !== null && $pstock <= 0): ?>
                    <div class="oos-badge" title="Produit en rupture de stock">Rupture de stock</div>
                <?php endif; ?>
                
                <!-- Carousel principal -->
                <div class="main-image-container">
                    <?php if (!empty($product_images)): ?>
                        <div id="productCarousel" class="carousel slide" data-ride="carousel" data-interval="false">
                            <div class="carousel-inner">
                                <?php foreach ($product_images as $index => $image): ?>
                                    <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                        <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" 
                                             alt="<?php echo $pname; ?>" 
                                             class="d-block w-100 carousel-main-img" 
                                             loading="lazy"
                                             onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if (count($product_images) > 1): ?>
                                <a class="carousel-control-prev" href="#productCarousel" role="button" data-slide="prev">
                                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Previous</span>
                                </a>
                                <a class="carousel-control-next" href="#productCarousel" role="button" data-slide="next">
                                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                    <span class="sr-only">Next</span>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="product-img-placeholder">
                            <img src="<?php echo $placeholderDataUri; ?>" 
                                 alt="Aucune image" 
                                 class="d-block w-100 carousel-main-img" 
                                 loading="lazy">
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Miniatures horizontales -->
                <?php if (!empty($product_images) && count($product_images) > 1): ?>
                <div class="thumbnails-horizontal">
                    <div class="thumbnails-scroll">
                        <?php foreach ($product_images as $index => $image): ?>
                            <div class="thumbnail-item">
                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" 
                                     alt="<?php echo $pname; ?>" 
                                     class="thumbnail-img" 
                                     loading="lazy"
                                     data-target="#productCarousel" 
                                     data-slide-to="<?php echo $index; ?>"
                                     onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Section informations droite -->
            <div class="product-info-section">
                
                <!-- Header avec titre et prix -->
                <div class="product-header">
                    <h1 class="product-title"><?php echo $pname; ?></h1>
                    <div class="product-price"><?php echo $pprice; ?> €</div>
                    <div class="product-category">Catégorie : <?php echo $pcategory; ?></div>
                </div>

                <!-- Description -->
                <div class="product-description">
                    <h3 class="description-title">Description</h3>
                    <p class="description-content">
                        <?php echo $pdesc ?: 'Aucune description disponible pour ce produit.'; ?>
                    </p>
                </div>

                <!-- Informations stock -->
                <?php if ($pstock !== null): ?>
                <div class="product-stock-info">
                    <h3 class="stock-title">Disponibilité</h3>
                    <div class="stock-status">
                        <?php if ($pstock > 0): ?>
                            <span class="stock-available">
                                <span class="stock-icon">✅</span>
                                En stock (<?php echo $pstock; ?> disponible<?php echo $pstock > 1 ? 's' : ''; ?>)
                            </span>
                        <?php else: ?>
                            <span class="stock-unavailable">
                                <span class="stock-icon">❌</span>
                                Rupture de stock
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Informations produit -->
                <div class="product-info">
                    <h3 class="info-title">Informations produit</h3>
                    <div class="info-details">
                        <div class="info-item">
                            <span class="info-label">Ajouté le :</span>
                            <span class="info-value"><?php echo date('d/m/Y', strtotime($pcreated)); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Référence :</span>
                            <span class="info-value">#<?php echo str_pad($pid, 6, '0', STR_PAD_LEFT); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Actions principales avec boutons ultra-modernes harmonisés -->
                <div class="product-actions">
                    <?php if ($pstock === null || $pstock > 0): ?>
                        <!-- Sélecteur de quantité -->
                        <div class="quantity-selector">
                            <label for="quantity" class="quantity-label">Quantité :</label>
                            <div class="quantity-controls">
                                <button type="button" class="quantity-btn quantity-decrease" aria-label="Diminuer la quantité">-</button>
                                <input type="number" id="quantity" class="quantity-input" value="1" min="1" <?php echo $pstock !== null ? 'max="'.$pstock.'"' : ''; ?>>
                                <button type="button" class="quantity-btn quantity-increase" aria-label="Augmenter la quantité">+</button>
                            </div>
                        </div>

                        <!-- Actions ultra modernes centrées -->
                        <div class="ultra-actions detail-actions">
                            <!-- Bouton panier ultra-moderne -->
                            <form id="addToCartForm" action="cart.php" method="post" class="m-0 p-0 ultra-form">
                                <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                <input type="hidden" name="quantity" id="hiddenQuantity" value="1">
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

                            <!-- Bouton favori avec gestion AJAX -->
                            <button class="ultra-btn ultra-fav <?php echo $is_favorite ? 'ultra-fav-active' : ''; ?>" 
                                    data-product-id="<?php echo $pid; ?>" 
                                    data-action="<?php echo $is_favorite ? 'remove' : 'add'; ?>"
                                    title="<?php echo $is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris'; ?>" 
                                    aria-label="<?php echo $is_favorite ? 'Retirer des favoris' : 'Ajouter aux favoris'; ?>">
                                <div class="ultra-inner">
                                    <div class="ultra-ripple"></div>
                                    <div class="ultra-glow <?php echo $is_favorite ? 'ultra-heart-glow' : ''; ?>"></div>
                                    <svg class="ultra-icon ultra-heart" width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path d="M20.8 6.6c-1.6-1.8-4.2-1.9-5.9-0.4l-0.9 0.8-0.9-0.8c-1.7-1.5-4.3-1.4-5.9 0.4-1.7 1.9-1.6 5 0.3 6.8l6.4 5.2 6.4-5.2c1.9-1.6 2-4.9 0.1-6.8z" 
                                              stroke="currentColor" 
                                              stroke-width="1.8" 
                                              fill="<?php echo $is_favorite ? 'currentColor' : 'none'; ?>"/>
                                    </svg>
                                    <div class="ultra-particles <?php echo $is_favorite ? 'ultra-heart-particles' : ''; ?>"></div>
                                    <?php if ($is_favorite): ?>
                                        <div class="ultra-heartbeat"></div>
                                    <?php endif; ?>
                                </div>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="out-of-stock-notice">
                            <h4>Produit indisponible</h4>
                            <p>Ce produit est actuellement en rupture de stock.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Section Produits similaires -->
    <?php if (!empty($similar_products)): ?>
    <section class="similar-products-section bg-light p-5 rounded shadow-sm mt-5">
        <h3 class="similar-title">Produits similaires</h3>
        <div class="row">
            <?php foreach ($similar_products as $similar): ?>
                <?php
                // Récupérer les images du produit similaire
                try {
                    $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                    $imgStmt->execute([$similar['id']]);
                    $similar_image = $imgStmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $similar_image = null;
                }

                // Sécuriser les valeurs à afficher
                $spid = (int)$similar['id'];
                $spname = htmlspecialchars($similar['name']);
                $spdesc = htmlspecialchars($similar['description']);
                $spprice = htmlspecialchars(number_format((float)$similar['price'], 2));
                $isFav = in_array($spid, $userFavorites, true);
                $spstock = isset($similar['stock']) ? (int)$similar['stock'] : null;
                $detailUrl = "product_detail.php?id=" . $spid;
                ?>
                <div class="col-md-3 col-sm-6 mb-4">
                    <div class="card h-100 position-relative product-card similar-product-card" data-href="<?php echo $detailUrl; ?>" tabindex="0" role="link" aria-label="Voir <?php echo $spname; ?>">
                        <?php if ($spstock !== null && $spstock <= 0): ?>
                            <div class="oos-badge" title="Produit en rupture de stock">Rupture de stock</div>
                        <?php endif; ?>

                        <?php if ($similar_image): ?>
                            <?php $imgSrc = 'assets/images/' . htmlspecialchars($similar_image['image']); ?>
                            <img src="<?php echo $imgSrc; ?>" alt="<?php echo $spname; ?>" class="d-block w-100 product-img" onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                        <?php else: ?>
                            <div class="product-img-placeholder">
                                <img src="<?php echo $placeholderDataUri; ?>" alt="Aucune image" class="d-block w-100 product-img">
                            </div>
                        <?php endif; ?>

                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title mb-1"><?php echo $spname; ?></h6>
                            <p class="card-text text-muted mb-2 small"><?php echo substr($spdesc ?: 'Aucune description', 0, 50) . (strlen($spdesc) > 50 ? '...' : ''); ?></p>
                            <div class="mt-auto d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="h6 mb-0"><?php echo $spprice; ?> €</span>
                                    <?php if ($spstock !== null): ?>
                                        <?php if ($spstock > 0): ?>
                                            <small class="d-block text-muted">Stock : <?php echo $spstock; ?></small>
                                        <?php else: ?>
                                            <small class="d-block text-danger">Rupture</small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Mini actions en bas à droite -->
                            <div class="ultra-actions" aria-hidden="false">
                                <?php if ($spstock === null || $spstock > 0): ?>
                                    <form action="cart.php" method="post" class="m-0 p-0 ultra-form">
                                        <input type="hidden" name="id" value="<?php echo $spid; ?>">
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
                                    <input type="hidden" name="id" value="<?php echo $spid; ?>">
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
        </div>
    </section>
    <?php endif; ?>
</main>

<script>
// Script pour miniatures -> carousel (fallback sans jQuery)
(function(){
    document.querySelectorAll('.thumbnail-img').forEach(function(thumbnail){
        thumbnail.addEventListener('click', function(e){
            e.preventDefault();
            var slideIndex = parseInt(this.getAttribute('data-slide-to') || 0, 10);
            
            // Essayer jQuery/Bootstrap d'abord
            if (window.jQuery && jQuery('#productCarousel').carousel) {
                jQuery('#productCarousel').carousel(slideIndex);
                return;
            }
            
            // Fallback: basculer les classes active manuellement
            var carouselItems = document.querySelectorAll('#productCarousel .carousel-item');
            if (!carouselItems.length) return;
            
            carouselItems.forEach(function(item, index){
                if (index === slideIndex) {
                    item.classList.add('active');
                } else {
                    item.classList.remove('active');
                }
            });
        });
    });

    // Navigation cliquable pour produits similaires
    function isInteractive(el) {
        return el && (el.closest && (el.closest('button, a, form, input, select, textarea') !== null) || el.tagName === 'BUTTON' || el.tagName === 'A');
    }

    document.querySelectorAll('.similar-product-card').forEach(function(card){
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

    // Gestion des contrôles de quantité
    var quantityInput = document.getElementById('quantity');
    var hiddenQuantity = document.getElementById('hiddenQuantity');
    var decreaseBtn = document.querySelector('.quantity-decrease');
    var increaseBtn = document.querySelector('.quantity-increase');
    var maxStock = <?php echo $pstock !== null ? $pstock : 999; ?>;

    function updateQuantity() {
        var value = parseInt(quantityInput.value) || 1;
        if (value < 1) value = 1;
        if (value > maxStock) value = maxStock;
        quantityInput.value = value;
        hiddenQuantity.value = value;
        
        // Mettre à jour les boutons
        decreaseBtn.disabled = value <= 1;
        increaseBtn.disabled = value >= maxStock;
    }

    if (decreaseBtn) {
        decreaseBtn.addEventListener('click', function() {
            var currentValue = parseInt(quantityInput.value) || 1;
            if (currentValue > 1) {
                quantityInput.value = currentValue - 1;
                updateQuantity();
            }
        });
    }

    if (increaseBtn) {
        increaseBtn.addEventListener('click', function() {
            var currentValue = parseInt(quantityInput.value) || 1;
            if (currentValue < maxStock) {
                quantityInput.value = currentValue + 1;
                updateQuantity();
            }
        });
    }

    if (quantityInput) {
        quantityInput.addEventListener('input', updateQuantity);
        quantityInput.addEventListener('change', updateQuantity);
        updateQuantity(); // Initialiser
    }

    // Gestion AJAX des favoris pour le produit principal avec mise à jour des compteurs
    document.querySelectorAll('.ultra-fav[data-product-id]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            
            var productId = this.getAttribute('data-product-id');
            var action = this.getAttribute('data-action');
            var button = this;
            
            // Créer FormData pour la requête
            var formData = new FormData();
            formData.append('id', productId);
            formData.append(action, '');
            
            // Requête AJAX
            fetch('favorites.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                if (response.ok) {
                    // Inverser l'état du bouton
                    var isActive = button.classList.contains('ultra-fav-active');
                    
                    if (isActive) {
                        // Retirer des favoris
                        button.classList.remove('ultra-fav-active');
                        button.setAttribute('data-action', 'add');
                        button.setAttribute('title', 'Ajouter aux favoris');
                        button.setAttribute('aria-label', 'Ajouter aux favoris');
                        
                        // Changer l'icône
                        var heartPath = button.querySelector('.ultra-heart path');
                        if (heartPath) {
                            heartPath.setAttribute('fill', 'none');
                            heartPath.setAttribute('stroke', 'currentColor');
                            heartPath.setAttribute('stroke-width', '1.8');
                        }
                        
                        // Retirer les effets visuels
                        var glow = button.querySelector('.ultra-glow');
                        var particles = button.querySelector('.ultra-particles');
                        var heartbeat = button.querySelector('.ultra-heartbeat');
                        
                        if (glow) glow.classList.remove('ultra-heart-glow');
                        if (particles) particles.classList.remove('ultra-heart-particles');
                        if (heartbeat) heartbeat.remove();
                        
                        // Décrémenter le compteur favoris
                        updateFavoritesCounter(-1);
                        
                    } else {
                        // Ajouter aux favoris
                        button.classList.add('ultra-fav-active');
                        button.setAttribute('data-action', 'remove');
                        button.setAttribute('title', 'Retirer des favoris');
                        button.setAttribute('aria-label', 'Retirer des favoris');
                        
                        // Changer l'icône
                        var heartPath = button.querySelector('.ultra-heart path');
                        if (heartPath) {
                            heartPath.setAttribute('fill', 'currentColor');
                            heartPath.removeAttribute('stroke');
                            heartPath.removeAttribute('stroke-width');
                        }
                        
                        // Ajouter les effets visuels
                        var glow = button.querySelector('.ultra-glow');
                        var particles = button.querySelector('.ultra-particles');
                        var inner = button.querySelector('.ultra-inner');
                        
                        if (glow) glow.classList.add('ultra-heart-glow');
                        if (particles) particles.classList.add('ultra-heart-particles');
                        
                        // Ajouter le heartbeat
                        if (inner && !inner.querySelector('.ultra-heartbeat')) {
                            var heartbeat = document.createElement('div');
                            heartbeat.className = 'ultra-heartbeat';
                            inner.appendChild(heartbeat);
                        }
                        
                        // Incrémenter le compteur favoris
                        updateFavoritesCounter(1);
                    }
                }
            })
            .catch(function(error) {
                console.error('Erreur lors de la mise à jour des favoris:', error);
            });
        });
    });

    // Fonction pour mettre à jour le compteur de favoris dans le header
    function updateFavoritesCounter(change) {
        var favBadge = document.querySelector('.navbar .badge');
        if (favBadge && favBadge.closest('a[href*="favorites"]')) {
            var currentCount = parseInt(favBadge.textContent) || 0;
            var newCount = Math.max(0, currentCount + change);
            favBadge.textContent = newCount;
            
            // Masquer le badge si count = 0
            if (newCount === 0) {
                favBadge.style.display = 'none';
            } else {
                favBadge.style.display = 'inline';
            }
        }
    }

    // Fonction pour mettre à jour le compteur de panier dans le header
    function updateCartCounter(change) {
        var cartBadge = document.querySelector('.navbar .badge');
        if (cartBadge && cartBadge.closest('a[href*="cart"]')) {
            var currentCount = parseInt(cartBadge.textContent) || 0;
            var newCount = Math.max(0, currentCount + change);
            cartBadge.textContent = newCount;
            
            // Masquer le badge si count = 0
            if (newCount === 0) {
                cartBadge.style.display = 'none';
            } else {
                cartBadge.style.display = 'inline';
            }
        }
    }

    // Intercepter le formulaire d'ajout au panier pour mettre à jour le compteur
    var addToCartForm = document.getElementById('addToCartForm');
    if (addToCartForm) {
        addToCartForm.addEventListener('submit', function(e) {
            // Laisser le formulaire se soumettre normalement
            // Mais mettre à jour le compteur après un court délai
            setTimeout(function() {
                var quantity = parseInt(quantityInput.value) || 1;
                updateCartCounter(quantity);
            }, 100);
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
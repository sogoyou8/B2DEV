<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include 'includes/header.php';
include 'includes/db.php';

// Récupérer les 4 produits les plus vendus (actifs uniquement)
$top_selling_ids = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id, SUM(od.quantity) as total_sold 
        FROM items i 
        JOIN order_details od ON i.id = od.item_id 
        WHERE IFNULL(i.is_active,1) = 1 
        GROUP BY i.id 
        ORDER BY total_sold DESC 
        LIMIT 4
    ");
    $stmt->execute();
    $top_selling = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($top_selling as $item) {
        $top_selling_ids[] = (int)$item['id'];
    }
} catch (Exception $e) {
    $top_selling_ids = [];
}

// Récupérer les 4 produits avec le plus de favoris (actifs uniquement)
$top_favorites_ids = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.id, COUNT(f.id) as favorite_count 
        FROM items i 
        JOIN favorites f ON i.id = f.item_id 
        WHERE IFNULL(i.is_active,1) = 1 
        GROUP BY i.id 
        ORDER BY favorite_count DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $top_favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prendre les 4 premiers qui ne sont pas déjà dans top_selling
    $added_count = 0;
    foreach ($top_favorites as $item) {
        $item_id = (int)$item['id'];
        if (!in_array($item_id, $top_selling_ids) && $added_count < 4) {
            $top_favorites_ids[] = $item_id;
            $added_count++;
        }
    }
    
    // Si on n'a pas assez d'articles différents, compléter avec les favoris restants
    if ($added_count < 4) {
        foreach ($top_favorites as $item) {
            $item_id = (int)$item['id'];
            if (!in_array($item_id, $top_favorites_ids) && count($top_favorites_ids) < 4) {
                $top_favorites_ids[] = $item_id;
            }
        }
    }
} catch (Exception $e) {
    $top_favorites_ids = [];
}

// Fusionner les deux listes pour créer le pool de sélection
$featured_pool = array_merge($top_selling_ids, $top_favorites_ids);

// Si pas assez d'articles populaires, compléter avec des articles aléatoires actifs
if (count($featured_pool) < 4) {
    try {
        $existing_ids = !empty($featured_pool) ? implode(',', $featured_pool) : '0';
        $stmt = $pdo->prepare("
            SELECT id FROM items 
            WHERE IFNULL(is_active,1) = 1 
            AND id NOT IN ($existing_ids) 
            ORDER BY RAND() 
            LIMIT ?
        ");
        $needed = 4 - count($featured_pool);
        $stmt->execute([$needed]);
        $random_items = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $featured_pool = array_merge($featured_pool, $random_items);
    } catch (Exception $e) {
        // Fallback silencieux
    }
}

// Sélectionner un produit vedette aléatoire dans le pool
$featured_product = null;
if (!empty($featured_pool)) {
    $random_id = $featured_pool[array_rand($featured_pool)];
    try {
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ? AND IFNULL(is_active,1) = 1 LIMIT 1");
        $stmt->execute([$random_id]);
        $featured_product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $featured_product = null;
    }
}

// Fallback : si aucun produit trouvé, prendre un produit actif aléatoire
if (!$featured_product) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM items WHERE IFNULL(is_active,1) = 1 ORDER BY RAND() LIMIT 1");
        $stmt->execute();
        $featured_product = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $featured_product = null;
    }
}

// Récupérer l'image du produit vedette
$productImage = null;
if ($featured_product) {
    try {
        $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
        $imgStmt->execute([$featured_product['id']]);
        $img = $imgStmt->fetch(PDO::FETCH_ASSOC);
        if ($img) {
            $productImage = 'assets/images/' . htmlspecialchars($img['image']);
        }
    } catch (Exception $e) {
        $productImage = null;
    }
}

// Précharger les favoris de l'utilisateur
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

// Charger le style pour la nouvelle page d'accueil
echo '<link rel="stylesheet" href="assets/css/user/index.css">';
?>

<main class="hero-container">
    <div class="hero-content">
        <!-- Section gauche avec contenu -->
        <div class="hero-left">
            <!-- Contenu principal -->
            <div class="hero-main">
                <?php if ($featured_product): ?>
                    <?php
                    $pid = (int)$featured_product['id'];
                    $pname = htmlspecialchars($featured_product['name']);
                    $pdesc = htmlspecialchars($featured_product['description']);
                    $pprice = number_format((float)$featured_product['price'], 2);
                    $pstock = isset($featured_product['stock']) ? (int)$featured_product['stock'] : null;
                    $pcategory = htmlspecialchars($featured_product['category']);
                    $isFav = in_array($pid, $userFavorites, true);
                    ?>
                    
                    <div class="product-highlight">
                        <h1 class="brand-name">SELECTION ELITE</h1>
                        <span class="brand-subtitle">Produits coups de cœur</span>
                        
                        <h2 class="product-name"><?php echo $pname; ?></h2>
                        <p class="product-tagline">
                            <?php echo $pdesc ?: "Un produit sélectionné spécialement pour vous parmi nos articles les plus populaires et les plus appréciés."; ?>
                        </p>
                        
                        <div class="product-meta">
                            <div class="price-section">
                                <span class="price-value"><?php echo $pprice; ?> €</span>
                                <span class="price-label">Prix actuel</span>
                            </div>
                            
                            <?php if ($pstock !== null): ?>
                                <div class="stock-section">
                                    <?php if ($pstock > 0): ?>
                                        <span class="stock-available">En stock</span>
                                        <span class="stock-count"><?php echo $pstock; ?> disponibles</span>
                                    <?php else: ?>
                                        <span class="stock-unavailable">Rupture de stock</span>
                                        <span class="stock-notify">Recevoir une notification</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="product-actions">
                            <?php if ($pstock === null || $pstock > 0): ?>
                                <form action="cart.php" method="post" class="action-form">
                                    <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" name="add" class="btn-primary">
                                        <span>Ajouter au panier</span>
                                        <i class="bi bi-cart-plus"></i>
                                    </button>
                                </form>
                                
                                <form action="favorites.php" method="post" class="action-form">
                                    <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                    <?php if ($isFav): ?>
                                        <button type="submit" name="remove" class="btn-secondary active" title="Retirer des favoris">
                                            <span>Retirer des favoris</span>
                                            <i class="bi bi-heart-fill"></i>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="add" class="btn-secondary" title="Ajouter aux favoris">
                                            <span>Ajouter aux favoris</span>
                                            <i class="bi bi-heart"></i>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="product-highlight">
                        <h1 class="brand-name">MARKETPLACE ELITE</h1>
                        <span class="brand-subtitle">Votre destination shopping de référence</span>
                        
                        <h2 class="product-name">Bienvenue</h2>
                        <p class="product-tagline">
                            Découvrez une sélection rigoureuse d'articles populaires et appréciés. 
                            Nos produits les plus vendus et les plus aimés vous attendent pour une expérience d'achat unique.
                        </p>
                        
                        <div class="product-actions">
                            <a href="products.php" class="btn-primary">
                                <span>Explorer le catalogue</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                            <a href="new_products.php" class="btn-secondary">
                                <span>Voir les nouveautés</span>
                                <i class="bi bi-star"></i>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section droite avec produit -->
        <div class="hero-right">
            <div class="product-showcase">
                <?php if ($productImage): ?>
                    <div class="product-image-container">
                        <a href="product_detail.php?id=<?php echo $pid; ?>" class="image-link" title="Voir les détails de <?php echo $pname; ?>">
                            <div class="product-image-wrapper">
                                <img src="<?php echo $productImage; ?>" alt="<?php echo $pname ?? 'Produit'; ?>" class="product-image">
                                <div class="image-glow"></div>
                            </div>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="product-placeholder">
                        <div class="placeholder-icon">
                            <i class="bi bi-box-seam"></i>
                        </div>
                        <span class="placeholder-text">Produit sélectionné</span>
                    </div>
                <?php endif; ?>
                
                <!-- Éléments décoratifs -->
                <div class="decorative-elements">
                    <div class="floating-element element-1"></div>
                    <div class="floating-element element-2"></div>
                    <div class="floating-element element-3"></div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Animation d'entrée
document.addEventListener('DOMContentLoaded', function() {
    const heroLeft = document.querySelector('.hero-left');
    const heroRight = document.querySelector('.hero-right');
    
    if (heroLeft) {
        heroLeft.style.opacity = '0';
        heroLeft.style.transform = 'translateX(-50px)';
        
        setTimeout(() => {
            heroLeft.style.transition = 'all 0.8s cubic-bezier(0.4, 0, 0.2, 1)';
            heroLeft.style.opacity = '1';
            heroLeft.style.transform = 'translateX(0)';
        }, 100);
    }
    
    if (heroRight) {
        heroRight.style.opacity = '0';
        heroRight.style.transform = 'translateX(50px)';
        
        setTimeout(() => {
            heroRight.style.transition = 'all 0.8s cubic-bezier(0.4, 0, 0.2, 1)';
            heroRight.style.opacity = '1';
            heroRight.style.transform = 'translateX(0)';
        }, 300);
    }
});

// Gestion du survol des boutons
document.querySelectorAll('.btn-primary, .btn-secondary').forEach(btn => {
    btn.addEventListener('mouseenter', function() {
        this.style.transform = 'translateY(-2px) scale(1.05)';
    });
    
    btn.addEventListener('mouseleave', function() {
        this.style.transform = 'translateY(0) scale(1)';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
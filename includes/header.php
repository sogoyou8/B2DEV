<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Inclure la connexion DB pour les compteurs
include_once __DIR__ . '/db.php';

// Déterminer état de session / rôle admin (valeurs provenant des pages qui incluent ce header)
$isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['admin_logged_in']);
$adminRole = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'];

// Par défaut, pas de notifications (les pages admin affichent leur propre header si nécessaire)
$unreadNotifications = 0;
$recentNotifications = [];

// Mettre à jour les compteurs panier et favoris automatiquement
if (!empty($_SESSION['user_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    
    // Compteur panier depuis la DB
    try {
        $cartStmt = $pdo->prepare("SELECT SUM(quantity) AS total FROM cart WHERE user_id = ?");
        $cartStmt->execute([$user_id]);
        $cartCount = (int)($cartStmt->fetchColumn() ?: 0);
        $_SESSION['cart_count'] = $cartCount;
    } catch (Exception $e) {
        $_SESSION['cart_count'] = 0;
    }
    
    // Compteur favoris depuis la DB
    try {
        $favStmt = $pdo->prepare("SELECT COUNT(*) FROM favorites WHERE user_id = ?");
        $favStmt->execute([$user_id]);
        $favCount = (int)($favStmt->fetchColumn() ?: 0);
        $_SESSION['favorites_count'] = $favCount;
    } catch (Exception $e) {
        $_SESSION['favorites_count'] = 0;
    }
} else {
    // Utilisateur non connecté : compteurs depuis session temporaire
    $_SESSION['cart_count'] = isset($_SESSION['temp_cart']) && is_array($_SESSION['temp_cart']) 
        ? array_sum($_SESSION['temp_cart']) : 0;
    
    $_SESSION['favorites_count'] = isset($_SESSION['temp_favorites']) && is_array($_SESSION['temp_favorites']) 
        ? count($_SESSION['temp_favorites']) : 0;
}

/**
 * Retourne 'active' si $href est présent dans l'URI courante.
 */
function isActive(string $href): string {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return (strpos($path, $href) !== false) ? 'active' : '';
}

/**
 * assetUrl — génère une URL web correcte vers un fichier dans /assets en tenant compte
 * du document root et du chemin physique du projet.
 */
function assetUrl(string $relativePath): string {
    // chemin absolu FS vers le dossier assets du projet (basé sur ce fichier)
    $assetsFsDir = realpath(__DIR__ . '/../assets');
    if ($assetsFsDir !== false) {
        // normaliser
        $assetsFsDir = str_replace('\\', '/', $assetsFsDir);

        // essayer d'obtenir document root réel
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        if ($docRoot !== false) {
            $docRoot = str_replace('\\', '/', $docRoot);
        } else {
            // fallback to raw DOCUMENT_ROOT if realpath failed
            $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
        }

        // si assets est sous le docroot, construire l'URL relative au webroot
        if ($docRoot !== '' && strpos($assetsFsDir, $docRoot) === 0) {
            $webPath = substr($assetsFsDir, strlen($docRoot));
            $webPath = '/' . ltrim(str_replace('\\', '/', $webPath), '/');
            return rtrim($webPath, '/') . '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
        }
    }

    // dernier recours : construire depuis la racine de l'URL en conservant une barre initiale
    return '/' . ltrim('assets/' . ltrim($relativePath, '/'), '/');
}

?><!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8" />
    <title>E-commerce Dynamique</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Performance: preconnect / preload -->
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet"></noscript>

    <?php $logoPath = htmlspecialchars(assetUrl('images/custom_logo.png')); ?>
    <link rel="preload" as="image" href="<?php echo $logoPath; ?>">

    <!-- Bootstrap 4.5 (compatibilité frontend existant) -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" crossorigin="anonymous">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">

    <!-- Projet - styles globaux -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/style.css')); ?>">

    <!-- Header specific CSS (moved out of inline <style>) -->
    <link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/user/header.css')); ?>">

</head>

<body>
<header>
<nav class="navbar navbar-expand-lg site-navbar sticky-top" role="navigation" aria-label="Navigation principale">
    <div class="container-fluid">
        <a class="navbar-brand site-brand" href="index.php" title="Accueil — E‑commerce Dynamique">
            <img src="<?php echo $logoPath; ?>" alt="E‑commerce Dynamique" class="site-logo" />
            <span class="site-brand-text d-none d-sm-inline">E-commerce Dynamique</span>
        </a>

        <button class="navbar-toggler text-white border-0 p-2" type="button" data-toggle="collapse" data-target="#mainNavbar" aria-controls="mainNavbar" aria-expanded="false" aria-label="Basculer la navigation">
            <i class="bi bi-list" aria-hidden="true" style="font-size:1.2rem;"></i>
        </button>

        <div class="collapse navbar-collapse" id="mainNavbar">
            <ul class="navbar-nav">
                <!-- left placeholder for future items -->
            </ul>

            <ul class="navbar-nav mx-auto">
                <li class="nav-item <?php echo isActive('/about.php'); ?>">
                    <a class="nav-link <?php echo isActive('/about.php'); ?>" href="about.php">Qui sommes-nous ?</a>
                </li>
                <li class="nav-item <?php echo isActive('/products.php'); ?>">
                    <a class="nav-link <?php echo isActive('/products.php'); ?>" href="products.php">Articles</a>
                </li>
                <li class="nav-item <?php echo isActive('/new_products.php'); ?>">
                    <a class="nav-link <?php echo isActive('/new_products.php'); ?>" href="new_products.php">Nouveautés</a>
                </li>
            </ul>

            <ul class="navbar-nav align-items-center">
                <!-- Desktop search -->
                <li class="nav-item d-none d-lg-flex align-items-center me-2">
                    <form action="search.php" method="get" class="d-flex align-items-center" role="search" aria-label="Recherche produits">
                        <div class="nav-search" role="search" aria-hidden="false">
                            <label for="navSearch" class="sr-only">Rechercher des produits</label>
                            <input id="navSearch" name="query" type="search" placeholder="Rechercher des produits, ex: chaussures, poster..." aria-label="Rechercher des produits" autocomplete="off" value="<?php echo htmlspecialchars($_GET['query'] ?? '', ENT_QUOTES); ?>">
                            <button type="submit" class="search-btn" aria-label="Lancer la recherche"><i class="bi bi-search"></i></button>
                        </div>
                    </form>
                </li>

                <!-- Mobile search toggle -->
                <li class="nav-item d-lg-none">
                    <a class="nav-link nav-icon-btn" href="#" id="mobileSearchToggle" aria-controls="mobileSearchContainer" aria-expanded="false" aria-label="Ouvrir la recherche mobile">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </a>
                </li>

                <!-- Cart -->
                <li class="nav-item">
                    <a class="nav-link nav-icon-btn <?php echo isActive('/cart.php'); ?>" href="cart.php" aria-label="Panier">
                        <i class="bi bi-cart2" aria-hidden="true"></i>
                        <?php if (!empty($_SESSION['cart_count']) && $_SESSION['cart_count'] > 0): ?>
                            <span class="badge badge-notif" aria-live="polite" data-badge="cart"><?php echo ((int)$_SESSION['cart_count'] > 99) ? '99+' : (int)$_SESSION['cart_count']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <!-- Favorites -->
                <li class="nav-item">
                    <a class="nav-link nav-icon-btn <?php echo isActive('/favorites.php'); ?>" href="favorites.php" aria-label="Favoris">
                        <i class="bi bi-heart" aria-hidden="true"></i>
                        <?php if (!empty($_SESSION['favorites_count']) && $_SESSION['favorites_count'] > 0): ?>
                            <span class="badge badge-notif" aria-live="polite" data-badge="favorites"><?php echo ((int)$_SESSION['favorites_count'] > 99) ? '99+' : (int)$_SESSION['favorites_count']; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <?php if ($isLoggedIn): ?>
                    <?php if ($adminRole): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link nav-icon-btn" href="admin/notifications.php" id="notifMenu" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Centre de notifications" aria-controls="notifDropdown">
                                <i class="bi bi-bell" aria-hidden="true"></i>
                                <?php if ($unreadNotifications > 0): ?>
                                    <span class="badge badge-notif" aria-live="polite" data-badge="notif"><?php echo $unreadNotifications > 99 ? '99+' : $unreadNotifications; ?></span>
                                <?php endif; ?>
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow-sm" aria-labelledby="notifMenu" id="notifDropdown" role="menu" style="min-width:320px;">
                                <div class="px-3 py-2 small text-muted">Centre de notifications — dernières alertes</div>
                                <div class="dropdown-divider" role="separator"></div>
                                <?php if (!empty($recentNotifications)): ?>
                                    <?php foreach ($recentNotifications as $n): ?>
                                        <a class="dropdown-item <?php echo empty($n['is_read']) ? 'font-weight-bold' : ''; ?>" href="#" role="menuitem">
                                            <div class="small text-muted"><?php echo htmlspecialchars($n['type'] ?? '', ENT_QUOTES); ?> • <?php echo htmlspecialchars((new DateTime($n['created_at']))->format('d/m/Y H:i')); ?></div>
                                            <div><?php echo htmlspecialchars(mb_strimwidth($n['message'] ?? '', 0, 120, '…')); ?></div>
                                        </a>
                                    <?php endforeach; ?>
                                    <div class="dropdown-divider"></div>
                                    <a class="dropdown-item text-center small" href="admin/notifications.php">Voir toutes les notifications</a>
                                <?php else: ?>
                                    <div class="dropdown-item small text-muted">Aucune notification récente.</div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endif; ?>

                    <!-- User dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userMenu" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Menu utilisateur">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                            <span class="d-none d-lg-inline ml-1"><?php echo htmlspecialchars($_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? 'Profil', ENT_QUOTES); ?></span>
                        </a>
                        <div class="dropdown-menu dropdown-menu-right" aria-labelledby="userMenu" role="menu">
                            <a class="dropdown-item" href="profile.php" role="menuitem"><i class="bi bi-person me-1"></i> Mon profil</a>
                            <a class="dropdown-item" href="orders_invoices.php" role="menuitem"><i class="bi bi-card-list me-1"></i> Mes commandes</a>
                            <div class="dropdown-divider" role="separator"></div>
                            <?php if ($adminRole): ?>
                                <a class="dropdown-item" href="admin/dashboard.php" role="menuitem"><i class="bi bi-speedometer2 me-1"></i> Administration</a>
                            <?php endif; ?>
                            <a class="dropdown-item" href="logout.php" role="menuitem"><i class="bi bi-box-arrow-right me-1"></i> Déconnexion</a>
                        </div>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo isActive('/register.php'); ?>" href="register.php"><i class="bi bi-pencil-square"></i> Inscription</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo isActive('/login.php'); ?>" href="login.php"><i class="bi bi-box-arrow-in-right"></i> Connexion</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<!-- Mobile search floating container -->
<div id="mobileSearchContainer" aria-hidden="true">
    <div class="container-fluid">
        <div class="mobile-search-box">
            <form action="search.php" method="get" class="d-flex w-100" role="search" aria-label="Recherche mobile">
                <label for="navSearchMobile" class="sr-only">Rechercher</label>
                <input id="navSearchMobile" type="search" name="query" class="form-control form-control-sm" placeholder="Rechercher des produits..." value="<?php echo htmlspecialchars($_GET['query'] ?? '', ENT_QUOTES); ?>">
                <button class="btn btn-outline-light btn-sm" type="submit" aria-label="Lancer la recherche"><i class="bi bi-search"></i></button>
                <button type="button" class="btn btn-link text-white ml-2" id="mobileSearchClose" aria-label="Fermer la recherche">Annuler</button>
            </form>
        </div>
    </div>
</div>

</header>

<!-- Lightweight enhancement JS: accessible keyboard behaviour, mobile search toggle, graceful fallback if jQuery missing -->
<script>
(function(){
    // Mobile search toggle
    var toggle = document.getElementById('mobileSearchToggle');
    var container = document.getElementById('mobileSearchContainer');
    var closeBtn = document.getElementById('mobileSearchClose');

    function showMobileSearch() {
        if (container) {
            container.classList.add('show');
            container.setAttribute('aria-hidden','false');
            // focus input
            var input = document.getElementById('navSearchMobile');
            if (input) input.focus();
        }
    }
    function hideMobileSearch() {
        if (container) {
            container.classList.remove('show');
            container.setAttribute('aria-hidden','true');
            // return focus to toggle for accessibility
            if (toggle) toggle.focus();
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function(e){
            e.preventDefault();
            if (container && container.classList.contains('show')) hideMobileSearch(); else showMobileSearch();
        });
    }
    if (closeBtn) closeBtn.addEventListener('click', function(){ hideMobileSearch(); });

    // Close mobile search on Escape
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            hideMobileSearch();
            // close open dropdowns if bootstrap is available
            try {
                if (window.jQuery) {
                    var open = document.querySelectorAll('.dropdown-menu.show');
                    open.forEach(function(d){ try { $(d).closest('.dropdown').find('[data-toggle="dropdown"]').dropdown('hide'); } catch(e){ d.classList.remove('show'); }});
                }
            } catch(err){}
        }
    });

    // Keyboard: open dropdowns on Enter/Space for elements that have data-toggle
    document.querySelectorAll('[data-toggle="dropdown"]').forEach(function(btn){
        btn.addEventListener('keyup', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                try { $(btn).dropdown('toggle'); } catch(e){ btn.click(); }
            }
        });
    });

    // Progressive: if bootstrap/js not loaded yet, the markup still functions as normal links.
})();
</script>

<!-- Note: footer.php should inclure les scripts JS globaux (jQuery/bootstrap.bundle) en bas de page pour performance -->
<!-- header.php laisse l'ouverture <body> en place ; le footer ferme body et html -->
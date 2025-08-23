<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - E‑commerce Dynamique</title>

    <!-- Bootstrap + icons (comme avant) -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <!-- Réutilise les styles globaux du projet pour garantir l'apparence identique -->
    <link rel="stylesheet" href="../assets/css/style.css">

    <!-- Variables & règles critiques harmonisées avec includes/header.php -->
    <style>
    :root{
        --nav-h: 72px;
        --nav-bg: rgba(28,30,31,0.72);
        --nav-blur: 8px;
        --accent: #5b8cff;
        --accent-2: #6b6eff;
        --muted: rgba(255,255,255,0.78);
        --glass-border: rgba(255,255,255,0.06);
        --pill-bg: rgba(255,255,255,0.06);
        --radius: 14px;
        --shadow-elevate: 0 8px 24px rgba(2,6,23,0.35);
        font-family: "Inter", system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }

    /* Navbar container */
    .site-navbar {
        min-height: var(--nav-h);
        background: linear-gradient(180deg, rgba(20,22,23,0.88), rgba(30,32,33,0.85));
        backdrop-filter: blur(var(--nav-blur));
        -webkit-backdrop-filter: blur(var(--nav-blur));
        border-bottom: 1px solid var(--glass-border);
        box-shadow: var(--shadow-elevate);
        padding: .45rem 1rem;
    }

    /* Brand */
    .site-brand {
        display:flex;
        align-items:center;
        gap: .7rem;
        text-decoration:none;
        color: #fff;
        font-weight:700;
        letter-spacing: .2px;
    }
    .site-logo {
        height:38px;
        width:auto;
        border-radius:8px;
        box-shadow: 0 6px 18px rgba(0,0,0,0.45), inset 0 -2px 6px rgba(255,255,255,0.02);
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.01));
        padding:4px;
    }
    .site-brand-text {
        color: var(--muted);
        font-size:1rem;
        display:inline-block;
        font-weight:700;
    }

    /* Center nav links (admin may not use mx-auto but keep rule for consistency) */
    .navbar-nav.mx-auto {
        margin-left:auto;
        margin-right:auto;
        gap: .15rem;
    }
    .nav-link {
        color: var(--muted) !important;
        padding: .45rem .8rem;
        border-radius: 10px;
        transition: all .18s cubic-bezier(.2,.9,.2,1);
        font-weight:600;
        font-size:0.98rem;
    }
    .nav-link:hover, .nav-link:focus {
        color: #fff !important;
        transform: translateY(-2px);
        text-decoration:none;
    }
    .nav-link.active {
        background: linear-gradient(90deg, rgba(91,140,255,0.12), rgba(107,110,255,0.08));
        color: #fff !important;
        box-shadow: 0 6px 18px rgba(91,140,255,0.06);
    }

    /* Search pill (kept to ensure consistent search visuals when used) */
    .nav-search {
        display:flex;
        align-items:center;
        gap:.5rem;
        background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.03));
        border-radius: 999px;
        padding:6px 10px;
        min-width:220px;
        max-width:460px;
        box-shadow: 0 6px 20px rgba(2,6,23,0.25);
    }
    .nav-search input[type="search"]{
        border:0;
        outline:0;
        background:transparent;
        color: #f7f7f7;
        padding:6px 8px;
        font-weight:500;
    }
    .nav-search .search-btn {
        background: transparent;
        border: none;
        color: var(--muted);
        display:inline-flex;
        align-items:center;
        justify-content:center;
        padding:4px;
        width:36px; height:36px;
        border-radius:50%;
        transition: all .15s;
    }
    .nav-search .search-btn:hover { transform: scale(1.04); color: #fff; background: rgba(255,255,255,0.03); }

    /* Icon buttons */
    .nav-icon-btn {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        color: var(--muted);
        padding:.4rem .55rem;
        border-radius:10px;
        transition: all .12s;
    }
    .nav-icon-btn:hover { color:#fff; transform: translateY(-2px); background: rgba(255,255,255,0.03); text-decoration:none; }

    /* Notification / badges */
    .badge-notif {
        transform: translateY(-4px);
        font-size:0.66rem;
        padding:.18rem .46rem;
        border-radius:10px;
        background: linear-gradient(90deg,#ff5f6d,#ffc371);
        color:#111;
        box-shadow: 0 6px 12px rgba(0,0,0,0.25);
        position:relative;
        margin-left:6px;
    }

    /* Mobile adjustments */
    @media (max-width: 991.98px) {
        .navbar-nav.mx-auto { display:flex; gap:.25rem; }
        .nav-search { display:none; } /* hidden, mobile has separate collapse */
    }

    /* Mobile compact search */
    #mobileSearchContainer {
        display:none;
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        top: calc(var(--nav-h) + 8px);
        width: calc(100% - 40px);
        max-width:720px;
        z-index: 1500;
    }
    #mobileSearchContainer.show {
        display:block;
    }
    #mobileSearchContainer .mobile-search-box {
        border-radius:12px;
        padding:10px;
        background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
        box-shadow: 0 12px 30px rgba(2,6,23,0.45);
        display:flex;
        gap:.5rem;
        align-items:center;
    }

    /* Accessibility helper */
    .sr-only-focusable:focus { position: static; width:auto; height:auto; overflow:visible; clip:auto; }

    /* subtle caret for dropdown toggles */
    .dropdown-toggle::after { display:none; }

    /* Align icon + username like frontend header */
    .navbar-nav .nav-link,
    .navbar-nav .dropdown-toggle {
        display: inline-flex;
        align-items: center;
        gap: .35rem; /* espace entre icône et texte (ajustez si besoin) */
    }
    .navbar-nav .nav-link i,
    .navbar-nav .dropdown-toggle i {
        line-height: 1; /* propre alignement de l'icône */
        vertical-align: middle;
    }

    </style>
</head>
<body>
<header>
<nav class="navbar navbar-expand-lg navbar-dark site-navbar sticky-top" role="navigation" aria-label="Navigation admin">
    <div class="container-fluid">
        <?php $logoPath = '../assets/images/custom_logo.png'; ?>
        <a class="navbar-brand site-brand" href="dashboard.php" title="Administration — E‑commerce Dynamique">
            <img src="<?php echo htmlspecialchars($logoPath); ?>" alt="E‑commerce Dynamique" class="site-logo" />
            <span class="site-brand-text d-none d-sm-inline">E-commerce Dynamique</span>
        </a>

        <button class="navbar-toggler text-white border-0 p-2" type="button" data-toggle="collapse" data-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Basculer la navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav mr-auto" role="menu">
                <?php $isAdmin = !empty($_SESSION['admin_name']) || (($_SESSION['role'] ?? '') === 'admin');
                ?>
                <li class="nav-item"><a class="nav-link" href="list_products.php">Produits</a></li>
                <li class="nav-item"><a class="nav-link" href="list_users.php">Utilisateurs</a></li>
                <li class="nav-item"><a class="nav-link" href="list_orders.php">Commandes</a></li>
                <li class="nav-item"><a class="nav-link" href="sales_history.php">Ventes</a></li>

                <li class="nav-item dropdown">
                    <div class="dropdown-menu" aria-labelledby="adminToolsMenu">
                        <a class="dropdown-item" href="bulk_update_products.php">Mise à jour en masse</a>
                        <a class="dropdown-item" href="manage_product_images.php">Images produits</a>
                        <a class="dropdown-item" href="notifications.php">Notifications</a>
                        <a class="dropdown-item" href="prediction.php">Prédictions IA</a>
                        <a class="dropdown-item" href="mark_all_read.php">Tout marquer lu</a>
                    </div>
                </li>
            </ul>

            <ul class="navbar-nav ml-auto align-items-center">
                <?php
                // Notification count (simple)
                $count = 0;
                try {
                    $count = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
                } catch (Exception $e) {
                    // ignore DB errors here — header should remain minimal
                }
                ?>
                <li class="nav-item me-2">
                    <a class="nav-link nav-icon-btn" href="notifications.php" role="menuitem" aria-label="Notifications">
                        <i class="bi bi-bell" aria-hidden="true"></i>
                        <?php if ($count > 0): ?>
                            <span class="badge badge-danger badge-notif" aria-live="polite" data-badge="admin-notif"><?php echo $count > 99 ? '99+' : $count; ?></span>
                        <?php endif; ?>
                    </a>
                </li>

                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="adminUserMenu" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" aria-label="Menu utilisateur admin">
                            <i class="bi bi-person-circle" aria-hidden="true"></i>
                            <span class="d-none d-lg-inline ml-1"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? $_SESSION['user_name'] ?? 'Admin', ENT_QUOTES); ?></span>
                        </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="adminUserMenu" role="menu">
                        <a class="dropdown-item" href="../profile.php" role="menuitem"><i class="bi bi-person me-1"></i> Mon profil</a>
                        <?php if ($isAdmin): ?>
                            <a class="dropdown-item" href="how_it_works.php" role="menuitem"><i class="bi bi-question-circle me-1"></i> Aide</a>
                        <?php endif; ?>                        <div class="dropdown-divider" role="separator"></div>
                        <a class="dropdown-item" href="../index.php" role="menuitem"><i class="bi bi-globe me-1"></i> Voir le site</a>
                        <a class="dropdown-item" href="logout.php" role="menuitem"><i class="bi bi-box-arrow-right me-1"></i> Déconnexion</a>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1): ?>
    <div class="container-fluid mt-2">
        <span class="badge badge-warning text-dark demo-badge" style="font-size:1rem;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Mode Démo
            <a href="how_it_works.php?page=demo" class="ms-2 text-dark" title="À propos du mode démo"><i class="bi bi-info-circle"></i></a>
        </span>
    </div>
<?php endif; ?>

</header>
<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/db.php'; // Assure la connexion PDO pour le rôle admin
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>E-commerce Dynamique</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <header>
        <nav>
            <ul class="nav">
                <li class="nav-item"><a class="nav-link" href="index.php">Accueil</a></li>
                <li class="nav-item"><a class="nav-link" href="about.php">Qui sommes-nous ?</a></li>
                <li class="nav-item"><a class="nav-link" href="products.php">Articles</a></li>
                <li class="nav-item"><a class="nav-link" href="new_products.php">Nouveautés</a></li>
                <li class="nav-item"><a class="nav-link" href="cart.php">Panier</a></li>
                <li class="nav-item"><a class="nav-link" href="favorites.php">Favoris</a></li>
                <?php if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']): ?>
                    <li class="nav-item"><a class="nav-link" href="profile.php">Profil</a></li>
                    <li class="nav-item"><a class="nav-link" href="orders_invoices.php">Mes Commandes et Factures</a></li>
                    <?php
                    // Vérification du rôle admin
                    if (isset($_SESSION['user_id'])) {
                        $query = $pdo->prepare("SELECT role FROM users WHERE id = ?");
                        $query->execute([$_SESSION['user_id']]);
                        $role = $query->fetchColumn();
                        if ($role === 'admin'): ?>
                            <li class="nav-item"><a class="nav-link" href="admin/dashboard.php">Admin</a></li>
                        <?php endif;
                    }
                    ?>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Déconnexion</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="register.php">Inscription</a></li>
                    <li class="nav-item"><a class="nav-link" href="login.php">Connexion</a></li>
                <?php endif; ?>
            </ul>
        </nav>
        <form action="search.php" method="get" class="form-inline">
            <input type="text" name="query" class="form-control mr-sm-2" placeholder="Rechercher des produits">
            <button type="submit" class="btn btn-outline-success">Rechercher</button>
        </form>
    </header>
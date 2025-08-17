<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - E-commerce Dynamique</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
</head>
<body>
    <header>
        <nav class="navbar navbar-expand-lg navbar-dark">
            <a class="navbar-brand" href="dashboard.php">Admin Dashboard</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="list_products.php">Produits</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="list_users.php">Utilisateurs</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="list_orders.php">Commandes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="create_admin.php">Créer Admin</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="sales_history.php">Historique des ventes</a>
                    </li>
                    <!-- Bouton pour revenir au site utilisateur -->
                    <li class="nav-item">
                        <a class="nav-link btn btn-outline-light mx-2" href="../index.php">
                            <i class="bi bi-house-door"></i> Site utilisateur
                        </a>
                    </li>
                    <?php
                    $count = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
                    ?>
                    <li class="nav-item">
                        <a class="nav-link position-relative" href="notifications.php">
                            <i class="bi bi-bell"></i>
                            <?php if ($count > 0): ?>
                                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle"><?php echo $count; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Déconnexion</a>
                    </li>
                </ul>
            </div>
        </nav>
    <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1): ?>
        <span class="badge bg-warning text-dark ms-2" style="font-size:1rem;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Mode Démo
            <a href="how_it_works.php?page=demo" class="ms-2 text-dark" title="À propos du mode démo">
                <i class="bi bi-info-circle"></i>
            </a>
        </span>
    <?php endif; ?> 
    </header>   
</body>
</html>

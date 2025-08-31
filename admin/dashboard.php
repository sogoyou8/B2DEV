<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit;
}
include 'includes/header.php';
include '../includes/db.php';
require_once '../includes/classes/Product.php';
require_once '../includes/classes/Notification.php';
require_once '../includes/classes/Order.php';

// Ensure admin visual style is applied (many admin pages add this)
?>
<script>try { document.body.classList.add('admin-page'); } catch(e){}</script>
<?php

// === STATISTIQUES PRINCIPALES ===

// Statistiques aujourd'hui
$today_stats = [
    'orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn(),
    'revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn(),
    'new_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'products_sold' => $pdo->query("SELECT COALESCE(SUM(od.quantity), 0) FROM order_details od JOIN orders o ON od.order_id = o.id WHERE DATE(o.order_date) = CURDATE()")->fetchColumn()
];

// Comparaison avec hier
$yesterday_stats = [
    'orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn(),
    'revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE DATE(order_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn(),
    'new_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)")->fetchColumn(),
];

// Statistiques de la semaine pour plus de contexte
$week_stats = [
    'orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE WEEK(order_date) = WEEK(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())")->fetchColumn(),
    'revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE WEEK(order_date) = WEEK(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())")->fetchColumn(),
    'new_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE WEEK(created_at) = WEEK(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn(),
    'products_sold' => $pdo->query("SELECT COALESCE(SUM(od.quantity), 0) FROM order_details od JOIN orders o ON od.order_id = o.id WHERE WEEK(o.order_date) = WEEK(CURDATE()) AND YEAR(o.order_date) = YEAR(CURDATE())")->fetchColumn()
];

// Statistiques du mois
$month_stats = [
    'orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE MONTH(order_date) = MONTH(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())")->fetchColumn(),
    'revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE MONTH(order_date) = MONTH(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())")->fetchColumn(),
    'new_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn(),
    'products_sold' => $pdo->query("SELECT COALESCE(SUM(od.quantity), 0) FROM order_details od JOIN orders o ON od.order_id = o.id WHERE MONTH(o.order_date) = MONTH(CURDATE()) AND YEAR(o.order_date) = YEAR(CURDATE())")->fetchColumn()
];

// Moyenne par commande aujourd'hui
$avg_order_today = $today_stats['orders'] > 0 ? $today_stats['revenue'] / $today_stats['orders'] : 0;
$avg_order_month = $month_stats['orders'] > 0 ? $month_stats['revenue'] / $month_stats['orders'] : 0;

// Calcul des pourcentages de variation
if (!function_exists('calculatePercentageChange')) {
    function calculatePercentageChange($current, $previous) {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }
}

// Statistiques globales avec plus de détails
$global_stats = [
    'total_products' => $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn(),
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
    'total_orders' => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'total_revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders")->fetchColumn(),
    'low_stock_count' => $pdo->query("SELECT COUNT(*) FROM items WHERE stock <= stock_alert_threshold")->fetchColumn(),
    'pending_orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
    'unread_notifications' => $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn(),
    'delivered_orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'delivered'")->fetchColumn(),
    'shipped_orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'shipped'")->fetchColumn(),
    'cancelled_orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'cancelled'")->fetchColumn(),
    'monthly_revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE MONTH(order_date) = MONTH(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())")->fetchColumn(),
    'avg_order_value' => $pdo->query("SELECT COALESCE(AVG(total_price), 0) FROM orders")->fetchColumn(),
    'total_admins' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn(),
    'active_categories' => $pdo->query("SELECT COUNT(DISTINCT category) FROM items WHERE category IS NOT NULL AND category != ''")->fetchColumn()
];

// Alerte stock critique avec plus de détails
$critical_stock = $pdo->query("SELECT name, stock, stock_alert_threshold, category, price FROM items WHERE stock = 0 OR stock <= 2 ORDER BY stock ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Top 5 produits les plus vendus (30 derniers jours) avec plus d'infos
$top_products = $pdo->query("
    SELECT i.name, i.price, i.stock, i.category, SUM(od.quantity) as total_sold, SUM(od.quantity * od.price) as revenue,
           COUNT(DISTINCT o.user_id) as unique_customers
    FROM order_details od 
    JOIN items i ON od.item_id = i.id 
    JOIN orders o ON od.order_id = o.id 
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY i.id 
    ORDER BY total_sold DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Commandes récentes avec plus d'informations (limitées à 5)
$recent_orders = $pdo->query("
    SELECT o.id, u.name as user_name, u.email, o.total_price, o.status, o.order_date, o.user_id,
           DATE_FORMAT(o.order_date, '%d/%m %H:%i') as formatted_date,
           DATE_FORMAT(o.order_date, '%W') as day_name,
           COUNT(od.id) as items_count,
           SUM(od.quantity) as total_items
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    LEFT JOIN order_details od ON o.id = od.order_id
    GROUP BY o.id
    ORDER BY o.order_date DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Données pour l'évolution du stock (limitées à 8 avec plus d'infos)
$stock_data = $pdo->query("
    SELECT name, stock, stock_alert_threshold, category, price,
           CASE 
               WHEN stock = 0 THEN 'Rupture'
               WHEN stock <= stock_alert_threshold THEN 'Critique'
               WHEN stock <= stock_alert_threshold * 2 THEN 'Faible'
               ELSE 'Correct'
           END as status_stock
    FROM items 
    ORDER BY stock ASC, name 
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Données pour les ventes par mois (6 derniers mois) avec évolution
$sales_data = array_reverse($pdo->query("
    SELECT DATE_FORMAT(o.order_date, '%Y-%m') as period, 
           DATE_FORMAT(o.order_date, '%M %Y') as label,
           SUM(od.quantity) as total,
           SUM(o.total_price) as revenue,
           COUNT(DISTINCT o.id) as orders_count,
           COUNT(DISTINCT o.user_id) as unique_customers,
           AVG(o.total_price) as avg_order_value
    FROM order_details od
    JOIN orders o ON od.order_id = o.id
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY period
    ORDER BY period DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC));

// Données pour les prédictions avec plus de détails
$previsions_data = $pdo->query("
    SELECT i.name, i.stock, i.category, p.quantite_prevue, p.confidence_score, p.trend_direction,
           (p.quantite_prevue - i.stock) as stock_diff,
           CASE 
               WHEN p.quantite_prevue > i.stock * 2 THEN 'Forte demande'
               WHEN p.quantite_prevue > i.stock THEN 'Demande élevée'
               WHEN p.quantite_prevue < i.stock / 2 THEN 'Demande faible'
               ELSE 'Demande stable'
           END as demand_status
    FROM previsions p
    JOIN items i ON p.item_id = i.id
    WHERE p.date_prevision = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
    ORDER BY p.quantite_prevue DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// Statistiques de performance
$performance_stats = [
    'conversion_rate' => $pdo->query("
        SELECT ROUND(
            (COUNT(DISTINCT o.user_id) * 100.0 / NULLIF(COUNT(DISTINCT u.id), 0)), 2
        ) as rate
        FROM users u 
        LEFT JOIN orders o ON u.id = o.user_id 
        WHERE u.role = 'user'
    ")->fetchColumn() ?: 0,
    'repeat_customers' => $pdo->query("
        SELECT COUNT(*) FROM (
            SELECT user_id FROM orders GROUP BY user_id HAVING COUNT(*) > 1
        ) as repeat_users
    ")->fetchColumn(),
    'avg_items_per_order' => $pdo->query("
        SELECT COALESCE(AVG(total_items), 0) FROM (
            SELECT SUM(quantity) as total_items FROM order_details GROUP BY order_id
        ) as order_totals
    ")->fetchColumn()
];

// Notifications récentes par type
$notifications_by_type = $pdo->query("
    SELECT type, COUNT(*) as count, 
           SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
    FROM notifications 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    GROUP BY type
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

<!-- Dashboard-specific stylesheet (extracted from inline) -->
<link rel="stylesheet" href="../assets/css/admin/dashboard.css">

<main id="dashboardRoot" class="container-fluid professional-dashboard">
    <!-- Messages de feedback -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show professional-alert" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Header du Dashboard professionnel -->
    <div class="row mb-3">
        <div class="col-12">
            <div class="dashboard-header">
                <div class="header-content">
                    <div class="header-title">
                        <h1 class="h4 mb-1 fw-bold text-primary">
                            <i class="bi bi-speedometer2 me-2"></i>Dashboard Admin
                        </h1>
                        <p class="text-muted mb-0">
                            Aperçu complet de votre e-commerce - <?php echo date('d/m/Y H:i'); ?>
                        </p>
                    </div>
                    <div class="header-actions">
                        <!-- Alertes intégrées -->
                        <?php if ($critical_stock || $global_stats['pending_orders'] > 0): ?>
                        <div class="alert alert-warning border-0 shadow-sm professional-inline-alert">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <?php if ($critical_stock): ?>
                                <strong><?php echo count($critical_stock); ?></strong> produit(s) en stock critique
                            <?php endif; ?>
                            <?php if ($global_stats['pending_orders'] > 0): ?>
                                • <strong><?php echo $global_stats['pending_orders']; ?></strong> commande(s) en attente
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="action-buttons">
                            <button onclick="exportStats()" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-download me-1"></i>Export CSV
                            </button>
                            <button onclick="printDashboard()" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-printer me-1"></i>Imprimer
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === LAYOUT PROFESSIONNEL 2x3 === -->
    <!-- Première rangée : 3 cartes principales -->
    <div class="row mb-3">
        <!-- Statistiques Principales Détaillées -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card stats-card border-primary">
                <div class="card-header bg-gradient-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-graph-up me-2"></i>Statistiques Aujourd'hui
                        </h5>
                        <div class="dropdown">
                            <button type="button" class="btn btn-outline-light btn-sm me-1" onclick="exportStats()" title="Export CSV (Statistiques)">
                                <i class="bi bi-download"></i>
                            </button>
                            <button type="button" class="btn btn-outline-light btn-sm me-1" onclick="printDashboard()" title="Imprimer">
                                <i class="bi bi-printer"></i>
                            </button>
                            <a class="btn btn-outline-light btn-sm me-1" href="sales_history.php" title="Historique détaillé">
                                <i class="bi bi-file-earmark-text"></i>
                            </a>

                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="sales_history.php">Historique détaillé</a></li>
                                <li><a class="dropdown-item" href="list_orders.php">Voir commandes</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Grille des statistiques principales -->
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <div class="stat-card border-primary">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">Commandes</div>
                                        <div class="stat-number text-primary"><?php echo $today_stats['orders']; ?></div>
                                        <div class="stat-trend text-<?php echo calculatePercentageChange($today_stats['orders'], $yesterday_stats['orders']) >= 0 ? 'success' : 'danger'; ?>">
                                            <i class="bi bi-arrow-<?php echo calculatePercentageChange($today_stats['orders'], $yesterday_stats['orders']) >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo abs(calculatePercentageChange($today_stats['orders'], $yesterday_stats['orders'])); ?>%
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-primary text-white">
                                        <i class="bi bi-cart-check"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <div class="stat-card border-success">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">Revenus</div>
                                        <div class="stat-number text-success"><?php echo number_format($today_stats['revenue'], 0); ?>€</div>
                                        <div class="stat-trend text-<?php echo calculatePercentageChange($today_stats['revenue'], $yesterday_stats['revenue']) >= 0 ? 'success' : 'danger'; ?>">
                                            <i class="bi bi-arrow-<?php echo calculatePercentageChange($today_stats['revenue'], $yesterday_stats['revenue']) >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo abs(calculatePercentageChange($today_stats['revenue'], $yesterday_stats['revenue'])); ?>%
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-success text-white">
                                        <i class="bi bi-currency-euro"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <div class="stat-card border-info">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">Utilisateurs</div>
                                        <div class="stat-number text-info"><?php echo $today_stats['new_users']; ?></div>
                                        <div class="stat-trend text-<?php echo calculatePercentageChange($today_stats['new_users'], $yesterday_stats['new_users']) >= 0 ? 'success' : 'danger'; ?>">
                                            <i class="bi bi-arrow-<?php echo calculatePercentageChange($today_stats['new_users'], $yesterday_stats['new_users']) >= 0 ? 'up' : 'down'; ?>"></i>
                                            <?php echo abs(calculatePercentageChange($today_stats['new_users'], $yesterday_stats['new_users'])); ?>%
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-info text-white">
                                        <i class="bi bi-people"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-6">
                            <div class="stat-card border-warning">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="stat-label">Produits vendus</div>
                                        <div class="stat-number text-warning"><?php echo $today_stats['products_sold']; ?></div>
                                        <div class="stat-trend text-muted">
                                            <i class="bi bi-box-seam"></i>
                                            Total
                                        </div>
                                    </div>
                                    <div class="stat-icon bg-warning text-white">
                                        <i class="bi bi-box"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Résumé des performances -->
                    <div class="performance-summary">
                        <h6 class="fw-bold text-primary mb-2">
                            <i class="bi bi-bullseye me-1"></i>Performances Clés
                        </h6>
                        <div class="row g-2 text-center">
                            <div class="col-4">
                                <div class="small text-muted">Panier moyen</div>
                                <div class="fw-bold text-primary"><?php echo number_format($avg_order_today, 0); ?>€</div>
                            </div>
                            <div class="col-4">
                                <div class="small text-muted">Conv. Rate</div>
                                <div class="fw-bold text-success"><?php echo $performance_stats['conversion_rate']; ?>%</div>
                            </div>
                            <div class="col-4">
                                <div class="small text-muted">Articles/Cmd</div>
                                <div class="fw-bold text-info"><?php echo number_format($performance_stats['avg_items_per_order'], 1); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Indicateurs de performance -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="row g-2 text-center">
                            <div class="col-6">
                                <div class="small text-muted">Cette semaine</div>
                                <div class="fw-bold"><?php echo $week_stats['orders']; ?> commandes</div>
                                <div class="small text-success"><?php echo number_format($week_stats['revenue'], 0); ?>€</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Ce mois</div>
                                <div class="fw-bold"><?php echo $month_stats['orders']; ?> commandes</div>
                                <div class="small text-success"><?php echo number_format($month_stats['revenue'], 0); ?>€</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Évolution des Ventes Détaillée -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card chart-card sales-card border-secondary">
                <div class="card-header bg-gradient-secondary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-graph-up-arrow me-2"></i>Évolution des Ventes (6 mois)
                        </h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn btn-outline-light active" onclick="toggleSalesChart('quantity', this)">Qté</button>
                            <button type="button" class="btn btn-outline-light" onclick="toggleSalesChart('revenue', this)">€</button>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="salesChart" height="180"></canvas>
                    
                    <!-- Données détaillées sur les ventes -->
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold mb-2">
                            <i class="bi bi-bar-chart me-1"></i>Tendances & Insights
                        </h6>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="small text-muted">Mois le + fort</div>
                                <?php 
                                $best_month = !empty($sales_data) ? max($sales_data) : ['revenue' => 0, 'label' => 'N/A'];
                                ?>
                                <div class="fw-bold text-success"><?php echo $best_month['label'] ?? 'N/A'; ?></div>
                                <div class="small"><?php echo number_format($best_month['revenue'] ?? 0, 0); ?>€</div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Croissance</div>
                                <?php 
                                $growth = 0;
                                if (count($sales_data) >= 2) {
                                    $current = $sales_data[count($sales_data)-1]['revenue'];
                                    $previous = $sales_data[count($sales_data)-2]['revenue'];
                                    $growth = calculatePercentageChange($current, $previous);
                                }
                                ?>
                                <div class="fw-bold text-<?php echo $growth >= 0 ? 'success' : 'danger'; ?>">
                                    <?php echo abs($growth); ?>%
                                </div>
                                <div class="small">
                                    <i class="bi bi-arrow-<?php echo $growth >= 0 ? 'up' : 'down'; ?>"></i>
                                    vs mois dernier
                                </div>
                            </div>
                        </div>
                        
                        <?php if (!empty($sales_data)): ?>
                        <div class="row g-2 mt-2">
                            <div class="col-12">
                                <div class="small text-muted">Clients uniques (6 mois)</div>
                                <div class="fw-bold text-primary">
                                    <?php echo array_sum(array_column($sales_data, 'unique_customers')); ?> clients
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gestion Stock Avancée -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card chart-card stock-card border-dark">
                <div class="card-header bg-gradient-dark text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-boxes me-2"></i>Gestion Stock
                        </h5>
                        <a href="bulk_update_products.php" class="btn btn-outline-light btn-sm">Gérer</a>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="stockChart" height="140"></canvas>
                    
                    <!-- Alertes stock critique -->
                    <div class="mt-3 pt-3 border-top">
                        <h6 class="fw-bold text-danger mb-2">
                            <i class="bi bi-exclamation-triangle me-1"></i>Alertes Stock Critique
                        </h6>
                        <?php if ($critical_stock): ?>
                            <div class="stock-alerts">
                                <?php foreach ($critical_stock as $item): ?>
                                <div class="alert alert-sm alert-danger d-flex justify-content-between align-items-center mb-2">
                                    <div>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($item['category']); ?></small>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-danger"><?php echo $item['stock']; ?></span>
                                        <br><small class="text-muted">Seuil: <?php echo $item['stock_alert_threshold']; ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-success">
                                <i class="bi bi-check-circle display-6"></i>
                                <p class="mb-0">Aucune alerte stock</p>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Statistiques globales stock -->
                        <div class="row g-2 mt-3 pt-2 border-top">
                            <div class="col-6">
                                <div class="small text-muted">Total produits</div>
                                <div class="fw-bold text-primary"><?php echo $global_stats['total_products']; ?></div>
                            </div>
                            <div class="col-6">
                                <div class="small text-muted">Stock faible</div>
                                <div class="fw-bold text-warning"><?php echo $global_stats['low_stock_count']; ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Deuxième rangée : 3 cartes analytiques -->
    <div class="row">
        <!-- Prédictions IA Avancées - VERSION OPTIMISÉE -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card ai-card border-success">
                <div class="card-header bg-gradient-success text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-robot me-2"></i>Prédictions IA
                        </h5>
                        <a href="prediction.php" class="btn btn-outline-light btn-sm">Configurer</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($previsions_data): ?>
                        <!-- Graphique compact -->
                        <div class="prediction-chart-container">
                            <canvas id="previsionChart"></canvas>
                        </div>
                        
                        <!-- Détails des prédictions -->
                        <div class="card-section">
                            <h6 class="fw-bold text-success mb-2">
                                <i class="bi bi-lightbulb me-1"></i>Prédictions Mois Prochain
                            </h6>
                            <div class="prediction-details">
                                <?php foreach (array_slice($previsions_data, 0, 4) as $pred): ?>
                                <div class="d-flex justify-content-between align-items-center prediction-item">
                                    <div style="min-width: 0; flex: 1;">
                                        <strong class="text-truncate d-block" style="max-width: 100px; font-size: 0.85rem;">
                                            <?php echo htmlspecialchars($pred['name']); ?>
                                        </strong>
                                        <small class="text-muted" style="font-size: 0.7rem;">
                                            <?php echo $pred['demand_status']; ?>
                                        </small>
                                    </div>
                                    <div class="text-end" style="flex-shrink: 0;">
                                        <span class="badge bg-success" style="font-size: 0.7rem;">
                                            <?php echo $pred['quantite_prevue']; ?>
                                        </span>
                                        <br><small class="text-muted" style="font-size: 0.65rem;">
                                            Stock: <?php echo $pred['stock']; ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- Recommandations compactes -->
                        <div class="card-section">
                            <h6 class="fw-bold text-primary mb-2" style="font-size: 0.9rem;">
                                <i class="bi bi-cpu me-1"></i>Recommandations
                            </h6>
                            <div class="prediction-recommendations">
                                <ul class="list-unstyled mb-0" style="font-size: 0.8rem;">
                                    <?php 
                                    $high_demand = array_filter($previsions_data, function($p) { return $p['demand_status'] === 'Forte demande'; });
                                    if ($high_demand): ?>
                                    <li class="text-warning mb-1">
                                        <i class="bi bi-arrow-up me-1"></i>Réappro. <?php echo count($high_demand); ?> produit(s)
                                    </li>
                                    <?php endif; ?>
                                    <li class="text-info">
                                        <i class="bi bi-graph-up me-1"></i><?php echo count($previsions_data); ?> produits analysés
                                    </li>
                                </ul>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-robot display-4 text-muted"></i>
                            <h6 class="text-muted">Aucune prédiction disponible</h6>
                            <p class="text-muted small">Générez des prédictions IA pour optimiser votre stock</p>
                            <a href="prediction.php" class="btn btn-outline-primary btn-sm">Générer maintenant</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Commandes Récentes - VERSION OPTIMISÉE -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card orders-card-new orders-card border-info">
                <div class="card-header bg-gradient-info text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-receipt me-2"></i>Commandes Récentes
                        </h5>
                        <a href="list_orders.php" class="btn btn-outline-light btn-sm">Toutes</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($recent_orders): ?>
                        <!-- Table des commandes avec scroll -->
                        <div class="orders-table-wrapper">
                            <table class="table table-sm orders-table mb-0">
                                <thead class="orders-table-head">
                                    <tr>
                                        <th>ID</th>
                                        <th>Client</th>
                                        <th>Total</th>
                                        <th>Statut</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody class="orders-table-body">
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr class="order-row-new">
                                        <td class="order-id">
                                            <strong>#<?php echo $order['id']; ?></strong>
                                        </td>
                                        <td class="order-client">
                                            <div class="client-info">
                                                <div class="client-name"><?php echo htmlspecialchars(substr($order['user_name'], 0, 12)); ?></div>
                                                <div class="client-email"><?php echo htmlspecialchars(substr($order['email'], 0, 18)); ?></div>
                                            </div>
                                        </td>
                                        <td class="order-total">
                                            <div class="total-amount"><?php echo number_format($order['total_price'], 0); ?>€</div>
                                            <div class="total-items"><?php echo $order['total_items']; ?> art.</div>
                                        </td>
                                        <td class="order-status">
                                            <?php
                                            $status_config = [
                                                'pending' => ['class' => 'warning', 'text' => 'Pending'],
                                                'shipped' => ['class' => 'info', 'text' => 'Shipped'],
                                                'delivered' => ['class' => 'success', 'text' => 'Delivered'],
                                                'cancelled' => ['class' => 'danger', 'text' => 'Cancelled']
                                            ];
                                            $config = $status_config[$order['status']] ?? ['class' => 'secondary', 'text' => 'Unknown'];
                                            ?>
                                            <span class="badge status-badge bg-<?php echo $config['class']; ?>">
                                                <?php echo $config['text']; ?>
                                            </span>
                                        </td>
                                        <td class="order-date">
                                            <div class="date-formatted"><?php echo $order['formatted_date']; ?></div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Résumé compact en bas -->
                        <div class="orders-summary">
                            <div class="summary-grid">
                                <div class="summary-item">
                                    <span class="summary-label">Attente</span>
                                    <span class="summary-value text-warning"><?php echo $global_stats['pending_orders']; ?></span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Expédiées</span>
                                    <span class="summary-value text-info"><?php echo $global_stats['shipped_orders']; ?></span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Livrées</span>
                                    <span class="summary-value text-success"><?php echo $global_stats['delivered_orders']; ?></span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Annulées</span>
                                    <span class="summary-value text-danger"><?php echo $global_stats['cancelled_orders']; ?></span>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="orders-empty">
                            <i class="bi bi-receipt display-4 text-muted"></i>
                            <h6 class="text-muted mt-2">Aucune commande récente</h6>
                            <p class="text-muted small">Les nouvelles commandes apparaîtront ici</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Produits & Analytics -->
        <div class="col-xl-4 col-lg-4 mb-3">
            <div class="card professional-card products-card border-warning">
                <div class="card-header bg-gradient-warning text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-white d-flex align-items-center">
                            <i class="bi bi-trophy me-2"></i>Top Produits (30j)
                        </h5>
                        <a href="list_products.php" class="btn btn-outline-light btn-sm">Catalogue</a>
                    </div>
                </div>
                <div class="card-body">
                    <?php if ($top_products): ?>
                        <div class="top-products-list" style="max-height: 250px; overflow-y: auto;">
                            <?php foreach ($top_products as $index => $product): ?>
                            <div class="d-flex justify-content-between align-items-center product-item p-2 mb-2">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-primary rank-badge me-2">#{<?php echo $index + 1; ?>}</span>
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars(substr($product['name'], 0, 25)); ?>...</div>
                                        <small class="text-muted"><?php echo htmlspecialchars($product['category']); ?> • <?php echo number_format($product['price'], 2); ?>€</small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="fw-bold text-success"><?php echo $product['total_sold']; ?> vendus</div>
                                    <small class="text-muted"><?php echo number_format($product['revenue'], 0); ?>€ CA</small>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Analyse des performances produits -->
                        <div class="mt-3 pt-3 border-top">
                            <h6 class="fw-bold mb-2">
                                <i class="bi bi-graph-up me-1"></i>Performance Globale
                            </h6>
                            <div class="row g-2 text-center">
                                <div class="col-4">
                                    <div class="small text-muted">CA Top 5</div>
                                    <div class="fw-bold text-success">
                                        <?php echo number_format(array_sum(array_column($top_products, 'revenue')), 0); ?>€
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">Clients uniques</div>
                                    <div class="fw-bold text-info">
                                        <?php echo array_sum(array_column($top_products, 'unique_customers')); ?>
                                    </div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">Catégories</div>
                                    <div class="fw-bold text-primary"><?php echo $global_stats['active_categories']; ?></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notifications système -->
                        <?php if ($notifications_by_type): ?>
                        <div class="mt-3 pt-3 border-top">
                            <h6 class="fw-bold mb-2">
                                <i class="bi bi-bell me-1"></i>Notifications (7j)
                            </h6>
                            <?php foreach (array_slice($notifications_by_type, 0, 3) as $notif): ?>
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <small class="text-muted"><?php echo ucfirst($notif['type']); ?></small>
                                <div>
                                    <span class="badge bg-secondary"><?php echo $notif['count']; ?></span>
                                    <?php if ($notif['unread_count'] > 0): ?>
                                    <span class="badge bg-danger"><?php echo $notif['unread_count']; ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div class="mt-2">
                                <a href="notifications.php" class="btn btn-outline-primary btn-sm w-100">
                                    Voir toutes les notifications
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-graph-up display-4 text-muted"></i>
                            <h6 class="text-muted">Aucune vente récente</h6>
                            <p class="text-muted small">Les produits populaires apparaîtront ici</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- Scripts pour les graphiques -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Configuration globale des graphiques
Chart.defaults.font.family = 'Inter, system-ui, sans-serif';
Chart.defaults.color = '#6c757d';

// Variables globales pour les données
const salesData = <?php echo json_encode($sales_data); ?>;
const stockData = <?php echo json_encode($stock_data); ?>;
const predictionsData = <?php echo json_encode($previsions_data); ?>;

// Graphique des ventes
let salesChart = null;
let currentSalesView = 'quantity';

function initSalesChart() {
    const ctx = document.getElementById('salesChart');
    if (!ctx) return;
    
    const labels = salesData.map(item => item.label || 'N/A');
    const quantityData = salesData.map(item => parseInt(item.total) || 0);
    const revenueData = salesData.map(item => Math.round((parseFloat(item.revenue) || 0))) ; // arrondir pour afficher
    const maxValue = Math.max(...quantityData, ...revenueData, 1);
    const step = Math.max(1, Math.ceil(maxValue / 5));
    const suggestedMax = Math.ceil(maxValue * 1.15 / step) * step;
    
    // initial dataset = quantité
    salesChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Quantités vendues',
                data: quantityData,
                borderColor: '#007bff',
                backgroundColor: 'rgba(0, 123, 255, 0.1)',
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#007bff',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2,
                pointRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0,0,0,0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    callbacks: {
                        title: function(items) {
                            return items[0].label || '';
                        },
                        label: function(context) {
                            const val = context.parsed.y;
                            if (currentSalesView === 'revenue') {
                                return context.dataset.label + ': ' + new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(val);
                            }
                            return context.dataset.label + ': ' + val + ' pcs';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    suggestedMax: suggestedMax,
                    ticks: {
                        stepSize: step,
                        font: { size: 11 },
                        callback: function(value) {
                            if (currentSalesView === 'revenue') {
                                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(value);
                            }
                            return value;
                        }
                    },
                    title: {
                        display: true,
                        text: currentSalesView === 'revenue' ? 'Revenus (€)' : 'Quantité (pcs)',
                        color: '#6c757d',
                        font: { size: 12 }
                    },
                    grid: { color: 'rgba(0,0,0,0.05)' }
                }
            },
            elements: { point: { hoverRadius: 8 } }
        }
    });
}

function toggleSalesChart(view, btnElem) {
    if (!salesChart) return;
    currentSalesView = view;
    
    // basculer active class
    document.querySelectorAll('.btn-group button').forEach(btn => btn.classList.remove('active'));
    if (btnElem) btnElem.classList.add('active');
    
    const quantityData = salesData.map(item => parseInt(item.total) || 0);
    const revenueData = salesData.map(item => Math.round((parseFloat(item.revenue) || 0)));
    
    if (view === 'quantity') {
        salesChart.data.datasets[0].data = quantityData;
        salesChart.data.datasets[0].label = 'Quantités vendues';
        salesChart.data.datasets[0].borderColor = '#007bff';
        salesChart.data.datasets[0].backgroundColor = 'rgba(0, 123, 255, 0.1)';
    } else {
        salesChart.data.datasets[0].data = revenueData;
        salesChart.data.datasets[0].label = 'Revenus';
        salesChart.data.datasets[0].borderColor = '#28a745';
        salesChart.data.datasets[0].backgroundColor = 'rgba(40, 167, 69, 0.1)';
    }
    
    // recalculer limites et step
    const maxValue = Math.max(...salesChart.data.datasets[0].data, 1);
    const step = Math.max(1, Math.ceil(maxValue / 5));
    const suggestedMax = Math.ceil(maxValue * 1.15 / step) * step;
    salesChart.options.scales.y.suggestedMax = suggestedMax;
    salesChart.options.scales.y.ticks.stepSize = step;
    salesChart.options.scales.y.title.text = view === 'revenue' ? 'Revenus (€)' : 'Quantité (pcs)';
    
    salesChart.update('active');
}

// Graphique des stocks
function initStockChart() {
    const ctx = document.getElementById('stockChart');
    if (!ctx || !stockData || stockData.length === 0) return;
    
    const labels = stockData.map(item => item.name.substring(0, 10) + '...');
    const stockValues = stockData.map(item => parseInt(item.stock) || 0);
    const thresholds = stockData.map(item => parseInt(item.stock_alert_threshold) || 5);
    
    // Couleurs basées sur le statut du stock
    const backgroundColors = stockData.map(item => {
        if (item.status_stock === 'Rupture') return 'rgba(220, 53, 69, 0.8)';
        if (item.status_stock === 'Critique') return 'rgba(255, 193, 7, 0.8)';
        if (item.status_stock === 'Faible') return 'rgba(255, 152, 0, 0.8)';
        return 'rgba(40, 167, 69, 0.8)';
    });
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Stock actuel',
                data: stockValues,
                backgroundColor: backgroundColors,
                borderColor: backgroundColors.map(color => color.replace('0.8', '1')),
                borderWidth: 1,
                borderRadius: 4,
                borderSkipped: false
            }, {
                label: 'Seuil alerte',
                data: thresholds,
                type: 'line',
                borderColor: '#dc3545',
                borderWidth: 2,
                borderDash: [5, 5],
                fill: false,
                pointBackgroundColor: '#dc3545',
                pointBorderColor: '#dc3545',
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 11
                        }
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    callbacks: {
                        afterLabel: function(context) {
                            if (context.datasetIndex === 0) {
                                const status = stockData[context.dataIndex].status_stock;
                                return 'Statut: ' + status;
                            }
                            return '';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 10
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        font: {
                            size: 10
                        }
                    }
                }
            }
        }
    });
}

// Graphique des prédictions
function initPredictionChart() {
    const ctx = document.getElementById('previsionChart');
    if (!ctx || !predictionsData || predictionsData.length === 0) return;
    
    // Limiter les labels pour éviter l'étirement
    const labels = predictionsData.map(item => {
        const name = item.name || 'Produit';
        return name.length > 8 ? name.substring(0, 8) + '...' : name;
    });
    const predictedData = predictionsData.map(item => parseInt(item.quantite_prevue) || 0);
    const currentStock = predictionsData.map(item => parseInt(item.stock) || 0);
    
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Stock actuel',
                data: currentStock,
                backgroundColor: 'rgba(108, 117, 125, 0.7)',
                borderColor: 'rgba(108, 117, 125, 1)',
                borderWidth: 1
            }, {
                label: 'Prédiction',
                data: predictedData,
                backgroundColor: 'rgba(40, 167, 69, 0.7)',
                borderColor: 'rgba(40, 167, 69, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        font: {
                            size: 10
                        },
                        boxWidth: 12
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#ffffff',
                    bodyColor: '#ffffff',
                    titleFont: { size: 11 },
                    bodyFont: { size: 10 },
                    callbacks: {
                        afterLabel: function(context) {
                            if (context.datasetIndex === 1) {
                                const item = predictionsData[context.dataIndex];
                                return 'Demande: ' + item.demand_status;
                            }
                            return '';
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 9
                        },
                        maxRotation: 45,
                        minRotation: 0
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        font: {
                            size: 9
                        }
                    }
                }
            },
            layout: {
                padding: {
                    top: 5,
                    bottom: 5
                }
            }
        }
    });
}

// Fonctions utilitaires
function exportStats() {
    // Récupération des données principales
    const today = new Date().toISOString().split('T')[0];
    const stats = [
        ['Type', 'Valeur', 'Date'],
        ['Commandes aujourd\'hui', '<?php echo $today_stats['orders']; ?>', today],
        ['Revenus aujourd\'hui', '<?php echo $today_stats['revenue']; ?>€', today],
        ['Nouveaux utilisateurs', '<?php echo $today_stats['new_users']; ?>', today],
        ['Produits vendus', '<?php echo $today_stats['products_sold']; ?>', today],
        ['Total produits', '<?php echo $global_stats['total_products']; ?>', today],
        ['Total utilisateurs', '<?php echo $global_stats['total_users']; ?>', today],
        ['Stock critique', '<?php echo $global_stats['low_stock_count']; ?>', today],
        ['Commandes en attente', '<?php echo $global_stats['pending_orders']; ?>', today]
    ];
    
    // Conversion en CSV
    const csvContent = stats.map(row => row.join(',')).join('\n');
    
    // Téléchargement
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', `dashboard_stats_${today}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Notification de succès
    showSuccessToast('Statistiques exportées avec succès !');
}

function printDashboard() {
    // Configuration d'impression
    const printCSS = `
        <style>
            @media print {
                .professional-dashboard { padding: 0; }
                .btn, .dropdown { display: none !important; }
                .professional-card { 
                    break-inside: avoid; 
                    box-shadow: none; 
                    border: 1px solid #ddd;
                    margin-bottom: 1rem;
                }
                .chart-card canvas { max-height: 300px !important; }
            }
        </style>
    `;
    
    // Ajout du CSS d'impression
    const head = document.getElementsByTagName('head')[0];
    const style = document.createElement('style');
    style.innerHTML = printCSS;
    head.appendChild(style);
    
    // Impression
    window.print();
    
    // Suppression du CSS après impression
    setTimeout(() => {
        head.removeChild(style);
    }, 1000);
}

// Notifications toast
function showSuccessToast(message) {
    // Création du toast
    const toast = document.createElement('div');
    toast.className = 'toast show position-fixed top-0 end-0 m-3';
    toast.style.zIndex = '9999';
    toast.innerHTML = `
        <div class="toast-header bg-success text-white">
            <i class="bi bi-check-circle me-2"></i>
            <strong class="me-auto">Succès</strong>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
        </div>
        <div class="toast-body">${message}</div>
    `;
    
    document.body.appendChild(toast);
    
    // Suppression automatique après 3 secondes
    setTimeout(() => {
        if (toast.parentNode) {
            toast.parentNode.removeChild(toast);
        }
    }, 3000);
}

// Actualisation automatique des données (toutes les 5 minutes)
function autoRefreshData() {
    setInterval(() => {
        // Actualisation silencieuse des statistiques via AJAX
        fetch(window.location.href, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.text())
        .then(html => {
            // Mise à jour des éléments dynamiques
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            
            // Mise à jour des badges de notifications
            const notifBadges = document.querySelectorAll('[data-badge="notif"]');
            const newNotifBadges = doc.querySelectorAll('[data-badge="notif"]');
            
            notifBadges.forEach((badge, index) => {
                if (newNotifBadges[index]) {
                    badge.textContent = newNotifBadges[index].textContent;
                }
            });
            
            console.log('🔄 Données actualisées automatiquement');
        })
        .catch(error => {
            console.warn('⚠️ Erreur lors de l\'actualisation automatique:', error);
        });
    }, 300000); // 5 minutes
}

// Initialisation au chargement de la page
document.addEventListener('DOMContentLoaded', function() {
    console.log('🚀 Dashboard professionnel chargé');
    
    // Initialisation des graphiques
    initSalesChart();
    initStockChart();
    initPredictionChart();
    
    // Démarrage de l'actualisation automatique
    autoRefreshData();
    
    // Gestion des erreurs de graphiques
    window.addEventListener('error', function(e) {
        if (e.message.includes('Chart')) {
            console.warn('⚠️ Erreur de graphique:', e.message);
        }
    });
    
    // Animation d'entrée pour les cartes
    const cards = document.querySelectorAll('.professional-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
    });
    
    // Tooltips Bootstrap
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    console.log('✅ Dashboard entièrement initialisé');
});

// Gestion de la visibilité de la page (pour économiser les ressources)
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        console.log('🔇 Dashboard mis en veille');
        // Arrêter les animations coûteuses
    } else {
        console.log('🔊 Dashboard réactivé');
        // Reprendre les animations
    }
});

// Raccourcis clavier
document.addEventListener('keydown', function(e) {
    // Ctrl + R : actualisation manuelle
    if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        location.reload();
    }
    
    // Ctrl + P : impression
    if (e.ctrlKey && e.key === 'p') {
        e.preventDefault();
        printDashboard();
    }
    
    // Ctrl + E : export
    if (e.ctrlKey && e.key === 'e') {
        e.preventDefault();
        exportStats();
    }
});

</script>

<?php include 'includes/footer.php'; ?>
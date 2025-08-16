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

// Calcul des pourcentages de variation
function calculatePercentageChange($current, $previous) {
    if ($previous == 0) return $current > 0 ? 100 : 0;
    return round((($current - $previous) / $previous) * 100, 1);
}

// Statistiques globales
$global_stats = [
    'total_products' => $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn(),
    'total_users' => $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn(),
    'total_orders' => $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
    'total_revenue' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders")->fetchColumn(),
    'low_stock_count' => $pdo->query("SELECT COUNT(*) FROM items WHERE stock <= stock_alert_threshold")->fetchColumn(),
    'pending_orders' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn(),
    'unread_notifications' => $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn()
];

// Alerte stock critique
$critical_stock = $pdo->query("SELECT name, stock FROM items WHERE stock = 0 OR stock <= 2 ORDER BY stock ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

// Top 5 produits les plus vendus (30 derniers jours)
$top_products = $pdo->query("
    SELECT i.name, SUM(od.quantity) as total_sold, SUM(od.quantity * od.price) as revenue
    FROM order_details od 
    JOIN items i ON od.item_id = i.id 
    JOIN orders o ON od.order_id = o.id 
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY i.id 
    ORDER BY total_sold DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Commandes récentes
$recent_orders = $pdo->query("
    SELECT o.id, u.name as user_name, o.total_price, o.status, o.order_date
    FROM orders o 
    JOIN users u ON o.user_id = u.id 
    ORDER BY o.order_date DESC 
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Données pour l'évolution du stock
$stock_data = $pdo->query("SELECT name, stock FROM items ORDER BY name LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// Données pour les ventes par mois (12 derniers mois)
$sales_data = array_reverse($pdo->query("
    SELECT DATE_FORMAT(o.order_date, '%Y-%m') as period, 
           DATE_FORMAT(o.order_date, '%M %Y') as label,
           SUM(od.quantity) as total,
           SUM(o.total_price) as revenue
    FROM order_details od
    JOIN orders o ON od.order_id = o.id
    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY period
    ORDER BY period DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC));

// Données pour les prévisions
$previsions_data = $pdo->query("
    SELECT i.name, p.quantite_prevue
    FROM previsions p
    JOIN items i ON p.item_id = i.id
    WHERE p.date_prevision = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

<main class="container-fluid py-4">
    <!-- Messages de feedback -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?php echo $_SESSION['success_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Header du Dashboard -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h2 mb-1">🏪 Dashboard Admin</h1>
                    <p class="text-muted mb-0">
                        Bienvenue, <strong><?php echo htmlspecialchars($_SESSION['admin_name']); ?></strong> • 
                        <i class="bi bi-calendar3 me-1"></i><?php echo date('d/m/Y H:i'); ?>
                    </p>
                </div>
                <div>
                    <button class="btn btn-outline-primary me-2" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise me-1"></i>Actualiser
                    </button>
                    <a href="notifications.php" class="btn btn-primary position-relative">
                        <i class="bi bi-bell me-1"></i>Notifications
                        <?php if ($global_stats['unread_notifications'] > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                <?php echo $global_stats['unread_notifications']; ?>
                            </span>
                        <?php endif; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- === STATISTIQUES PRINCIPALES === -->
    <div class="row mb-4">
        <!-- Commandes Aujourd'hui -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-primary stats-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="card-subtitle mb-2 text-primary">Commandes Aujourd'hui</h6>
                            <h2 class="card-title mb-1"><?php echo $today_stats['orders']; ?></h2>
                            <?php $orders_change = calculatePercentageChange($today_stats['orders'], $yesterday_stats['orders']); ?>
                            <small class="text-<?php echo $orders_change >= 0 ? 'success' : 'danger'; ?>">
                                <i class="bi bi-arrow-<?php echo $orders_change >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($orders_change); ?>% vs hier
                            </small>
                        </div>
                        <div class="stats-icon bg-primary">
                            <i class="bi bi-cart-check text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Revenus Aujourd'hui -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-success stats-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="card-subtitle mb-2 text-success">Revenus Aujourd'hui</h6>
                            <h2 class="card-title mb-1"><?php echo number_format($today_stats['revenue'], 2); ?>€</h2>
                            <?php $revenue_change = calculatePercentageChange($today_stats['revenue'], $yesterday_stats['revenue']); ?>
                            <small class="text-<?php echo $revenue_change >= 0 ? 'success' : 'danger'; ?>">
                                <i class="bi bi-arrow-<?php echo $revenue_change >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($revenue_change); ?>% vs hier
                            </small>
                        </div>
                        <div class="stats-icon bg-success">
                            <i class="bi bi-currency-euro text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Nouveaux Utilisateurs -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-info stats-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="card-subtitle mb-2 text-info">Nouveaux Utilisateurs</h6>
                            <h2 class="card-title mb-1"><?php echo $today_stats['new_users']; ?></h2>
                            <?php $users_change = calculatePercentageChange($today_stats['new_users'], $yesterday_stats['new_users']); ?>
                            <small class="text-<?php echo $users_change >= 0 ? 'success' : 'danger'; ?>">
                                <i class="bi bi-arrow-<?php echo $users_change >= 0 ? 'up' : 'down'; ?>"></i>
                                <?php echo abs($users_change); ?>% vs hier
                            </small>
                        </div>
                        <div class="stats-icon bg-info">
                            <i class="bi bi-person-plus text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Produits Vendus -->
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-warning stats-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <h6 class="card-subtitle mb-2 text-warning">Produits Vendus</h6>
                            <h2 class="card-title mb-1"><?php echo $today_stats['products_sold']; ?></h2>
                            <small class="text-muted">
                                <i class="bi bi-box"></i>
                                Aujourd'hui
                            </small>
                        </div>
                        <div class="stats-icon bg-warning">
                            <i class="bi bi-graph-up text-white"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === ALERTES ET NOTIFICATIONS === -->
    <?php if ($critical_stock || $global_stats['pending_orders'] > 0): ?>
    <div class="row mb-4">
        <div class="col-12">
            <div class="alert alert-warning border-0 shadow-sm">
                <div class="d-flex align-items-center">
                    <i class="bi bi-exclamation-triangle-fill me-3 fs-4"></i>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading mb-1">Attention requise</h5>
                        <div class="row">
                            <?php if ($critical_stock): ?>
                            <div class="col-md-6 mb-2">
                                <strong>Stock critique :</strong> 
                                <?php foreach ($critical_stock as $item): ?>
                                    <span class="badge bg-danger me-1"><?php echo htmlspecialchars($item['name']); ?> (<?php echo $item['stock']; ?>)</span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($global_stats['pending_orders'] > 0): ?>
                            <div class="col-md-6 mb-2">
                                <strong>Commandes en attente :</strong> 
                                <span class="badge bg-warning"><?php echo $global_stats['pending_orders']; ?> commande(s)</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <a href="notifications.php" class="btn btn-warning">
                            <i class="bi bi-arrow-right me-1"></i>Voir détails
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- === GRAPHIQUES === -->
    <div class="row mb-4">
        <!-- Évolution des Ventes -->
        <div class="col-xl-8 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-graph-up me-2"></i>Évolution des Ventes (12 mois)</h5>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-primary active" onclick="toggleSalesChart('quantity')">Quantité</button>
                        <button type="button" class="btn btn-outline-primary" onclick="toggleSalesChart('revenue')">Revenus</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="salesChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Stock par Produit -->
        <div class="col-xl-4 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-transparent">
                    <h5 class="mb-0"><i class="bi bi-boxes me-2"></i>Stock par Produit</h5>
                </div>
                <div class="card-body">
                    <canvas id="stockChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- === PRÉDICTIONS IA & TOP PRODUITS === -->
    <div class="row mb-4">
        <!-- Prédictions IA -->
        <div class="col-xl-6 mb-4">
            <div class="card h-100 shadow-sm border-success">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-robot me-2"></i>Prédictions IA - Mois Prochain</h5>
                </div>
                <div class="card-body" style="height:320px;display:flex;align-items:center;justify-content:center;">
                    <?php if ($previsions_data): ?>
                        <canvas id="previsionChart" style="max-height:260px;max-width:100%;"></canvas>
                        <div class="mt-3">
                            <small class="text-muted">
                                <i class="bi bi-info-circle me-1"></i>
                                Algorithme de régression linéaire basé sur l'historique des ventes
                            </small>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="bi bi-gear text-muted" style="font-size: 3rem;"></i>
                            <p class="mt-3 text-muted">Prédictions en cours de génération...</p>
                            <a href="prediction.php" class="btn btn-success">
                                <i class="bi bi-arrow-right me-1"></i>Générer prédictions
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top Produits -->
        <div class="col-xl-6 mb-4">
            <div class="card h-100 shadow-sm">
                <div class="card-header bg-transparent">
                    <h5 class="mb-0"><i class="bi bi-trophy me-2"></i>Top Produits (30 derniers jours)</h5>
                </div>
                <div class="card-body">
                    <?php if ($top_products): ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($top_products as $index => $product): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center px-0">
                                <div class="d-flex align-items-center">
                                    <span class="badge bg-primary rounded-pill me-3"><?php echo $index + 1; ?></span>
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                        <small class="text-muted"><?php echo $product['total_sold']; ?> vendus</small>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <strong class="text-success"><?php echo number_format($product['revenue'], 2); ?>€</strong>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-graph-down" style="font-size: 2rem;"></i>
                            <p class="mt-2">Aucune vente récente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- === COMMANDES RÉCENTES & ACTIONS RAPIDES === -->
    <div class="row mb-4">
        <!-- Commandes Récentes -->
        <div class="col-xl-8 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Commandes Récentes</h5>
                    <a href="list_orders.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-right me-1"></i>Voir tout
                    </a>
                </div>
                <div class="card-body p-0">
                    <?php if ($recent_orders): ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Commande</th>
                                        <th>Client</th>
                                        <th>Montant</th>
                                        <th>Statut</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_orders as $order): ?>
                                    <tr>
                                        <td><span class="fw-bold">#<?php echo $order['id']; ?></span></td>
                                        <td><?php echo htmlspecialchars($order['user_name']); ?></td>
                                        <td><strong><?php echo number_format($order['total_price'], 2); ?>€</strong></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($order['status']) {
                                                    'pending' => 'warning',
                                                    'shipped' => 'info',
                                                    'delivered' => 'success',
                                                    'cancelled' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($order['status']); ?>
                                            </span>
                                        </td>
                                        <td><small><?php echo date('d/m H:i', strtotime($order['order_date'])); ?></small></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4 text-muted">
                            <i class="bi bi-cart-x" style="font-size: 2rem;"></i>
                            <p class="mt-2">Aucune commande récente</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Actions Rapides -->
        <div class="col-xl-4 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-transparent">
                    <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>Actions Rapides</h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="list_products.php" class="btn btn-outline-primary btn-lg">
                            <i class="bi bi-box me-2"></i>Gérer Produits
                            <span class="badge bg-primary ms-auto"><?php echo $global_stats['total_products']; ?></span>
                        </a>
                        <a href="list_users.php" class="btn btn-outline-info btn-lg">
                            <i class="bi bi-people me-2"></i>Gérer Utilisateurs
                            <span class="badge bg-info ms-auto"><?php echo $global_stats['total_users']; ?></span>
                        </a>
                        <a href="list_orders.php" class="btn btn-outline-warning btn-lg">
                            <i class="bi bi-cart me-2"></i>Gérer Commandes
                            <span class="badge bg-warning ms-auto"><?php echo $global_stats['total_orders']; ?></span>
                        </a>
                        <a href="sales_history.php" class="btn btn-outline-success btn-lg">
                            <i class="bi bi-graph-up me-2"></i>Historique Ventes
                        </a>
                        <a href="prediction.php" class="btn btn-success btn-lg">
                            <i class="bi bi-robot me-2"></i>Prédictions IA
                        </a>
                        <a href="create_admin.php" class="btn btn-outline-secondary btn-lg">
                            <i class="bi bi-person-plus me-2"></i>Créer Admin
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- === STATISTIQUES GLOBALES === -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-transparent">
                    <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Statistiques Globales</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3 mb-3">
                            <div class="border rounded p-3">
                                <i class="bi bi-box text-primary" style="font-size: 2rem;"></i>
                                <h4 class="mt-2"><?php echo $global_stats['total_products']; ?></h4>
                                <small class="text-muted">Produits Total</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="border rounded p-3">
                                <i class="bi bi-people text-info" style="font-size: 2rem;"></i>
                                <h4 class="mt-2"><?php echo $global_stats['total_users']; ?></h4>
                                <small class="text-muted">Utilisateurs</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="border rounded p-3">
                                <i class="bi bi-cart text-warning" style="font-size: 2rem;"></i>
                                <h4 class="mt-2"><?php echo $global_stats['total_orders']; ?></h4>
                                <small class="text-muted">Commandes Total</small>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="border rounded p-3">
                                <i class="bi bi-currency-euro text-success" style="font-size: 2rem;"></i>
                                <h4 class="mt-2"><?php echo number_format($global_stats['total_revenue'], 0); ?>€</h4>
                                <small class="text-muted">Revenus Total</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- === CSS PERSONNALISÉ === -->
<style>
.stats-card {
    transition: all 0.3s ease;
    border-left: 4px solid transparent;
}

.stats-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.stats-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.card {
    border: 0;
    border-radius: 10px;
}

.shadow-sm {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

.list-group-item {
    border: none;
    border-bottom: 1px solid #eee;
}

.list-group-item:last-child {
    border-bottom: none;
}

.table th {
    border-top: none;
    font-weight: 600;
    font-size: 0.9rem;
}

.btn-lg {
    font-size: 1rem;
    padding: 0.75rem 1rem;
}

@media (max-width: 768px) {
    .stats-card .card-body {
        padding: 1rem;
    }
    
    .stats-icon {
        width: 40px;
        height: 40px;
        font-size: 1.2rem;
    }
}

/* Animations au chargement */
.stats-card {
    opacity: 0;
    animation: fadeInUp 0.6s ease forwards;
}

/* ...existing code... */
#previsionChart {
    max-height: 260px;
    width: 100% !important;
    height: 260px !important;
    margin: 0 auto;
    display: block;
}
/* ...existing code... */

.stats-card:nth-child(1) { animation-delay: 0.1s; }
.stats-card:nth-child(2) { animation-delay: 0.2s; }
.stats-card:nth-child(3) { animation-delay: 0.3s; }
.stats-card:nth-child(4) { animation-delay: 0.4s; }

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<!-- Scripts Chart.js pour les graphiques -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// === CONFIGURATION GLOBALE CHART.JS ===
Chart.defaults.font.family = 'Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.color = '#6c757d';

// === DONNÉES POUR LES GRAPHIQUES ===
const salesData = {
    labels: <?php echo json_encode(array_column($sales_data, 'label')); ?>,
    quantity: <?php echo json_encode(array_map('intval', array_column($sales_data, 'total'))); ?>,
    revenue: <?php echo json_encode(array_map('floatval', array_column($sales_data, 'revenue'))); ?>
};

const stockLabels = <?php echo json_encode(array_column($stock_data, 'name')); ?>;
const stockValues = <?php echo json_encode(array_column($stock_data, 'stock')); ?>;

const previsionLabels = <?php echo json_encode(array_column($previsions_data, 'name')); ?>;
const previsionValues = <?php echo json_encode(array_map('intval', array_column($previsions_data, 'quantite_prevue'))); ?>;

// === GRAPHIQUE DES VENTES ===
let salesChart;
const salesCtx = document.getElementById('salesChart').getContext('2d');

function createSalesChart(type = 'quantity') {
    if (salesChart) salesChart.destroy();
    
    const data = type === 'quantity' ? salesData.quantity : salesData.revenue;
    const label = type === 'quantity' ? 'Produits vendus' : 'Revenus (€)';
    const color = type === 'quantity' ? 'rgba(54, 162, 235, 0.8)' : 'rgba(40, 167, 69, 0.8)';
    
    salesChart = new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: salesData.labels,
            datasets: [{
                label: label,
                data: data,
                borderColor: color,
                backgroundColor: color.replace('0.8', '0.1'),
                borderWidth: 3,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: color,
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 6,
                pointHoverRadius: 8
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(0,0,0,0.05)'
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });
}

function toggleSalesChart(type) {
    // Mise à jour des boutons
    document.querySelectorAll('.btn-group .btn').forEach(btn => {
        btn.classList.remove('active');
    });
    event.target.classList.add('active');
    
    createSalesChart(type);
}

// === GRAPHIQUE DU STOCK ===
new Chart(document.getElementById('stockChart'), {
    type: 'doughnut',
    data: {
        labels: stockLabels,
        datasets: [{
            data: stockValues,
            backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF',
                '#4BC0C0', '#FF6384'
            ],
            borderWidth: 0,
            hoverBorderWidth: 3,
            hoverBorderColor: '#fff'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true,
                    font: {
                        size: 11
                    }
                }
            }
        }
    }
});

// === GRAPHIQUE DES PRÉDICTIONS ===
<?php if ($previsions_data): ?>
new Chart(document.getElementById('previsionChart'), {
    type: 'bar',
    data: {
        labels: previsionLabels,
        datasets: [{
            label: 'Prévision mois prochain',
            data: previsionValues,
            backgroundColor: 'rgba(40, 167, 69, 0.8)',
            borderColor: 'rgba(40, 167, 69, 1)',
            borderWidth: 1,
            borderRadius: 4,
            borderSkipped: false
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(0,0,0,0.05)'
                }
            }
        }
    }
});
<?php endif; ?>

// === API REST - MACHINE TO MACHINE COMMUNICATION ===
class AdminAPI {
    constructor() {
        this.baseURL = 'api/';
    }
    
    async getNotifications() {
        try {
            const response = await fetch(this.baseURL + 'notifications.php');
            return await response.json();
        } catch (error) {
            console.error('❌ Erreur notifications:', error);
            return null;
        }
    }
    
    async markNotificationRead(id) {
        try {
            const response = await fetch(this.baseURL + 'notifications.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({id: id})
            });
            return await response.json();
        } catch (error) {
            console.error('❌ Erreur mark read:', error);
            return null;
        }
    }
    
    async getStock() {
        try {
            const response = await fetch(this.baseURL + 'stock.php');
            return await response.json();
        } catch (error) {
            console.error('❌ Erreur stock:', error);
            return null;
        }
    }
    
    async getOrders() {
        try {
            const response = await fetch(this.baseURL + 'orders.php');
            return await response.json();
        } catch (error) {
            console.error('❌ Erreur orders:', error);
            return null;
        }
    }
}

// Initialisation de l'API
const api = new AdminAPI();

// Fonction pour mettre à jour le badge de notifications
function updateNotificationBadge(count) {
    const badge = document.querySelector('.position-absolute.badge');
    if (badge) {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    }
}

// === INITIALISATION ===
document.addEventListener('DOMContentLoaded', async function() {
    console.log('🚀 Dashboard Admin Moderne - Machine-to-Machine Communication Active!');
    
    // Créer le graphique des ventes initial
    createSalesChart('quantity');
    
    // Test initial des APIs
    const notifications = await api.getNotifications();
    if (notifications?.success) {
        console.log('✅ API Notifications OK:', notifications.count, 'notification(s)');
        updateNotificationBadge(notifications.count);
    }
    
    const stock = await api.getStock();
    if (stock?.success) {
        console.log('✅ API Stock OK:', stock.low_stock_count || 0, 'produit(s) en stock faible');
    }
    
    const orders = await api.getOrders();
    if (orders?.success) {
        console.log('✅ API Orders OK:', orders.stats?.pending_orders || 0, 'commande(s) en attente');
    }
    
    // Auto-refresh des notifications toutes les 60 secondes
    setInterval(async () => {
        const notifications = await api.getNotifications();
        if (notifications?.success) {
            updateNotificationBadge(notifications.count);
            console.log('🔄 Auto-refresh:', notifications.count, 'notification(s) non lue(s)');
        }
    }, 60000);
    
    console.log('✨ Dashboard entièrement initialisé avec succès!');
});
</script>


<?php include 'includes/footer.php'; ?>
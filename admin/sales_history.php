<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}
include 'includes/header.php';
include '../includes/db.php';

// Filtres
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$product_filter = $_GET['product_id'] ?? '';
$export = $_GET['export'] ?? false;

// Récupérer les ventes avec filtres
$query = "
    SELECT 
        o.id as order_id,
        o.order_date,
        u.name as customer_name,
        i.name as product_name,
        od.quantity,
        od.price,
        (od.quantity * od.price) as total_line,
        o.status
    FROM order_details od
    JOIN orders o ON od.order_id = o.id
    JOIN users u ON o.user_id = u.id
    JOIN items i ON od.item_id = i.id
    WHERE DATE(o.order_date) BETWEEN ? AND ?
";

$params = [$start_date, $end_date];

if ($product_filter) {
    $query .= " AND i.id = ?";
    $params[] = $product_filter;
}

$query .= " ORDER BY o.order_date DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques de la période
$stats_query = "
    SELECT 
        COUNT(DISTINCT o.id) as total_orders,
        SUM(od.quantity) as total_quantity,
        SUM(od.quantity * od.price) as total_revenue,
        AVG(o.total_price) as avg_order_value
    FROM order_details od
    JOIN orders o ON od.order_id = o.id
    WHERE DATE(o.order_date) BETWEEN ? AND ?
";

if ($product_filter) {
    $stats_query .= " AND od.item_id = ?";
}

$stats_stmt = $pdo->prepare($stats_query);
$stats_stmt->execute($params);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Export CSV
if ($export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="historique_ventes_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Headers CSV
    fputcsv($output, [
        'Date Commande', 'ID Commande', 'Client', 'Produit', 
        'Quantité', 'Prix Unitaire', 'Total Ligne', 'Statut'
    ]);
    
    // Données
    foreach ($sales as $sale) {
        fputcsv($output, [
            $sale['order_date'],
            $sale['order_id'],
            $sale['customer_name'],
            $sale['product_name'],
            $sale['quantity'],
            $sale['price'],
            $sale['total_line'],
            $sale['status']
        ]);
    }
    
    fclose($output);
    exit;
}

// Récupérer la liste des produits pour le filtre
$products = $pdo->query("SELECT id, name FROM items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>

<main class="container py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-clock-history me-2"></i>Historique des Ventes</h2>
                    <p class="text-muted">Analyse détaillée des performances commerciales</p>
                </div>
                <div>
                    <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-success">
                        <i class="bi bi-download me-1"></i>Exporter CSV
                    </a>
                    <a href="dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i>Retour Dashboard
                    </a>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Date début</label>
                            <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Date fin</label>
                            <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Produit</label>
                            <select name="product_id" class="form-select">
                                <option value="">Tous les produits</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo $product['id']; ?>" <?php echo $product_filter == $product['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($product['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search me-1"></i>Filtrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Statistiques de la période -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card border-primary">
                        <div class="card-body text-center">
                            <h3 class="text-primary"><?php echo number_format($stats['total_orders']); ?></h3>
                            <small class="text-muted">Commandes</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-success">
                        <div class="card-body text-center">
                            <h3 class="text-success"><?php echo number_format($stats['total_revenue'], 2); ?>€</h3>
                            <small class="text-muted">Chiffre d'affaires</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-info">
                        <div class="card-body text-center">
                            <h3 class="text-info"><?php echo number_format($stats['total_quantity']); ?></h3>
                            <small class="text-muted">Articles vendus</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-warning">
                        <div class="card-body text-center">
                            <h3 class="text-warning"><?php echo number_format($stats['avg_order_value'], 2); ?>€</h3>
                            <small class="text-muted">Panier moyen</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tableau des ventes -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        Détail des Ventes 
                        <span class="badge bg-primary ms-2"><?php echo count($sales); ?> ligne(s)</span>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if ($sales): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Date</th>
                                        <th>Commande</th>
                                        <th>Client</th>
                                        <th>Produit</th>
                                        <th>Qté</th>
                                        <th>Prix Unit.</th>
                                        <th>Total</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales as $sale): ?>
                                    <tr>
                                        <td><small><?php echo date('d/m/Y H:i', strtotime($sale['order_date'])); ?></small></td>
                                        <td><span class="fw-bold">#<?php echo $sale['order_id']; ?></span></td>
                                        <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                        <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo $sale['quantity']; ?></span></td>
                                        <td><?php echo number_format($sale['price'], 2); ?>€</td>
                                        <td><strong><?php echo number_format($sale['total_line'], 2); ?>€</strong></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo match($sale['status']) {
                                                    'pending' => 'warning',
                                                    'shipped' => 'info',
                                                    'delivered' => 'success',
                                                    'cancelled' => 'danger',
                                                    default => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($sale['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-5">
                            <i class="bi bi-graph-down text-muted" style="font-size: 3rem;"></i>
                            <h4 class="mt-3">Aucune vente trouvée</h4>
                            <p class="text-muted">Aucune donnée pour la période sélectionnée</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
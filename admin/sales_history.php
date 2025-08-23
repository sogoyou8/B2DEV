<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'includes/header.php';
include_once '../includes/db.php';

// Filtres
$start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 month'));
$end_date   = $_GET['end_date']   ?? date('Y-m-d');
$product_filter = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? intval($_GET['product_id']) : '';
$export = $_GET['export'] ?? '';

// Récupérer la liste des produits pour le filtre
try {
    $products = $pdo->query("SELECT id, name FROM items ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
    $_SESSION['error'] = "Impossible de récupérer la liste des produits : " . $e->getMessage();
}

// Construire la requête principale des ventes (détails par ligne)
$query = "
    SELECT 
        o.id AS order_id,
        o.order_date,
        u.name AS customer_name,
        i.name AS product_name,
        od.quantity,
        od.price,
        (od.quantity * od.price) AS total_line,
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

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sales = [];
    $_SESSION['error'] = "Erreur lors de la récupération des ventes : " . $e->getMessage();
}

// Statistiques de la période
$stats_query = "
    SELECT 
        COUNT(DISTINCT o.id) AS total_orders,
        COALESCE(SUM(od.quantity), 0) AS total_quantity,
        COALESCE(SUM(od.quantity * od.price), 0) AS total_revenue,
        COALESCE(AVG(o.total_price), 0) AS avg_order_value
    FROM order_details od
    JOIN orders o ON od.order_id = o.id
    WHERE DATE(o.order_date) BETWEEN ? AND ?
";
$stats_params = [$start_date, $end_date];

if ($product_filter) {
    $stats_query .= " AND od.item_id = ?";
    $stats_params[] = $product_filter;
}

try {
    $stats_stmt = $pdo->prepare($stats_query);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = [
            'total_orders' => 0,
            'total_quantity' => 0,
            'total_revenue' => 0,
            'avg_order_value' => 0
        ];
    }
} catch (Exception $e) {
    $stats = [
        'total_orders' => 0,
        'total_quantity' => 0,
        'total_revenue' => 0,
        'avg_order_value' => 0
    ];
    $_SESSION['error'] = "Impossible de calculer les statistiques : " . $e->getMessage();
}

// Export CSV si demandé
if ($export && strtolower($export) === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historique_ventes_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');

    // En-têtes CSV
    fputcsv($output, [
        'Date Commande', 'ID Commande', 'Client', 'Produit',
        'Quantité', 'Prix Unitaire', 'Total Ligne', 'Statut'
    ]);

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
?>
<style>
:root{
    --card-radius:12px;
    --muted:#6c757d;
    --bg-gradient-1:#f8fbff;
    --bg-gradient-2:#eef7ff;
    --accent:#0d6efd;
    --accent-2:#6610f2;
}
body.admin-page { background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2)); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }
.panel-card { border-radius: var(--card-radius); background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff); box-shadow: 0 12px 36px rgba(3,37,76,0.06); padding: 1.25rem; }
.page-title { display:flex; gap:1rem; align-items:center; }
.page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
.small-muted { color:var(--muted); font-size:.95rem; }
.table thead th { background: linear-gradient(180deg,#fbfdff,#f2f7ff); border-bottom:1px solid rgba(3,37,76,0.06); font-weight:600; }
.badge-status { border-radius:8px; padding:.35em .6em; font-size:.9rem; }
.input-search { max-width:540px; width:100%; }
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Historique des Ventes</h2>
                    <div class="small text-muted ms-2">Analyse détaillée des performances commerciales</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Retour Dashboard</a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>" class="btn btn-outline-primary btn-sm">Exporter CSV</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="get" class="mb-3 d-flex gap-2 align-items-center flex-wrap">
            <label class="small-muted mb-0">Date début
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($start_date); ?>">
            </label>
            <label class="small-muted mb-0">Date fin
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($end_date); ?>">
            </label>
            <label class="small-muted mb-0">Produit
                <select name="product_id" class="form-select form-select-sm">
                    <option value="">Tous les produits</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" <?php echo $product_filter == $p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="ms-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
                <a href="sales_history.php" class="btn btn-outline-secondary btn-sm">Réinitialiser</a>
            </div>
        </form>

        <h3>Statistiques de la période</h3>
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h3 class="text-primary"><?php echo number_format($stats['total_orders'] ?? 0); ?></h3>
                        <small class="text-muted">Commandes</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h3 class="text-success"><?php echo number_format($stats['total_revenue'] ?? 0, 2); ?>€</h3>
                        <small class="text-muted">Chiffre d'affaires</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h3 class="text-info"><?php echo number_format($stats['total_quantity'] ?? 0); ?></h3>
                        <small class="text-muted">Articles vendus</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <h3 class="text-warning"><?php echo number_format($stats['avg_order_value'] ?? 0, 2); ?>€</h3>
                        <small class="text-muted">Panier moyen</small>
                    </div>
                </div>
            </div>
        </div>

        <h3>Détail des ventes (<?php echo count($sales); ?> ligne(s))</h3>

        <?php if ($sales): ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Commande</th>
                            <th>Client</th>
                            <th>Produit</th>
                            <th>Qté</th>
                            <th>Prix Unit.</th>
                            <th>Total</th>
                            <th>Statut</th>
                            <th style="width:140px;" class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sales as $sale): ?>
                            <tr data-search="<?php echo htmlspecialchars(strtolower($sale['order_id'].' '.$sale['customer_name'].' '.$sale['product_name'].' '.$sale['status'])); ?>">
                                <td><small><?php echo date('d/m/Y H:i', strtotime($sale['order_date'])); ?></small></td>
                                <td><span class="fw-bold">#<?php echo (int)$sale['order_id']; ?></span></td>
                                <td><?php echo htmlspecialchars($sale['customer_name']); ?></td>
                                <td><?php echo htmlspecialchars($sale['product_name']); ?></td>
                                <td><?php echo (int)$sale['quantity']; ?></td>
                                <td><?php echo number_format((float)$sale['price'], 2); ?>€</td>
                                <td><?php echo number_format((float)$sale['total_line'], 2); ?>€</td>
                                <td><?php echo ucfirst(htmlspecialchars($sale['status'])); ?></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <a href="edit_order.php?id=<?php echo (int)$sale['order_id']; ?>" class="btn btn-sm btn-warning">Voir / Modifier</a>
                                        <a href="user_activity.php?user_id=<?php
                                            // try to infer user_id from orders table (non-critical)
                                            try {
                                                $q = $pdo->prepare('SELECT user_id FROM orders WHERE id = ? LIMIT 1');
                                                $q->execute([(int)$sale['order_id']]);
                                                $uid = $q->fetchColumn();
                                                echo $uid ? (int)$uid : '';
                                            } catch (Exception $e) {
                                                echo '';
                                            }
                                        ?>" class="btn btn-sm btn-outline-info">Client</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-muted">Aucune vente trouvée pour la période sélectionnée.</p>
        <?php endif; ?>

    </section>
</main>

<script>
(function(){
    var input = document.createElement('input');
    input.type = 'search';
    input.placeholder = 'Recherche rapide (ID, client, produit, statut)...';
    input.className = 'form-control mb-3';
    input.style.maxWidth = '540px';
    var panel = document.querySelector('.panel-card');
    if (panel) panel.insertBefore(input, panel.children[2] || panel.firstChild);

    var rows = function() { return Array.from(document.querySelectorAll('table tbody tr[data-search]')); };

    input.addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        rows().forEach(function(r){
            var txt = r.getAttribute('data-search') || '';
            r.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
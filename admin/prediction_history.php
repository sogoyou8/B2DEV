<?php
if (session_status() == PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'includes/header.php';
include_once '../includes/db.php';
include_once 'admin_demo_guard.php';

// Filtres
$where = [];
$params = [];

if (!empty($_GET['produit'])) {
    $where[] = "i.name LIKE ?";
    $params[] = '%' . $_GET['produit'] . '%';
}
if (!empty($_GET['mois'])) {
    // Expect format YYYY-MM for input[type=month]
    $where[] = "DATE_FORMAT(p.date_prevision, '%Y-%m') = ?";
    $params[] = $_GET['mois'];
}

$sql = "SELECT p.item_id, i.name, p.date_prevision, p.quantite_prevue, p.confidence_score, p.trend_direction, p.created_at
        FROM previsions p
        JOIN items i ON p.item_id = i.id";

if ($where) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY p.date_prevision DESC, i.name ASC LIMIT 200";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $history = [];
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}

// Export CSV (conserve la logique existante : protection mode démo)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: prediction_history.php");
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="historique_previsions_' . date('Y-m-d') . '.csv"');

    $output = fopen('php://output', 'w');
    // En-têtes CSV
    fputcsv($output, ['Produit', 'Mois prévision', 'Quantité prévue', 'Confiance', 'Tendance', 'Date génération']);

    foreach ($history as $row) {
        fputcsv($output, [
            $row['name'],
            date('F Y', strtotime($row['date_prevision'])),
            $row['quantite_prevue'],
            (isset($row['confidence_score']) ? ((int)$row['confidence_score'] . '%') : '0%'),
            ucfirst($row['trend_direction'] ?? ''),
            date('d/m/Y H:i', strtotime($row['created_at'] ?? ''))
        ]);
    }

    fclose($output);
    exit;
}

?>
<script>try { document.body.classList.add('admin-page'); } catch(e){}</script>
<style>
:root{
    --card-radius:12px;
    --muted:#6c757d;
    --bg-gradient-1:#f8fbff;
    --bg-gradient-2:#eef7ff;
    --accent:#198754;
    --accent-2:#0f5132;
}
body.admin-page { background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2)); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }
.panel-card { border-radius: var(--card-radius); background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff); box-shadow: 0 12px 36px rgba(3,37,76,0.06); padding: 1.25rem; }
.page-title { display:flex; gap:1rem; align-items:center; }
.page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.controls { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.btn-round { border-radius:8px; }
.table thead th { background: linear-gradient(180deg,#fbfdff,#f2f7ff); border-bottom:1px solid rgba(3,37,76,0.06); font-weight:600; }
.small-muted { color:var(--muted); font-size:.95rem; }
.input-search { max-width:540px; width:100%; }
.badge-status { border-radius:8px; padding:.35em .6em; font-size:.9rem; }
@media (max-width:768px) { .controls { width:100%; justify-content:space-between; } }
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Historique des Prévisions IA</h2>
                <div class="small text-muted ms-2">Historique des prévisions enregistrées — export CSV & filtres.</div>
            </div>

            <div class="controls">
                <a href="prediction.php" class="btn btn-outline-secondary btn-sm btn-round"><i class="bi bi-arrow-left me-1"></i>Retour à la prédiction IA</a>
                <a href="how_it_works.php?page=history" class="btn btn-outline-info btn-sm btn-round"><i class="bi bi-info-circle me-1"></i>Comment ça marche ?</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="get" class="mb-3 d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="produit" class="form-control form-control-sm input-search" placeholder="Filtrer par produit" value="<?php echo htmlspecialchars($_GET['produit'] ?? ''); ?>">
            <input type="month" name="mois" class="form-control form-control-sm" value="<?php echo htmlspecialchars($_GET['mois'] ?? ''); ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary">Filtrer</button>

            <div class="ms-auto">
                <?php
                    // Build current query with existing filters for export link
                    $q = $_GET;
                    $q['export'] = 'csv';
                    $exportUrl = 'prediction_history.php?' . http_build_query($q);
                ?>
                <a href="<?php echo htmlspecialchars($exportUrl); ?>" class="btn btn-outline-secondary btn-sm">Exporter CSV</a>
            </div>
        </form>

        <div class="card border-0 shadow-sm mb-3 p-3">
            <div class="small text-muted">
                Le CSV exporte jusqu'à 200 lignes. L'action d'export est désactivée en mode démo.
            </div>
        </div>

        <div class="table-responsive rounded shadow-sm">
            <table class="table table-hover table-striped align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Produit</th>
                        <th>Mois prévision</th>
                        <th>Quantité prévue</th>
                        <th>Confiance</th>
                        <th>Tendance</th>
                        <th>Date génération</th>
                        <th style="width:220px;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">Aucune prévision trouvée.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($history as $row): ?>
                            <?php
                                $itemId = isset($row['item_id']) ? intval($row['item_id']) : 0;
                                $prod = htmlspecialchars($row['name'] ?? '—');
                                $monthLabel = !empty($row['date_prevision']) ? date('F Y', strtotime($row['date_prevision'])) : '-';
                                $qty = (int)($row['quantite_prevue'] ?? 0);
                                $conf = isset($row['confidence_score']) ? intval($row['confidence_score']) : 0;
                                $trend = ucfirst(htmlspecialchars($row['trend_direction'] ?? '—'));
                                $genDate = !empty($row['created_at']) ? date('d/m/Y H:i', strtotime($row['created_at'])) : '-';
                                $searchText = strtolower($itemId . ' ' . $prod . ' ' . $monthLabel . ' ' . $trend);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($searchText); ?>">
                                <td><?php echo $prod; ?></td>
                                <td><?php echo htmlspecialchars($monthLabel); ?></td>
                                <td><?php echo $qty; ?></td>
                                <td><?php echo $conf; ?>%</td>
                                <td><?php echo $trend; ?></td>
                                <td><small class="text-muted"><?php echo $genDate; ?></small></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <?php if ($itemId > 0): ?>
                                            <a href="../product_detail.php?id=<?php echo $itemId; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-seam"></i> Voir fiche</a>
                                            <a href="edit_product.php?id=<?php echo $itemId; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i> Modifier</a>
                                            <a href="sales_history.php?product_id=<?php echo $itemId; ?>" class="btn btn-sm btn-info"><i class="bi bi-clock-history"></i> Historique ventes</a>
                                        <?php else: ?>
                                            <span class="text-muted small">Produit supprimé</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </section>
</main>

<script>
(function(){
    // Quick client-side search for the table (mimic list_* pages)
    var inputs = document.querySelectorAll('input[name="produit"], input[name="mois"]');
    var rows = Array.from(document.querySelectorAll('table tbody tr[data-search]'));

    var searchInput = document.querySelector('input[name="produit"]');
    if (searchInput) {
        searchInput.addEventListener('input', function(){
            var q = this.value.trim().toLowerCase();
            rows.forEach(function(r){
                var txt = r.getAttribute('data-search') || '';
                r.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
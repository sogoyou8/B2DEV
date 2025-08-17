<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}
include 'includes/header.php';
include '../includes/db.php';
include 'admin_demo_guard.php';

// Filtres
$where = [];
$params = [];
if (!empty($_GET['produit'])) {
    $where[] = "i.name LIKE ?";
    $params[] = '%' . $_GET['produit'] . '%';
}
if (!empty($_GET['mois'])) {
    $where[] = "DATE_FORMAT(p.date_prevision, '%Y-%m') = ?";
    $params[] = $_GET['mois'];
}
$sql = "SELECT i.name, p.date_prevision, p.quantite_prevue, p.confidence_score, p.trend_direction, p.created_at
        FROM previsions p
        JOIN items i ON p.item_id = i.id";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY p.date_prevision DESC, i.name ASC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: prediction_history.php");
        exit;
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename=historique_previsions.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Produit', 'Mois prévision', 'Quantité prévue', 'Confiance', 'Tendance', 'Date génération']);
    foreach ($history as $row) {
        fputcsv($output, [
            $row['name'],
            date('F Y', strtotime($row['date_prevision'])),
            $row['quantite_prevue'],
            $row['confidence_score'] . '%',
            ucfirst($row['trend_direction']),
            date('d/m/Y H:i', strtotime($row['created_at']))
        ]);
    }
    fclose($output);
    exit;
}
?>

<main class="container py-4">
    <h2><i class="bi bi-clock-history me-2"></i>Historique des Prévisions IA</h2>
    <!-- Bouton retour vers la prédiction -->
    <a href="prediction.php" class="btn btn-outline-primary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Retour à la prédiction IA
    </a>
    <a href="how_it_works.php?page=history" class="btn btn-outline-info ms-2">
        <i class="bi bi-info-circle me-1"></i>Comment ça marche ?
    </a>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger mt-3"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
    <?php endif; ?>
    <form method="get" class="mb-3 d-flex gap-2">
        <input type="text" name="produit" class="form-control" placeholder="Filtrer par produit" value="<?php echo htmlspecialchars($_GET['produit'] ?? ''); ?>">
        <input type="month" name="mois" class="form-control" value="<?php echo htmlspecialchars($_GET['mois'] ?? ''); ?>">
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a href="prediction_history.php" class="btn btn-secondary">Réinitialiser</a>
        <button type="submit" name="export" value="csv" class="btn btn-outline-success">Exporter CSV</button>
    </form>

    <!-- Graphique Chart.js -->
    <canvas id="previsionChart" height="80"></canvas>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
    const labels = <?php echo json_encode(array_map(function($row){return $row['name'].' ('.date('m/Y', strtotime($row['date_prevision'])).')';}, $history)); ?>;
    const data = <?php echo json_encode(array_map('intval', array_column($history, 'quantite_prevue'))); ?>;
    new Chart(document.getElementById('previsionChart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Quantité prévue',
                data: data,
                backgroundColor: 'rgba(40,167,69,0.6)'
            }]
        }
    });
    </script>

    <div class="table-responsive mt-4">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>Produit</th>
                    <th>Mois prévision</th>
                    <th>Quantité prévue</th>
                    <th>Confiance</th>
                    <th>Tendance</th>
                    <th>Date génération</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo date('F Y', strtotime($row['date_prevision'])); ?></td>
                    <td><?php echo $row['quantite_prevue']; ?></td>
                    <td><?php echo $row['confidence_score']; ?>%</td>
                    <td><?php echo ucfirst($row['trend_direction']); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
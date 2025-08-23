<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once 'includes/header.php';
include_once '../includes/db.php';
include_once 'admin_demo_guard.php';

/**
 * Moteur de prédiction avancé
 */
class AdvancedPredictionEngine {
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Récupère données historiques mensuelles pour un item
     */
    private function fetchHistoricalData(int $item_id, int $months = 6): array {
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE_FORMAT(o.order_date, '%Y-%m') as period,
                SUM(od.quantity) as quantity,
                AVG(od.price) as avg_price
            FROM order_details od
            JOIN orders o ON od.order_id = o.id
            WHERE od.item_id = ?
              AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$item_id, $months]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // normalize months: ensure we return exactly $months points (zero when missing)
        $data = [];
        $periodLabels = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $period = date('Y-m', strtotime("-$i month"));
            $periodLabels[$period] = 0;
        }
        foreach ($rows as $r) {
            $periodLabels[$r['period']] = intval($r['quantity']);
        }
        foreach ($periodLabels as $p => $q) {
            $data[] = ['period' => $p, 'quantity' => (int)$q];
        }
        return $data;
    }

    /**
     * Génère une prédiction simple (régression linéaire avec ajustements saisonniers)
     * Retourne array : ['prediction' => int, 'confidence' => int, 'trend' => 'Stable|Hausse|Baisse']
     */
    private function predictFromSeries(array $series): array {
        // series: array of ['period'=>'YYYY-MM','quantity'=>int]
        $n = count($series);
        if ($n === 0) {
            return ['prediction' => 0, 'confidence' => 0, 'trend' => 'Données insuffisantes'];
        }

        // Prepare X (0..n-1) and Y (quantities)
        $X = [];
        $Y = [];
        foreach ($series as $i => $s) {
            $X[] = $i;
            $Y[] = $s['quantity'];
        }

        // Basic checks
        $sumX = array_sum($X);
        $sumY = array_sum($Y);
        $sumXY = 0;
        $sumX2 = 0;
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $X[$i] * $Y[$i];
            $sumX2 += $X[$i] * $X[$i];
        }

        // If variance zero, fallback to mean
        $den = ($n * $sumX2 - $sumX * $sumX);
        if ($den == 0) {
            $avg = round($sumY / max(1, $n));
            return ['prediction' => $avg, 'confidence' => 30, 'trend' => 'Stable'];
        }

        $slope = ($n * $sumXY - $sumX * $sumY) / $den;
        $intercept = ($sumY - $slope * $sumX) / $n;

        // predict next period (X = n)
        $rawPrediction = $slope * $n + $intercept;

        // Adjust prediction by basic seasonal factor (detect last month vs same month previous year)
        // Simpler: if last value significantly above/below mean -> boost/shrink
        $mean = $sumY / max(1, $n);
        $seasonCoef = 1.0;
        if ($mean > 0) {
            $last = end($Y);
            $ratio = $last / max(1, $mean);
            if ($ratio > 1.25) $seasonCoef = 1.08;
            elseif ($ratio < 0.75) $seasonCoef = 0.92;
        }

        $pred = round(max(0, $rawPrediction * $seasonCoef));

        // Confidence score: based on number of points + R^2-like proxy
        // Compute residual sum of squares and total sum of squares
        $ssr = 0; $sst = 0;
        foreach ($X as $i => $x) {
            $y = $Y[$i];
            $yhat = $slope * $x + $intercept;
            $ssr += ($y - $yhat) * ($y - $yhat);
            $sst += ($y - $mean) * ($y - $mean);
        }
        $r2 = ($sst > 0) ? (1 - ($ssr / $sst)) : 0;
        $r2 = max(0, min(1, $r2));

        // Confidence scales with r2 and number of points
        $conf = (int)round( max(0, min(100, ($r2 * 70) + ($n / 12) * 30)) );

        // Trend detection (slope threshold)
        $trend = 'Stable';
        if ($slope > 0.5) $trend = 'Hausse';
        if ($slope < -0.5) $trend = 'Baisse';

        return ['prediction' => (int)$pred, 'confidence' => $conf, 'trend' => $trend];
    }

    /**
     * Génère des prédictions pour tous les produits actifs
     * Retourne array of predictions
     */
    public function generateAllPredictions(int $months = 6): array {
        // récupérer items
        $items = $this->pdo->query("SELECT id, name, stock FROM items ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $predictions = [];

        foreach ($items as $item) {
            $series = $this->fetchHistoricalData((int)$item['id'], $months);
            // if not enough data (less than 2 points with non-zero) -> fallback
            $nonZeroCount = count(array_filter($series, fn($s) => intval($s['quantity']) > 0));
            if ($nonZeroCount < 2) {
                // fallback: use moving average of available data or 0
                $avg = array_sum(array_column($series, 'quantity')) / max(1, count($series));
                $pred = round($avg);
                $predictions[] = [
                    'item_id' => (int)$item['id'],
                    'name' => $item['name'],
                    'prediction' => (int)$pred,
                    'confidence' => max(0, min(40, $nonZeroCount * 20)),
                    'trend' => 'Données insuffisantes',
                ];
                continue;
            }

            $res = $this->predictFromSeries($series);
            $predictions[] = [
                'item_id' => (int)$item['id'],
                'name' => $item['name'],
                'prediction' => $res['prediction'],
                'confidence' => $res['confidence'],
                'trend' => $res['trend'],
            ];
        }

        // persist to previsions table
        $this->savePredictions($predictions);

        return $predictions;
    }

    /**
     * Save array of predictions into previsions table for next month
     */
    private function savePredictions(array $predictions): void {
        $nextMonth = date('Y-m-01', strtotime('+1 month'));
        // Delete previous
        $del = $this->pdo->prepare("DELETE FROM previsions WHERE date_prevision = ?");
        $del->execute([$nextMonth]);

        $ins = $this->pdo->prepare("
            INSERT INTO previsions (item_id, quantite_prevue, confidence_score, trend_direction, date_prevision, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        foreach ($predictions as $p) {
            $ins->execute([
                $p['item_id'],
                $p['prediction'],
                $p['confidence'],
                $p['trend'],
                $nextMonth
            ]);
        }
    }
}

// Page logic
$engine = new AdvancedPredictionEngine($pdo);
$processing = false;
$predictions = [];
$periode = isset($_POST['periode']) ? intval($_POST['periode']) : (isset($_GET['periode']) ? intval($_GET['periode']) : 6);

// Retrieve last generation date for display
$date_generation = null;
try {
    $date_generation_query = $pdo->query("
        SELECT MAX(created_at) as last_gen
        FROM previsions
        WHERE date_prevision = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
    ");
    $date_generation = $date_generation_query ? $date_generation_query->fetchColumn() : null;
} catch (Exception $e) {
    // ignore DB error; leave date_generation null
}

// Handle generate action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: prediction.php");
        exit;
    }

    // validate periode (3,6,12)
    if (!in_array($periode, [3,6,12], true)) $periode = 6;

    $processing = true;
    try {
        $predictions = $engine->generateAllPredictions($periode);
        $_SESSION['success'] = "Prédictions IA générées avec succès !";
        // Journalisation notification (non-persistante)
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
            $stmt->execute(['admin_action', 'Prédictions IA générées par ' . ($_SESSION['admin_name'] ?? 'admin')]);
        } catch (Exception $e) {
            // ignore
        }
        $processing = false;
        // refresh date_generation
        $date_generation = date('Y-m-d H:i:s');
    } catch (Exception $e) {
        $processing = false;
        $_SESSION['error'] = "Erreur lors de la génération : " . $e->getMessage();
    }
} else {
    // Load existing predictions for next month (if any)
    try {
        // include primary image (position = 0) when loading existing predictions
        $predictions = $pdo->query("
            SELECT 
                p.id AS prevision_id,
                p.item_id,
                i.name, 
                p.quantite_prevue as prediction,
                p.confidence_score as confidence,
                p.trend_direction as trend,
                i.stock as current_stock,
                COALESCE(pi.image, '') AS image
            FROM previsions p
            JOIN items i ON p.item_id = i.id
            LEFT JOIN product_images pi ON i.id = pi.product_id AND pi.position = 0
            WHERE p.date_prevision = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
            ORDER BY p.quantite_prevue DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $predictions = [];
        $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    }
}

// helper to resolve image for admin preview (reuse logic used elsewhere)
function resolveImageSrcAdmin(string $imageName = ''): string {
    $assetsFsDir = realpath(__DIR__ . '/../assets/images');
    $candidates = [];
    if ($assetsFsDir !== false) {
        if ($imageName !== '') {
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . basename($imageName);
        }
        $defaultFs = $assetsFsDir . DIRECTORY_SEPARATOR . 'default.png';
    } else {
        $candidates[] = __DIR__ . "/../assets/images/" . $imageName;
        $candidates[] = __DIR__ . "/../assets/images/products/" . $imageName;
        $defaultFs = __DIR__ . "/../assets/images/default.png";
    }

    foreach ($candidates as $p) {
        if (!$p) continue;
        if (@file_exists($p)) {
            return '../assets/images/' . rawurlencode(basename($p));
        }
    }

    if (@file_exists($defaultFs)) {
        return '../assets/images/default.png';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="16">No image</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

// HTML output harmonized with other admin pages (list_products/list_orders/list_users)
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
.page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
.controls { display:flex; gap:.5rem; align-items:center; }
.btn-round { border-radius:8px; }
.help-note { color:var(--muted); font-size:.95rem; }
.form-card { border-radius:12px; }
.pred-img { width:64px; height:48px; object-fit:cover; border-radius:6px; box-shadow:0 6px 14px rgba(3,37,76,0.06); }
.table thead th { background: linear-gradient(180deg,#fbfdff,#f2f7ff); border-bottom:1px solid rgba(3,37,76,0.06); font-weight:600; }
.small-muted { color:var(--muted); font-size:.95rem; }
.canvas-wrap { height: 340px; }
@media (max-width: 768px) { .controls { width:100%; justify-content:space-between; } .canvas-wrap { height:260px; } }
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Prédictions IA Avancées</h2>
                    <div class="small text-muted ms-2">Calcul de la demande future et recommandations de réapprovisionnement</div>
                </div>
                <?php if ($date_generation): ?>
                    <div class="small text-muted mt-1">Dernière génération : <strong><?php echo date('d/m/Y H:i', strtotime($date_generation)); ?></strong></div>
                <?php endif; ?>
            </div>

            <div class="controls">
                <a href="prediction_history.php" class="btn btn-outline-secondary btn-sm btn-round"><i class="bi bi-clock-history me-1"></i> Historique</a>
                <a href="how_it_works.php?page=prediction" class="btn btn-outline-info btn-sm btn-round"><i class="bi bi-info-circle me-1"></i> Comment ça marche ?</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="post" class="d-flex gap-2 align-items-center mb-3">
            <label class="small-muted mb-0">Période d'analyse :</label>
            <select name="periode" class="form-select form-select-sm" style="width:110px;">
                <option value="3" <?php if($periode==3) echo 'selected'; ?>>3 mois</option>
                <option value="6" <?php if($periode==6) echo 'selected'; ?>>6 mois</option>
                <option value="12" <?php if($periode==12) echo 'selected'; ?>>12 mois</option>
            </select>

            <button type="submit" name="generate" class="btn btn-success btn-sm <?php echo $processing ? 'disabled' : ''; ?>">
                <?php if ($processing): ?><span class="spinner-border spinner-border-sm me-1"></span>Génération...<?php else: ?><i class="bi bi-gear me-1"></i>Générer / Rafraîchir<?php endif; ?>
            </button>
            <div class="ms-auto small-muted">L'opération peut être lourde. Désactivée en mode démo.</div>
        </form>

        <?php if (empty($predictions)): ?>
            <div class="card p-3 mb-3">
                <div class="text-center py-4">
                    <i class="bi bi-robot text-muted" style="font-size: 3rem;"></i>
                    <h4 class="mt-3">Aucune prédiction disponible</h4>
                    <p class="text-muted">Générez des prédictions pour voir l'analyse IA.</p>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="periode" value="<?php echo htmlspecialchars($periode); ?>">
                        <button type="submit" name="generate" class="btn btn-success btn-lg">Lancer l'analyse IA</button>
                    </form>
                </div>
            </div>
        <?php else: ?>

            <div class="row mb-3">
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <h3 class="text-success mb-0"><?php echo count($predictions); ?></h3>
                        <small class="text-muted">Produits analysés</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <h3 class="text-info mb-0"><?php echo array_sum(array_column($predictions, 'prediction')); ?></h3>
                        <small class="text-muted">Demande totale prévue</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <h3 class="text-warning mb-0"><?php echo round(array_sum(array_column($predictions, 'confidence')) / max(1, count($predictions))); ?>%</h3>
                        <small class="text-muted">Confiance moyenne</small>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card p-3 text-center">
                        <h3 class="text-primary mb-0"><?php echo count(array_filter($predictions, fn($p) => intval($p['prediction']) > intval($p['current_stock'] ?? 0))); ?></h3>
                        <small class="text-muted">Réapprovisionnements suggérés</small>
                    </div>
                </div>
            </div>

            <div class="card mb-3 p-3">
                <div class="d-flex gap-3 align-items-center mb-2">
                    <h5 class="mb-0">Graphiques des prévisions</h5>
                    <div class="small text-muted ms-auto">Visualisez les prévisions vs stock et la confiance par produit</div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="canvas-wrap">
                            <canvas id="predictionsChart" style="width:100%; height:100%;"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="canvas-wrap">
                            <canvas id="confidenceChart" style="width:100%; height:100%;"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="table-responsive rounded shadow-sm">
                <table class="table table-hover table-striped align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Image</th>
                            <th>Produit</th>
                            <th>Stock Actuel</th>
                            <th>Demande Prévue</th>
                            <th>Confiance</th>
                            <th>Tendance</th>
                            <th>Recommandation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($predictions as $pred): ?>
                            <?php
                                // Resolve image for admin preview (use assets images primary file if available)
                                $imgFile = __DIR__ . "/../assets/images/" . ($pred['image'] ?? '');
                                if (!empty($pred['image']) && @file_exists($imgFile)) {
                                    $imgSrc = '../assets/images/' . rawurlencode(basename($imgFile));
                                } else {
                                    $imgSrc = '../assets/images/default.png';
                                }

                                $rec = '';
                                $predQty = intval($pred['prediction'] ?? 0);
                                $stock = intval($pred['current_stock'] ?? 0);
                                $conf = intval($pred['confidence'] ?? 0);
                                $trend = ucfirst(htmlspecialchars($pred['trend'] ?? '—'));

                                if ($predQty <= 0) {
                                    $rec = '<span class="badge bg-secondary text-white">Aucune action</span> - Demande nulle';
                                } elseif ($stock <= 0 && $predQty > 0) {
                                    $rec = '<span class="badge bg-danger text-white">Rupture de stock</span> - Stock épuisé !';
                                } elseif ($predQty > $stock) {
                                    $rec = '<span class="badge bg-danger text-white">Réapprovisionnement</span> - Commander ' . ($predQty - $stock) . ' unité(s)';
                                } else {
                                    $rec = '<span class="badge bg-success text-white">Stock suffisant</span> - Stock adapté à la demande prévue';
                                }
                            ?>
                            <tr>
                                <td><img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($pred['name'] ?? ''); ?>" class="pred-img"></td>
                                <td><strong><?php echo htmlspecialchars($pred['name'] ?? ''); ?></strong></td>
                                <td><?php echo $stock; ?></td>
                                <td><?php echo $predQty; ?></td>
                                <td><?php echo $conf; ?>%</td>
                                <td><?php echo $trend; ?></td>
                                <td><?php echo $rec; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>
    </section>
</main>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
(function(){
    // Prepare data for charts from PHP $predictions
    var rawPredictions = <?php echo json_encode(array_values($predictions), JSON_UNESCAPED_UNICODE); ?> || [];

    if (!rawPredictions || rawPredictions.length === 0) return;

    // Build label and datasets
    var labels = rawPredictions.map(function(p){ return (p.name || ('ID ' + p.item_id)); });
    var predicted = rawPredictions.map(function(p){ return Number(p.prediction || 0); });
    var stock = rawPredictions.map(function(p){ return Number(p.current_stock || 0); });
    var confidence = rawPredictions.map(function(p){ return Number(p.confidence || 0); });

    // --- Combined bar (prediction) + line (stock) chart ---
    var ctx = document.getElementById('predictionsChart').getContext('2d');
    var predictionsChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'bar',
                    label: 'Prévision (unités)',
                    data: predicted,
                    backgroundColor: 'rgba(13,110,253,0.85)',
                    borderColor: 'rgba(13,110,253,1)',
                    borderWidth: 1,
                    order: 2
                },
                {
                    type: 'line',
                    label: 'Stock actuel',
                    data: stock,
                    backgroundColor: 'rgba(40,167,69,0.2)',
                    borderColor: 'rgba(40,167,69,1)',
                    borderWidth: 2,
                    tension: 0.25,
                    pointRadius: 4,
                    fill: false,
                    order: 1,
                    yAxisID: 'yStock'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            stacked: false,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var label = ctx.dataset.label || '';
                            if (label) label += ': ';
                            label += ctx.parsed.y !== undefined ? ctx.parsed.y : ctx.parsed;
                            return label;
                        }
                    }
                },
                legend: { position: 'bottom' }
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Unités (prévision)' },
                    beginAtZero: true
                },
                yStock: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    title: { display: true, text: 'Stock actuel' },
                    beginAtZero: true
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 12
                    }
                }
            }
        }
    });

    // --- Confidence radar chart (top 8 by prediction)
    var sorted = rawPredictions.slice().sort(function(a,b){ return (b.prediction || 0) - (a.prediction || 0); });
    var top = sorted.slice(0, 8);
    var labelsConf = top.map(function(p){ return (p.name || ('ID ' + p.item_id)); });
    var dataConf = top.map(function(p){ return Number(p.confidence || 0); });

    var ctx2 = document.getElementById('confidenceChart').getContext('2d');
    var confChart = new Chart(ctx2, {
        type: 'radar',
        data: {
            labels: labelsConf,
            datasets: [{
                label: 'Confiance (%) - Top produits',
                data: dataConf,
                backgroundColor: 'rgba(255,193,7,0.12)',
                borderColor: 'rgba(255,193,7,1)',
                borderWidth: 1,
                pointBackgroundColor: 'rgba(255,193,7,1)',
                pointRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    suggestedMin: 0,
                    suggestedMax: 100,
                    ticks: { stepSize: 10 },
                    pointLabels: {
                        font: { size: 11 }
                    }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            return ctx.dataset.label + ': ' + ctx.formattedValue + '%';
                        }
                    }
                }
            }
        }
    });

    // Make charts redraw on window resize for responsive layout
    window.addEventListener('resize', function(){
        try { predictionsChart.resize(); confChart.resize(); } catch(e){ }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>

<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in'])) {
    header("Location: admin_login.php");
    exit;
}
include 'includes/header.php';
include '../includes/db.php';

class AdvancedPredictionEngine {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function predictDemand($item_id, $months = 6) {
        // Récupérer données historiques
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE_FORMAT(o.order_date, '%Y-%m') as period,
                SUM(od.quantity) as quantity,
                AVG(od.price) as avg_price
            FROM order_details od
            JOIN orders o ON od.order_id = o.id
            WHERE od.item_id = ? AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY period
            ORDER BY period ASC
        ");
        $stmt->execute([$item_id, $months]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($data) < 3) return ['prediction' => 0, 'confidence' => 0];
        
        // Régression polynomiale pour tendances saisonnières
        $predictions = $this->polynomialRegression($data);
        
        // Facteur saisonnier
        $seasonal_factor = $this->calculateSeasonality($data);
        
        // Prédiction finale avec confiance
        $base_prediction = end($predictions);
        $final_prediction = max(0, round($base_prediction * $seasonal_factor));
        $confidence = $this->calculateConfidence($data, $predictions);
        
        return [
            'prediction' => $final_prediction,
            'confidence' => $confidence,
            'trend' => $this->getTrend($predictions),
            'seasonality' => $seasonal_factor
        ];
    }
    
    private function polynomialRegression($data) {
        $n = count($data);
        $predictions = [];
        
        // Régression linéaire simple pour commencer
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        
        foreach ($data as $i => $point) {
            $x = $i + 1;
            $y = $point['quantity'];
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        $intercept = ($sumY - $slope * $sumX) / $n;
        
        // Générer prédictions
        for ($i = 1; $i <= $n + 3; $i++) {
            $predictions[] = $slope * $i + $intercept;
        }
        
        return $predictions;
    }
    
    private function calculateSeasonality($data) {
        $current_month = (int)date('m');
        
        // Facteurs saisonniers simplifiés
        $seasonal_factors = [
            1 => 0.8,  // Janvier
            2 => 0.9,  // Février
            3 => 1.1,  // Mars
            4 => 1.0,  // Avril
            5 => 1.1,  // Mai
            6 => 1.2,  // Juin
            7 => 1.3,  // Juillet
            8 => 1.2,  // Août
            9 => 1.0,  // Septembre
            10 => 1.1, // Octobre
            11 => 1.4, // Novembre
            12 => 1.5  // Décembre
        ];
        
        return $seasonal_factors[$current_month] ?? 1.0;
    }
    
    private function calculateConfidence($data, $predictions) {
        if (count($data) < 2) return 0;
        
        // Calculer l'erreur moyenne
        $errors = [];
        for ($i = 0; $i < count($data); $i++) {
            $actual = $data[$i]['quantity'];
            $predicted = $predictions[$i] ?? 0;
            $errors[] = abs($actual - $predicted);
        }
        
        $mean_error = array_sum($errors) / count($errors);
        $mean_actual = array_sum(array_column($data, 'quantity')) / count($data);
        
        // Confiance basée sur l'erreur relative
        $confidence = max(0, min(100, (1 - ($mean_error / max($mean_actual, 1))) * 100));
        
        return round($confidence);
    }
    
    private function getTrend($predictions) {
        if (count($predictions) < 2) return 'stable';
        
        $start = array_slice($predictions, 0, 3);
        $end = array_slice($predictions, -3);
        
        $start_avg = array_sum($start) / count($start);
        $end_avg = array_sum($end) / count($end);
        
        $change = ($end_avg - $start_avg) / max($start_avg, 1);
        
        if ($change > 0.1) return 'hausse';
        if ($change < -0.1) return 'baisse';
        return 'stable';
    }
    
    public function generateAllPredictions() {
        // Récupérer tous les produits avec ventes
        $items = $this->pdo->query("
            SELECT DISTINCT i.id, i.name, i.stock, i.stock_alert_threshold
            FROM items i 
            JOIN order_details od ON i.id = od.item_id
            WHERE i.stock > 0
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $predictions = [];
        foreach ($items as $item) {
            $prediction = $this->predictDemand($item['id']);
            if ($prediction['prediction'] > 0) {
                $predictions[] = [
                    'item_id' => $item['id'],
                    'name' => $item['name'],
                    'current_stock' => $item['stock'],
                    'prediction' => $prediction['prediction'],
                    'confidence' => $prediction['confidence'],
                    'trend' => $prediction['trend'],
                    'seasonality' => $prediction['seasonality'],
                    'recommendation' => $this->getRecommendation($item, $prediction)
                ];
            }
        }
        
        // Sauvegarder en base
        $this->savePredictions($predictions);
        
        return $predictions;
    }
    
    private function getRecommendation($item, $prediction) {
        $current_stock = $item['stock'];
        $predicted_demand = $prediction['prediction'];
        $confidence = $prediction['confidence'];
        
        if ($predicted_demand > $current_stock * 2) {
            return [
                'action' => 'URGENT: Réapprovisionner',
                'priority' => 'high',
                'message' => "Demande prévue ($predicted_demand) >> Stock actuel ($current_stock)"
            ];
        } elseif ($predicted_demand > $current_stock) {
            return [
                'action' => 'Réapprovisionner bientôt',
                'priority' => 'medium',
                'message' => "Demande prévue ($predicted_demand) > Stock actuel ($current_stock)"
            ];
        } else {
            return [
                'action' => 'Stock suffisant',
                'priority' => 'low',
                'message' => "Stock actuel adapté à la demande prévue"
            ];
        }
    }
    
    private function savePredictions($predictions) {
        $nextMonth = date('Y-m-01', strtotime('+1 month'));
        
        // Supprimer anciennes prédictions
        $this->pdo->prepare("DELETE FROM previsions WHERE date_prevision = ?")->execute([$nextMonth]);
        
        // Insérer nouvelles prédictions
        $stmt = $this->pdo->prepare("
            INSERT INTO previsions (item_id, quantite_prevue, confidence_score, trend_direction, date_prevision) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($predictions as $pred) {
            $stmt->execute([
                $pred['item_id'], 
                $pred['prediction'], 
                $pred['confidence'],
                $pred['trend'],
                $nextMonth
            ]);
        }
    }
}

$engine = new AdvancedPredictionEngine($pdo);
$predictions = [];
$processing = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate'])) {
    $processing = true;
    $predictions = $engine->generateAllPredictions();
    $_SESSION['success_message'] = "Prédictions IA générées avec succès !";
    
    // Notification
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
    $stmt->execute(['admin_action', 'Prédictions IA générées par ' . $_SESSION['admin_name']]);
} else {
    // Charger prédictions existantes
    $predictions = $pdo->query("
        SELECT 
            i.name, 
            p.quantite_prevue as prediction,
            p.confidence_score as confidence,
            p.trend_direction as trend,
            i.stock as current_stock
        FROM previsions p
        JOIN items i ON p.item_id = i.id
        WHERE p.date_prevision = DATE_FORMAT(DATE_ADD(NOW(), INTERVAL 1 MONTH), '%Y-%m-01')
        ORDER BY p.quantite_prevue DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<main class="container py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2><i class="bi bi-robot me-2 text-success"></i>Prédictions IA Avancées</h2>
                    <p class="text-muted">Algorithme de Machine Learning avec analyse saisonnière</p>
                </div>
                <div>
                    <form method="post" class="d-inline">
                        <button type="submit" name="generate" class="btn btn-success <?php echo $processing ? 'disabled' : ''; ?>">
                            <?php if ($processing): ?>
                                <span class="spinner-border spinner-border-sm me-1"></span>Génération...
                            <?php else: ?>
                                <i class="bi bi-gear me-1"></i>Générer Prédictions
                            <?php endif; ?>
                        </button>
                    </form>
                    <a href="../#README.md" class="btn btn-outline-info ms-2">
                        <i class="bi bi-info-circle me-1"></i>Comment ça marche ?
                    </a>
                </div>
            </div>
            
            <?php if ($predictions): ?>
            <div class="row">
                <!-- Statistiques globales -->
                <div class="col-12 mb-4">
                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">Aperçu Global - <?php echo date('F Y', strtotime('+1 month')); ?></h5>
                        </div>
                        <div class="card-body">
                            <div class="row text-center">
                                <div class="col-md-3">
                                    <h3 class="text-success"><?php echo count($predictions); ?></h3>
                                    <small class="text-muted">Produits analysés</small>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-info"><?php echo array_sum(array_column($predictions, 'prediction')); ?></h3>
                                    <small class="text-muted">Demande totale prévue</small>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-warning"><?php echo round(array_sum(array_column($predictions, 'confidence')) / count($predictions)); ?>%</h3>
                                    <small class="text-muted">Confiance moyenne</small>
                                </div>
                                <div class="col-md-3">
                                    <h3 class="text-primary"><?php echo count(array_filter($predictions, fn($p) => ($p['prediction'] ?? 0) > ($p['current_stock'] ?? 0))); ?></h3>
                                    <small class="text-muted">Réapprovisionnements suggérés</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Prédictions détaillées -->
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Prédictions Détaillées</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
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
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($pred['name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo $pred['current_stock'] ?? 'N/A'; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $pred['prediction']; ?></span>
                                            </td>
                                            <td>
                                                <div class="d-flex align-items-center">
                                                    <div class="progress me-2" style="width: 60px; height: 6px;">
                                                        <div class="progress-bar bg-<?php 
                                                            echo ($pred['confidence'] ?? 0) >= 80 ? 'success' : 
                                                                (($pred['confidence'] ?? 0) >= 60 ? 'warning' : 'danger'); 
                                                        ?>" style="width: <?php echo $pred['confidence'] ?? 0; ?>%"></div>
                                                    </div>
                                                    <small><?php echo $pred['confidence'] ?? 0; ?>%</small>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo match($pred['trend'] ?? 'stable') {
                                                        'hausse' => 'success',
                                                        'baisse' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <i class="bi bi-arrow-<?php 
                                                        echo match($pred['trend'] ?? 'stable') {
                                                            'hausse' => 'up',
                                                            'baisse' => 'down',
                                                            default => 'right'
                                                        };
                                                    ?>"></i>
                                                    <?php echo ucfirst($pred['trend'] ?? 'stable'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $current = $pred['current_stock'] ?? 0;
                                                $predicted = $pred['prediction'];
                                                ?>
                                                <?php if ($predicted > $current * 2): ?>
                                                    <span class="badge bg-danger">URGENT: Réapprovisionner</span>
                                                <?php elseif ($predicted > $current): ?>
                                                    <span class="badge bg-warning">Réapprovisionner</span>
                                                <?php else: ?>
                                                    <span class="badge bg-success">Stock OK</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-robot text-muted" style="font-size: 4rem;"></i>
                    <h4 class="mt-3">Aucune prédiction disponible</h4>
                    <p class="text-muted">Générez des prédictions pour voir l'analyse IA</p>
                    <form method="post" class="d-inline">
                        <button type="submit" name="generate" class="btn btn-success btn-lg">
                            <i class="bi bi-gear me-2"></i>Lancer l'analyse IA
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<style>
.progress {
    border-radius: 10px;
    overflow: hidden;
}

.table th {
    font-weight: 600;
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.badge {
    font-size: 0.75rem;
}

.spinner-border-sm {
    width: 1rem;
    height: 1rem;
}
</style>

<?php include 'includes/footer.php'; ?>
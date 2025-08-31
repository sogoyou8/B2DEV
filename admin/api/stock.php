<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

include '../../includes/db.php';
require_once '../../includes/classes/Product.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Récupérer les informations de stock
            $critical_stock = $pdo->query("
                SELECT id, name, stock, stock_alert_threshold 
                FROM items 
                WHERE stock <= stock_alert_threshold 
                ORDER BY stock ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $out_of_stock = $pdo->query("
                SELECT id, name, stock 
                FROM items 
                WHERE stock = 0
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $stock_stats = $pdo->query("
                SELECT 
                    COUNT(*) as total_products,
                    COUNT(CASE WHEN stock = 0 THEN 1 END) as out_of_stock_count,
                    COUNT(CASE WHEN stock <= stock_alert_threshold AND stock > 0 THEN 1 END) as low_stock_count,
                    AVG(stock) as avg_stock
                FROM items
            ")->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'critical_stock' => $critical_stock,
                'out_of_stock' => $out_of_stock,
                'stats' => $stock_stats
            ]);
            break;
            
        case 'PUT':
            // Mettre à jour le stock d'un produit via la couche Product pour logging/notifications
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            $stock = $input['stock'] ?? null;
            
            if (!$id || $stock === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Données manquantes']);
                exit;
            }

            // Charger et utiliser Product::updateStock pour centraliser notifications/log
            $product = new Product($pdo, $id);
            if (!$product->getId()) {
                http_response_code(404);
                echo json_encode(['error' => 'Produit introuvable']);
                exit;
            }

            $adminId = $_SESSION['admin_id'] ?? null;
            $success = $product->updateStock(intval($stock), $adminId);

            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Stock mis à jour' : 'Erreur lors de la mise à jour'
            ]);
            break;
            
        case 'POST':
            // Alerte stock pour tous les produits critiques
            $input = json_decode(file_get_contents('php://input'), true);
            $alert_type = $input['alert_type'] ?? 'email';
            
            $critical_products = $pdo->query("
                SELECT name, stock, stock_alert_threshold 
                FROM items 
                WHERE stock <= stock_alert_threshold
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            if ($critical_products) {
                foreach ($critical_products as $product) {
                    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
                    $stmt->execute([
                        'stock',
                        "Alerte stock : {$product['name']} ({$product['stock']} restants, seuil {$product['stock_alert_threshold']})"
                    ]);
                }
                
                echo json_encode([
                    'success' => true,
                    'count' => count($critical_products),
                    'message' => count($critical_products) . ' alertes stock créées'
                ]);
            } else {
                echo json_encode([
                    'success' => true,
                    'count' => 0,
                    'message' => 'Aucun produit en stock critique'
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Méthode non autorisée']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : ' . $e->getMessage()]);
}
?>
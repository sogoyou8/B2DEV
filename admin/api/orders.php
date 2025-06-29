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

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Récupérer les statistiques des commandes
            $pending_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();
            $today_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn();
            $total_orders = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
            $recent_orders = $pdo->query("
                SELECT o.id, u.name as user_name, o.total_price, o.status, o.order_date 
                FROM orders o 
                JOIN users u ON o.user_id = u.id 
                ORDER BY o.order_date DESC 
                LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            $revenue_today = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn();
            $revenue_month = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE MONTH(order_date) = MONTH(NOW())")->fetchColumn();
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'pending_orders' => $pending_orders,
                    'today_orders' => $today_orders,
                    'total_orders' => $total_orders,
                    'revenue_today' => $revenue_today,
                    'revenue_month' => $revenue_month
                ],
                'recent_orders' => $recent_orders
            ]);
            break;
            
        case 'PUT':
            // Mettre à jour le statut d'une commande
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            $status = $input['status'] ?? null;
            
            if (!$id || !$status) {
                http_response_code(400);
                echo json_encode(['error' => 'Données manquantes']);
                exit;
            }
            
            // Vérifier que le statut est valide
            $valid_statuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
            if (!in_array($status, $valid_statuses)) {
                http_response_code(400);
                echo json_encode(['error' => 'Statut invalide']);
                exit;
            }
            
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $success = $stmt->execute([$status, $id]);
            
            if ($success) {
                // Créer une notification pour certains changements de statut
                if (in_array($status, ['shipped', 'delivered'])) {
                    $orderQuery = $pdo->prepare("SELECT o.id, u.name FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
                    $orderQuery->execute([$id]);
                    $order = $orderQuery->fetch();
                    
                    $notifStmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
                    $notifStmt->execute([
                        'admin_action',
                        "Commande #{$order['id']} de {$order['name']} marquée comme '{$status}' par " . $_SESSION['admin_name']
                    ]);
                }
            }
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Statut mis à jour' : 'Erreur lors de la mise à jour'
            ]);
            break;
            
        case 'POST':
            // Créer un rapport de commandes
            $input = json_decode(file_get_contents('php://input'), true);
            $period = $input['period'] ?? 'today';
            
            $where_clause = match($period) {
                'today' => "WHERE DATE(order_date) = CURDATE()",
                'week' => "WHERE order_date >= DATE_SUB(NOW(), INTERVAL 1 WEEK)",
                'month' => "WHERE order_date >= DATE_SUB(NOW(), INTERVAL 1 MONTH)",
                default => "WHERE DATE(order_date) = CURDATE()"
            };
            
            $orders_report = $pdo->query("
                SELECT 
                    COUNT(*) as total_orders,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
                    COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
                    COUNT(CASE WHEN status = 'shipped' THEN 1 END) as shipped,
                    COUNT(CASE WHEN status = 'delivered' THEN 1 END) as delivered,
                    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
                    COALESCE(SUM(total_price), 0) as total_revenue,
                    COALESCE(AVG(total_price), 0) as avg_order_value
                FROM orders {$where_clause}
            ")->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'period' => $period,
                'report' => $orders_report
            ]);
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
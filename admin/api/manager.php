<?php
header('Content-Type: application/json');

session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit;
}

include '../../includes/db.php';
require_once '../../includes/classes/Order.php';
require_once '../../includes/classes/Analytics.php';

$endpoint = $_GET['endpoint'] ?? '';
$action = $_GET['action'] ?? '';

try {
    switch ($endpoint) {
        case 'dashboard':
            $analytics = new Analytics($pdo);
            echo json_encode([
                'success' => true,
                'data' => $analytics->generateReport(30)
            ]);
            break;
            
        case 'quick-stats':
            $stats = [
                'orders_today' => Order::getTodayStats($pdo),
                'notifications_count' => $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn(),
                'low_stock_count' => $pdo->query("SELECT COUNT(*) FROM items WHERE stock <= stock_alert_threshold")->fetchColumn(),
                'pending_orders_count' => $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn()
            ];
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'health':
            // Vérification de santé de l'API
            $health = [
                'status' => 'OK',
                'timestamp' => date('Y-m-d H:i:s'),
                'database' => 'connected',
                'session' => isset($_SESSION['admin_logged_in']) ? 'active' : 'inactive',
                'endpoints' => [
                    'notifications' => file_exists('notifications.php'),
                    'orders' => file_exists('orders.php'),
                    'stock' => file_exists('stock.php'),
                    'analytics' => file_exists('analytics.php')
                ]
            ];
            
            echo json_encode(['success' => true, 'data' => $health]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Endpoint invalide']);
    }
    
} catch (Exception $e) {
    error_log("Erreur API Manager: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>
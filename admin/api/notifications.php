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
            // Récupérer toutes les notifications non lues
            $query = $pdo->query("SELECT * FROM notifications WHERE is_read = 0 ORDER BY created_at DESC");
            $notifications = $query->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode([
                'success' => true,
                'data' => $notifications,
                'count' => count($notifications)
            ]);
            break;
            
        case 'POST':
            // Marquer une notification comme lue
            $input = json_decode(file_get_contents('php://input'), true);
            $id = $input['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'ID manquant']);
                exit;
            }
            
            // Vérifier que la notification existe
            $check = $pdo->prepare("SELECT id FROM notifications WHERE id = ?");
            $check->execute([$id]);
            
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Notification non trouvée']);
                exit;
            }
            
            // Marquer comme lue
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = ?");
            $success = $stmt->execute([$id]);
            
            echo json_encode([
                'success' => $success,
                'message' => $success ? 'Notification marquée comme lue' : 'Erreur lors du marquage'
            ]);
            break;
            
        case 'DELETE':
            // Marquer toutes les notifications comme lues
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE is_read = 0");
            $success = $stmt->execute();
            $count = $stmt->rowCount();
            
            echo json_encode([
                'success' => $success,
                'count' => $count,
                'message' => "$count notification(s) marquée(s) comme lue(s)"
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
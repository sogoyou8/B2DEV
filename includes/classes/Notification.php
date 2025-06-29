<?php
class Notification {
    private $pdo;
    
    const TYPE_IMPORTANT = 'important';
    const TYPE_SECURITY = 'security';
    const TYPE_STOCK = 'stock';
    const TYPE_ADMIN_ACTION = 'admin_action';
    const TYPE_SYSTEM = 'system';
    const TYPE_ERROR = 'error';
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // === CRÉATION DE NOTIFICATIONS ===
    
    public function create($type, $message, $persistent = false) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (type, message, is_persistent, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            return $stmt->execute([$type, $message, $persistent ? 1 : 0]);
        } catch (PDOException $e) {
            error_log("Notification create Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function createImportant($message, $persistent = true) {
        return $this->create(self::TYPE_IMPORTANT, $message, $persistent);
    }
    
    public function createSecurity($message, $persistent = true) {
        return $this->create(self::TYPE_SECURITY, $message, $persistent);
    }
    
    public function createStock($message, $persistent = false) {
        return $this->create(self::TYPE_STOCK, $message, $persistent);
    }
    
    public function createAdminAction($message, $persistent = false) {
        return $this->create(self::TYPE_ADMIN_ACTION, $message, $persistent);
    }
    
    public function createSystem($message, $persistent = false) {
        return $this->create(self::TYPE_SYSTEM, $message, $persistent);
    }
    
    public function createError($message, $persistent = true) {
        return $this->create(self::TYPE_ERROR, $message, $persistent);
    }
    
    // === RÉCUPÉRATION DE NOTIFICATIONS ===
    
    public function getUnread($limit = null) {
        try {
            $limit_clause = $limit ? "LIMIT " . intval($limit) : "";
            $stmt = $this->pdo->query("
                SELECT * FROM notifications 
                WHERE is_read = 0 
                ORDER BY created_at DESC 
                {$limit_clause}
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Notification getUnread Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getByType($type, $include_read = false) {
        try {
            $read_condition = $include_read ? "" : "AND is_read = 0";
            $stmt = $this->pdo->prepare("
                SELECT * FROM notifications 
                WHERE type = ? {$read_condition}
                ORDER BY created_at DESC
            ");
            $stmt->execute([$type]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Notification getByType Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getAll($filters = []) {
        $where_conditions = ["1=1"];
        $params = [];
        
        if (isset($filters['type'])) {
            $where_conditions[] = "type = ?";
            $params[] = $filters['type'];
        }
        
        if (isset($filters['read_status'])) {
            if ($filters['read_status'] === 'unread') {
                $where_conditions[] = "is_read = 0";
            } elseif ($filters['read_status'] === 'read') {
                $where_conditions[] = "is_read = 1";
            }
        }
        
        if (isset($filters['persistent'])) {
            $where_conditions[] = "is_persistent = ?";
            $params[] = $filters['persistent'] ? 1 : 0;
        }
        
        if (isset($filters['date_from'])) {
            $where_conditions[] = "created_at >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (isset($filters['date_to'])) {
            $where_conditions[] = "created_at <= ?";
            $params[] = $filters['date_to'];
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $order_by = $filters['order_by'] ?? 'created_at DESC';
        $limit = isset($filters['limit']) ? "LIMIT " . intval($filters['limit']) : "";
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM notifications 
                WHERE {$where_clause}
                ORDER BY {$order_by}
                {$limit}
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Notification getAll Error: " . $e->getMessage());
            return [];
        }
    }
    
    // === MARQUAGE COMME LUE ===
    
    public function markAsRead($id) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ?
            ");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("Notification markAsRead Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function markAllAsRead($type = null) {
        try {
            if ($type) {
                $stmt = $this->pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE is_read = 0 AND type = ?
                ");
                $success = $stmt->execute([$type]);
            } else {
                $stmt = $this->pdo->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE is_read = 0
                ");
                $success = $stmt->execute();
            }
            
            return [
                'success' => $success,
                'count' => $stmt->rowCount()
            ];
        } catch (PDOException $e) {
            error_log("Notification markAllAsRead Error: " . $e->getMessage());
            return ['success' => false, 'count' => 0];
        }
    }
    
    // === SUPPRESSION ===
    
    public function delete($id) {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM notifications WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            error_log("Notification delete Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteOldRead($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM notifications 
                WHERE is_read = 1 
                  AND is_persistent = 0 
                  AND read_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $success = $stmt->execute([$days]);
            return [
                'success' => $success,
                'count' => $stmt->rowCount()
            ];
        } catch (PDOException $e) {
            error_log("Notification deleteOldRead Error: " . $e->getMessage());
            return ['success' => false, 'count' => 0];
        }
    }
    
    // === STATISTIQUES ===
    
    public function getStats() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total,
                    COUNT(CASE WHEN is_read = 0 THEN 1 END) as unread,
                    COUNT(CASE WHEN is_read = 1 THEN 1 END) as read,
                    COUNT(CASE WHEN is_persistent = 1 THEN 1 END) as persistent,
                    COUNT(CASE WHEN type = 'important' THEN 1 END) as important,
                    COUNT(CASE WHEN type = 'security' THEN 1 END) as security,
                    COUNT(CASE WHEN type = 'stock' THEN 1 END) as stock,
                    COUNT(CASE WHEN type = 'admin_action' THEN 1 END) as admin_actions,
                    COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h
                FROM notifications
            ");
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Notification getStats Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getCountByType() {
        try {
            $stmt = $this->pdo->query("
                SELECT type, COUNT(*) as count
                FROM notifications 
                WHERE is_read = 0
                GROUP BY type
            ");
            $result = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$row['type']] = $row['count'];
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Notification getCountByType Error: " . $e->getMessage());
            return [];
        }
    }
    
    // === MÉTHODES SPÉCIALISÉES ===
    
    public function createLoginFailure($email, $ip = null) {
        $ip_info = $ip ? " depuis l'IP {$ip}" : "";
        return $this->createSecurity(
            "Tentative de connexion échouée pour l'email : {$email}{$ip_info}",
            true
        );
    }
    
    public function createStockAlert($product_name, $current_stock, $threshold = null) {
        if ($current_stock == 0) {
            return $this->createImportant(
                "Le produit '{$product_name}' est en rupture de stock !",
                true
            );
        } else {
            $threshold_info = $threshold ? " (seuil {$threshold})" : "";
            return $this->createStock(
                "Stock faible pour '{$product_name}' : {$current_stock} restant(s){$threshold_info}",
                false
            );
        }
    }
    
    public function createProductAction($action, $product_name, $admin_name) {
        $actions = [
            'created' => 'créé',
            'updated' => 'modifié', 
            'deleted' => 'supprimé'
        ];
        
        $action_text = $actions[$action] ?? $action;
        return $this->createAdminAction(
            "Produit '{$product_name}' {$action_text} par {$admin_name}",
            false
        );
    }
    
    public function createOrderStatusChange($order_id, $new_status, $admin_name = null) {
        $admin_info = $admin_name ? " par {$admin_name}" : "";
        return $this->createAdminAction(
            "Commande #{$order_id} marquée comme '{$new_status}'{$admin_info}",
            false
        );
    }
    
    // === NETTOYAGE AUTOMATIQUE ===
    
    public function cleanupOldNotifications() {
        $result = [
            'deleted_read' => 0,
            'deleted_old_system' => 0,
            'success' => true
        ];
        
        try {
            // Supprimer les notifications lues non-persistantes de plus de 30 jours
            $stmt1 = $this->pdo->prepare("
                DELETE FROM notifications 
                WHERE is_read = 1 
                  AND is_persistent = 0 
                  AND read_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $stmt1->execute();
            $result['deleted_read'] = $stmt1->rowCount();
            
            // Supprimer les notifications système de plus de 7 jours
            $stmt2 = $this->pdo->prepare("
                DELETE FROM notifications 
                WHERE type = 'system' 
                  AND created_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt2->execute();
            $result['deleted_old_system'] = $stmt2->rowCount();
            
        } catch (PDOException $e) {
            error_log("Notification cleanupOldNotifications Error: " . $e->getMessage());
            $result['success'] = false;
        }
        
        return $result;
    }
    
    // === VALIDATION ===
    
    public function isValidType($type) {
        return in_array($type, [
            self::TYPE_IMPORTANT,
            self::TYPE_SECURITY, 
            self::TYPE_STOCK,
            self::TYPE_ADMIN_ACTION,
            self::TYPE_SYSTEM,
            self::TYPE_ERROR
        ]);
    }
    
    public function exists($id) {
        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM notifications WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Notification exists Error: " . $e->getMessage());
            return false;
        }
    }
}
?>
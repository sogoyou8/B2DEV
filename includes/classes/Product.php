<?php
class Product {
    private $pdo;
    private $id;
    private $data = [];
    
    public function __construct($pdo, $id = null) {
        $this->pdo = $pdo;
        if ($id) {
            $this->id = $id;
            $this->loadData();
        }
    }
    
    // === GETTERS ET SETTERS ===
    
    public function getId() {
        return $this->id;
    }
    
    public function getName() {
        return $this->data['name'] ?? '';
    }
    
    public function getDescription() {
        return $this->data['description'] ?? '';
    }
    
    public function getPrice() {
        return floatval($this->data['price'] ?? 0);
    }
    
    public function getStock() {
        return intval($this->data['stock'] ?? 0);
    }
    
    public function getStockAlertThreshold() {
        return intval($this->data['stock_alert_threshold'] ?? 5);
    }
    
    public function getCreatedAt() {
        return $this->data['created_at'] ?? null;
    }
    
    public function getData() {
        return $this->data;
    }
    
    // === MÉTHODES DE CHARGEMENT ===
    
    private function loadData() {
        if (!$this->id) return false;
        
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM items WHERE id = ?");
            $stmt->execute([$this->id]);
            $this->data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            return !empty($this->data);
        } catch (PDOException $e) {
            error_log("Product loadData Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getImages() {
        if (!$this->id) return [];
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, image, position 
                FROM product_images 
                WHERE product_id = ? 
                ORDER BY position ASC
            ");
            $stmt->execute([$this->id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Product getImages Error: " . $e->getMessage());
            return [];
        }
    }
    
    public function getSalesStats($days = 30) {
        if (!$this->id) return null;
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT o.id) as orders_count,
                    SUM(od.quantity) as total_sold,
                    SUM(od.quantity * od.price) as revenue,
                    AVG(od.price) as avg_price,
                    COUNT(DISTINCT o.user_id) as unique_buyers
                FROM order_details od
                JOIN orders o ON od.order_id = o.id
                WHERE od.item_id = ? 
                  AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                  AND o.status IN ('delivered', 'shipped', 'processing')
            ");
            $stmt->execute([$this->id, $days]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Product getSalesStats Error: " . $e->getMessage());
            return null;
        }
    }
    
    // === MÉTHODES DE MODIFICATION ===
    
    public function updateStock($new_stock, $admin_id = null) {
        if (!$this->id) return false;
        
        try {
            $old_stock = $this->getStock();
            
            $stmt = $this->pdo->prepare("UPDATE items SET stock = ? WHERE id = ?");
            $success = $stmt->execute([$new_stock, $this->id]);
            
            if ($success) {
                $this->data['stock'] = $new_stock;
                
                // Créer une notification si stock critique
                if ($new_stock == 0) {
                    $this->createStockAlert('critical', $admin_id);
                } elseif ($new_stock <= $this->getStockAlertThreshold()) {
                    $this->createStockAlert('low', $admin_id);
                }
                
                // Log du changement
                $this->logStockChange($old_stock, $new_stock, $admin_id);
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log("Product updateStock Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updatePrice($new_price, $admin_id = null) {
        if (!$this->id) return false;
        
        try {
            $old_price = $this->getPrice();
            
            $stmt = $this->pdo->prepare("UPDATE items SET price = ? WHERE id = ?");
            $success = $stmt->execute([$new_price, $this->id]);
            
            if ($success) {
                $this->data['price'] = $new_price;
                
                // Log du changement de prix
                $this->logPriceChange($old_price, $new_price, $admin_id);
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log("Product updatePrice Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function update($data, $admin_id = null) {
        if (!$this->id) return false;
        
        $allowed_fields = ['name', 'description', 'price', 'stock', 'stock_alert_threshold'];
        $update_fields = [];
        $values = [];
        
        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($update_fields)) return false;
        
        try {
            $values[] = $this->id;
            $stmt = $this->pdo->prepare("UPDATE items SET " . implode(', ', $update_fields) . " WHERE id = ?");
            $success = $stmt->execute($values);
            
            if ($success) {
                // Recharger les données
                $this->loadData();
                
                // Créer une notification de modification
                if ($admin_id) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO notifications (type, message, is_persistent) 
                        VALUES (?, ?, 0)
                    ");
                    $stmt->execute([
                        'admin_action',
                        "Produit '{$this->getName()}' modifié par admin ID {$admin_id}"
                    ]);
                }
            }
            
            return $success;
        } catch (PDOException $e) {
            error_log("Product update Error: " . $e->getMessage());
            return false;
        }
    }
    
    // === MÉTHODES D'ÉTAT ===
    
    public function isLowStock() {
        return $this->getStock() <= $this->getStockAlertThreshold() && $this->getStock() > 0;
    }
    
    public function isOutOfStock() {
        return $this->getStock() == 0;
    }
    
    public function isAvailable() {
        return $this->getStock() > 0;
    }
    
    public function canPurchase($quantity = 1) {
        return $this->getStock() >= $quantity;
    }
    
    // === MÉTHODES DE CRÉATION ===
    
    public static function create($pdo, $data, $admin_id = null) {
        $required_fields = ['name', 'description', 'price', 'stock'];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new InvalidArgumentException("Le champ '$field' est requis");
            }
        }
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO items (name, description, price, stock, stock_alert_threshold, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stock_alert_threshold = $data['stock_alert_threshold'] ?? 5;
            
            $success = $stmt->execute([
                $data['name'],
                $data['description'], 
                $data['price'],
                $data['stock'],
                $stock_alert_threshold
            ]);
            
            if ($success) {
                $product_id = $pdo->lastInsertId();
                
                // Créer une notification de création
                if ($admin_id) {
                    $notif_stmt = $pdo->prepare("
                        INSERT INTO notifications (type, message, is_persistent) 
                        VALUES (?, ?, 0)
                    ");
                    $notif_stmt->execute([
                        'admin_action',
                        "Nouveau produit '{$data['name']}' créé par admin ID {$admin_id}"
                    ]);
                }
                
                return new self($pdo, $product_id);
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Product create Error: " . $e->getMessage());
            throw new Exception("Erreur lors de la création du produit");
        }
    }
    
    // === MÉTHODES DE SUPPRESSION ===
    
    public function delete($admin_id = null) {
        if (!$this->id) return false;
        
        try {
            $this->pdo->beginTransaction();
            
            // Supprimer les images associées
            $images = $this->getImages();
            foreach ($images as $image) {
                $image_path = "../assets/images/" . $image['image'];
                if (file_exists($image_path)) {
                    unlink($image_path);
                }
            }
            
            // Supprimer les enregistrements de la base
            $this->pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$this->id]);
            $this->pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$this->id]);
            $this->pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$this->id]);
            $this->pdo->prepare("DELETE FROM previsions WHERE item_id = ?")->execute([$this->id]);
            
            // Supprimer le produit
            $stmt = $this->pdo->prepare("DELETE FROM items WHERE id = ?");
            $success = $stmt->execute([$this->id]);
            
            if ($success) {
                // Créer une notification de suppression
                if ($admin_id) {
                    $notif_stmt = $this->pdo->prepare("
                        INSERT INTO notifications (type, message, is_persistent) 
                        VALUES (?, ?, 0)
                    ");
                    $notif_stmt->execute([
                        'admin_action',
                        "Produit '{$this->getName()}' supprimé par admin ID {$admin_id}"
                    ]);
                }
                
                $this->pdo->commit();
                return true;
            } else {
                $this->pdo->rollBack();
                return false;
            }
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("Product delete Error: " . $e->getMessage());
            return false;
        }
    }
    
    // === MÉTHODES PRIVÉES ===
    
    private function createStockAlert($type, $admin_id = null) {
        try {
            $message = match($type) {
                'critical' => "Le produit '{$this->getName()}' est en rupture de stock !",
                'low' => "Stock faible pour '{$this->getName()}' ({$this->getStock()} restants, seuil {$this->getStockAlertThreshold()})",
                default => "Alerte stock pour '{$this->getName()}'"
            };
            
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (type, message, is_persistent) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $type === 'critical' ? 'important' : 'stock',
                $message,
                $type === 'critical' ? 1 : 0
            ]);
        } catch (PDOException $e) {
            error_log("Product createStockAlert Error: " . $e->getMessage());
        }
    }
    
    private function logStockChange($old_stock, $new_stock, $admin_id = null) {
        // Optionnel : table de logs pour traçabilité
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (type, message, is_persistent) 
                VALUES (?, ?, 0)
            ");
            $stmt->execute([
                'admin_action',
                "Stock du produit '{$this->getName()}' modifié: {$old_stock} → {$new_stock}" . 
                ($admin_id ? " (admin ID {$admin_id})" : "")
            ]);
        } catch (PDOException $e) {
            error_log("Product logStockChange Error: " . $e->getMessage());
        }
    }
    
    private function logPriceChange($old_price, $new_price, $admin_id = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO notifications (type, message, is_persistent) 
                VALUES (?, ?, 0)
            ");
            $stmt->execute([
                'admin_action',
                "Prix du produit '{$this->getName()}' modifié: {$old_price}€ → {$new_price}€" . 
                ($admin_id ? " (admin ID {$admin_id})" : "")
            ]);
        } catch (PDOException $e) {
            error_log("Product logPriceChange Error: " . $e->getMessage());
        }
    }
    
    // === MÉTHODES STATIQUES UTILITAIRES ===
    
    public static function getAll($pdo, $filters = []) {
        $where_conditions = [];
        $params = [];
        
        if (isset($filters['search']) && !empty($filters['search'])) {
            $where_conditions[] = "(name LIKE ? OR description LIKE ?)";
            $search_term = '%' . $filters['search'] . '%';
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        if (isset($filters['min_price'])) {
            $where_conditions[] = "price >= ?";
            $params[] = $filters['min_price'];
        }
        
        if (isset($filters['max_price'])) {
            $where_conditions[] = "price <= ?";
            $params[] = $filters['max_price'];
        }
        
        if (isset($filters['in_stock']) && $filters['in_stock']) {
            $where_conditions[] = "stock > 0";
        }
        
        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $where_conditions[] = "stock <= stock_alert_threshold AND stock > 0";
        }
        
        $where_clause = !empty($where_conditions) ? 
            "WHERE " . implode(' AND ', $where_conditions) : "";
        
        $order_by = match($filters['sort'] ?? 'name') {
            'price_asc' => 'ORDER BY price ASC',
            'price_desc' => 'ORDER BY price DESC', 
            'stock_asc' => 'ORDER BY stock ASC',
            'stock_desc' => 'ORDER BY stock DESC',
            'created_desc' => 'ORDER BY created_at DESC',
            default => 'ORDER BY name ASC'
        };
        
        $limit = isset($filters['limit']) ? "LIMIT " . intval($filters['limit']) : "";
        
        try {
            $stmt = $pdo->prepare("
                SELECT * FROM items 
                {$where_clause} 
                {$order_by} 
                {$limit}
            ");
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Product getAll Error: " . $e->getMessage());
            return [];
        }
    }
    
    public static function getLowStock($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT * FROM items 
                WHERE stock <= stock_alert_threshold AND stock > 0 
                ORDER BY stock ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Product getLowStock Error: " . $e->getMessage());
            return [];
        }
    }
    
    public static function getOutOfStock($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT * FROM items 
                WHERE stock = 0 
                ORDER BY name ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Product getOutOfStock Error: " . $e->getMessage());
            return [];
        }
    }
}
?>
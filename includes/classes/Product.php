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
    
    public function getUpdatedAt() {
        return $this->data['updated_at'] ?? null;
    }
    
    public function isActive() {
        // If column missing in DB, default to true
        if (!array_key_exists('is_active', $this->data)) return true;
        return (int)($this->data['is_active'] ?? 1) === 1;
    }
    
    public function getDeletedAt() {
        return $this->data['deleted_at'] ?? null;
    }
    
    public function getCategory() {
        return $this->data['category'] ?? null;
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
                SELECT id, image, position, caption
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
            // If caller has opened a transaction, obtain a FOR UPDATE lock to avoid races
            if ($this->pdo->inTransaction()) {
                $lockStmt = $this->pdo->prepare("SELECT stock, stock_alert_threshold, name FROM items WHERE id = ? FOR UPDATE");
                $lockStmt->execute([$this->id]);
                $row = $lockStmt->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    return false;
                }
                $old_stock = intval($row['stock']);
                $threshold = isset($row['stock_alert_threshold']) ? intval($row['stock_alert_threshold']) : $this->getStockAlertThreshold();
            } else {
                // No external transaction: read from cached/internal data
                $old_stock = $this->getStock();
                $threshold = $this->getStockAlertThreshold();
            }
            
            $stmt = $this->pdo->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE id = ?");
            $success = $stmt->execute([intval($new_stock), $this->id]);
            
            if ($success) {
                $this->data['stock'] = intval($new_stock);
                
                // Créer une notification si stock critique
                if ($new_stock == 0) {
                    $this->createStockAlert('critical', $admin_id);
                } elseif ($new_stock <= $threshold) {
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

    /**
     * Décrémente le stock de manière sécurisée et centralisée.
     * - Verrouille la ligne produit (SELECT ... FOR UPDATE)
     * - Vérifie la disponibilité
     * - Met à jour le stock (stock = stock - $qty)
     * - Crée les notifications si seuil atteint / rupture
     * - Log l'opération
     *
     * Retourne true si succès, false sinon.
     */
    public function decrementStock(int $qty, $admin_id = null) {
        if (!$this->id) return false;
        $qty = max(0, $qty);
        if ($qty <= 0) return false;

        try {
            // Démarrer transaction si pas déjà en transaction
            $ownTx = false;
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $ownTx = true;
            }

            // Verrouiller la ligne produit
            $stmt = $this->pdo->prepare("SELECT stock, stock_alert_threshold, name FROM items WHERE id = ? FOR UPDATE");
            $stmt->execute([$this->id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                if ($ownTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
                return false;
            }

            $available = intval($row['stock']);
            $threshold = isset($row['stock_alert_threshold']) ? intval($row['stock_alert_threshold']) : $this->getStockAlertThreshold();

            if ($available < $qty) {
                // stock insuffisant -> rollback and fail
                if ($ownTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
                return false;
            }

            $new_stock = $available - $qty;

            $upd = $this->pdo->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$new_stock, $this->id]);

            // Notifications / alertes
            if ($new_stock == 0) {
                $this->createStockAlert('critical', $admin_id);
            } elseif ($new_stock <= $threshold) {
                $this->createStockAlert('low', $admin_id);
            }

            // Log du changement
            $this->logStockChange($available, $new_stock, $admin_id);

            // Commit si on a ouvert la transaction
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }

            // Recharger les données internes pour rester cohérent
            $this->loadData();

            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Product decrementStock Error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Incrémente le stock de manière sécurisée et centralisée.
     * - Verrouille la ligne produit (SELECT ... FOR UPDATE)
     * - Met à jour le stock (stock = stock + $qty)
     * - Crée notification de réapprovisionnement si nécessaire
     * - Log l'opération
     *
     * Retourne true si succès, false sinon.
     */
    public function incrementStock(int $qty, $admin_id = null) {
        if (!$this->id) return false;
        $qty = max(0, $qty);
        if ($qty <= 0) return false;

        try {
            $ownTx = false;
            if (!$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $ownTx = true;
            }

            // Verrouiller la ligne produit
            $stmt = $this->pdo->prepare("SELECT stock, stock_alert_threshold, name FROM items WHERE id = ? FOR UPDATE");
            $stmt->execute([$this->id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                if ($ownTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
                return false;
            }

            $old_stock = intval($row['stock']);
            $new_stock = $old_stock + $qty;

            $upd = $this->pdo->prepare("UPDATE items SET stock = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$new_stock, $this->id]);

            // Si on passe de 0 → >0, créer notification de réapprovisionnement
            if ($old_stock == 0 && $new_stock > 0) {
                try {
                    $msg = "Produit '{$row['name']}' réapprovisionné ({$qty} ajoutés, maintenant {$new_stock})";
                    $note = $this->pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
                    $note->execute(['stock', $msg, 0]);
                } catch (Exception $_) {
                    // ignore notification failure
                }
            }

            // Log du changement
            $this->logStockChange($old_stock, $new_stock, $admin_id);

            if ($ownTx && $this->pdo->inTransaction()) $this->pdo->commit();

            // Recharger données internes
            $this->loadData();
            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log("Product incrementStock Error: " . $e->getMessage());
            return false;
        }
    }
    
    public function updatePrice($new_price, $admin_id = null) {
        if (!$this->id) return false;
        
        try {
            $old_price = $this->getPrice();
            
            $stmt = $this->pdo->prepare("UPDATE items SET price = ?, updated_at = NOW() WHERE id = ?");
            $success = $stmt->execute([round(floatval($new_price), 2), $this->id]);
            
            if ($success) {
                $this->data['price'] = round(floatval($new_price), 2);
                
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
        
        // Autoriser is_active, deleted_at et category dans les mises à jour admin si fournis
        $allowed_fields = ['name', 'description', 'price', 'stock', 'stock_alert_threshold', 'category', 'is_active', 'deleted_at'];
        $update_fields = [];
        $values = [];
        
        foreach ($allowed_fields as $field) {
            if (array_key_exists($field, $data)) {
                $update_fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (empty($update_fields)) return false;
        
        try {
            $values[] = $this->id;
            $stmt = $this->pdo->prepare("UPDATE items SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE id = ?");
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
        return $this->isActive() && $this->getStock() > 0;
    }
    
    public function canPurchase($quantity = 1) {
        return $this->isActive() && $this->getStock() >= $quantity;
    }
    
    // === MÉTHODES DE CRÉATION ===
    
    public static function create($pdo, $data, $admin_id = null) {
        $required_fields = ['name', 'description', 'price', 'stock'];
        
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new InvalidArgumentException("Le champ '$field' est requis");
            }
        }
        
        try {
            // Préparer valeurs par défaut pour is_active / deleted_at
            $is_active = isset($data['is_active']) ? intval($data['is_active']) : 1;
            $deleted_at = null;
            $stock_alert_threshold = isset($data['stock_alert_threshold']) ? intval($data['stock_alert_threshold']) : 5;
            $category = $data['category'] ?? null;
            
            $stmt = $pdo->prepare("
                INSERT INTO items (name, description, price, stock, stock_alert_threshold, category, is_active, deleted_at, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NOW(), NOW())
            ");
            
            $success = $stmt->execute([
                $data['name'],
                $data['description'],
                round(floatval($data['price']), 2),
                intval($data['stock']),
                intval($stock_alert_threshold),
                ($category === '' ? null : $category),
                $is_active
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
            // Vérifier si le produit est référencé dans order_details
            $refStmt = $this->pdo->prepare("SELECT COUNT(*) FROM order_details WHERE item_id = ?");
            $refStmt->execute([$this->id]);
            $refCount = (int)$refStmt->fetchColumn();
            
            if ($refCount > 0) {
                // Soft-delete : désactiver le produit et conserver l'historique des commandes
                try {
                    $this->pdo->beginTransaction();
                    
                    $upd = $this->pdo->prepare("UPDATE items SET is_active = 0, deleted_at = NOW(), updated_at = NOW() WHERE id = ?");
                    $upd->execute([$this->id]);
                    
                    // Nettoyer paniers et favoris pour éviter nouvel achat
                    $this->pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$this->id]);
                    $this->pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$this->id]);
                    
                    // Notification admin
                    if ($admin_id) {
                        $notif = $this->pdo->prepare("
                            INSERT INTO notifications (type, message, is_persistent)
                            VALUES (?, ?, 0)
                        ");
                        $notif->execute([
                            'admin_action',
                            "Produit '{$this->getName()}' désactivé (soft-delete) par admin ID {$admin_id} - présent dans {$refCount} commande(s)"
                        ]);
                    }
                    
                    $this->pdo->commit();
                    // Recharger données pour refléter is_active si nécessaire
                    $this->loadData();
                    return true;
                } catch (PDOException $e) {
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    error_log("Product soft-delete Error: " . $e->getMessage());
                    return false;
                }
            }
            
            // Aucun lien dans order_details -> suppression physique complète
            try {
                $this->pdo->beginTransaction();
                
                // Supprimer les images physiques
                $images = $this->getImages();
                foreach ($images as $image) {
                    $image_path = __DIR__ . "/../../assets/images/" . $image['image'];
                    if (file_exists($image_path)) {
                        try {
                            @unlink($image_path);
                        } catch (Exception $e) {
                            // ignorer les erreurs unlink
                        }
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
                    if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                    return false;
                }
            } catch (PDOException $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                error_log("Product delete Error: " . $e->getMessage());
                return false;
            }
        } catch (PDOException $e) {
            error_log("Product delete Check Error: " . $e->getMessage());
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
        
        // Par défaut, n'inclure que les produits actifs sauf si filter explicitement demandé
        if (empty($filters['include_inactive'])) {
            $where_conditions[] = "IFNULL(is_active, 1) = 1";
        }
        
        $where_clause = !empty($where_conditions) ? 
            "WHERE " . implode(" AND ", $where_conditions) : "";
        
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
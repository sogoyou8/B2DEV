<?php
require_once __DIR__ . '/Product.php';

class Order {
    private $pdo;
    private $id;
    private $user_id;
    private $total_price;
    private $status;
    private $order_date;
    private $shipping_address;
    private $tracking_number;
    private $items = [];
    
    // Statuts valides
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';
    
    const VALID_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_SHIPPED,
        self::STATUS_DELIVERED,
        self::STATUS_CANCELLED
    ];
    
    public function __construct($pdo, $order_id = null) {
        $this->pdo = $pdo;
        if ($order_id) {
            $this->loadFromDatabase($order_id);
        }
    }
    
    // === MÉTHODES FACTORY ===
    
    public static function create($pdo, $user_id, $items, $shipping_address) {
        $order = new self($pdo);
        $order->user_id = $user_id;
        $order->shipping_address = $shipping_address;
        $order->status = self::STATUS_PENDING;
        $order->order_date = date('Y-m-d H:i:s');
        
        // Calculer le total
        $total = 0;
        foreach ($items as $item) {
            $total += $item['quantity'] * $item['price'];
        }
        $order->total_price = $total;
        $order->items = $items;
        
        return $order;
    }
    
    public static function findById($pdo, $order_id) {
        return new self($pdo, $order_id);
    }
    
    public static function findByUser($pdo, $user_id, $limit = 10) {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE user_id = ? 
            ORDER BY order_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$user_id, $limit]);
        $order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $orders = [];
        foreach ($order_ids as $id) {
            $orders[] = new self($pdo, $id);
        }
        
        return $orders;
    }
    
    public static function findByStatus($pdo, $status, $limit = 50) {
        if (!in_array($status, self::VALID_STATUSES)) {
            throw new InvalidArgumentException("Statut invalide: $status");
        }
        
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE status = ? 
            ORDER BY order_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$status, $limit]);
        $order_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $orders = [];
        foreach ($order_ids as $id) {
            $orders[] = new self($pdo, $id);
        }
        
        return $orders;
    }
    
    // === MÉTHODES CRUD ===
    
    public function save() {
        if ($this->id) {
            return $this->update();
        } else {
            return $this->insert();
        }
    }
    
    private function insert() {
        $this->pdo->beginTransaction();
        
        try {
            // Insérer la commande
            $stmt = $this->pdo->prepare("
                INSERT INTO orders (user_id, total_price, status, order_date, shipping_address, tracking_number)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $this->user_id,
                $this->total_price,
                $this->status,
                $this->order_date,
                $this->shipping_address,
                $this->tracking_number
            ]);
            
            $this->id = $this->pdo->lastInsertId();
            
            // Insérer les détails
            foreach ($this->items as $item) {
                $this->addOrderDetail($item);
            }
            
            $this->pdo->commit();
            
            // Notification
            $this->createNotification('admin_action', "Nouvelle commande #{$this->id} créée");
            
            return true;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Erreur création commande: " . $e->getMessage());
            return false;
        }
    }
    
    private function update() {
        $stmt = $this->pdo->prepare("
            UPDATE orders 
            SET status = ?, tracking_number = ?, shipping_address = ?
            WHERE id = ?
        ");
        
        $success = $stmt->execute([
            $this->status,
            $this->tracking_number,
            $this->shipping_address,
            $this->id
        ]);
        
        if ($success) {
            $this->createNotification('orders', "Commande #{$this->id} mise à jour - Statut: {$this->status}");
        }
        
        return $success;
    }
    
    private function loadFromDatabase($order_id) {
        $stmt = $this->pdo->prepare("
            SELECT * FROM orders WHERE id = ?
        ");
        $stmt->execute([$order_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            throw new Exception("Commande #$order_id non trouvée");
        }
        
        $this->id = $data['id'];
        $this->user_id = $data['user_id'];
        $this->total_price = $data['total_price'];
        $this->status = $data['status'];
        $this->order_date = $data['order_date'];
        $this->shipping_address = $data['shipping_address'];
        $this->tracking_number = $data['tracking_number'];
        
        $this->loadOrderDetails();
    }
    
    private function loadOrderDetails() {
        $stmt = $this->pdo->prepare("
            SELECT od.*, i.name as item_name
            FROM order_details od
            JOIN items i ON od.item_id = i.id
            WHERE od.order_id = ?
        ");
        $stmt->execute([$this->id]);
        $this->items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function addOrderDetail($item) {
        // Idempotence : si la ligne existe déjà pour cette commande et cet item, ne rien faire
        $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM order_details WHERE order_id = ? AND item_id = ?");
        $checkStmt->execute([$this->id, $item['item_id']]);
        $exists = (int)$checkStmt->fetchColumn();
        if ($exists > 0) {
            // ligne déjà insérée -> ne pas décrémenter à nouveau
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO order_details (order_id, item_id, quantity, price)
            VALUES (?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $this->id,
            $item['item_id'],
            $item['quantity'],
            $item['price']
        ]);
        
        // Utiliser le wrapper Product::decrementStock pour centraliser la décrémentation
        $product = new Product($this->pdo, $item['item_id']);
        $decremented = $product->decrementStock((int)$item['quantity']);
        if (!$decremented) {
            // Si la décrémentation échoue (stock insuffisant ou erreur), lever une exception
            // pour provoquer le rollback en amont. Le message permet de diagnostiquer.
            throw new Exception("Stock insuffisant ou erreur lors de la décrémentation pour l'item ID {$item['item_id']}");
        }

        // Les notifications de stock faible/rupture sont gérées par Product::decrementStock()
    }
    
    // === MÉTHODES MÉTIER ===
    
    public function updateStatus($new_status, $tracking_number = null) {
        if (!in_array($new_status, self::VALID_STATUSES)) {
            throw new InvalidArgumentException("Statut invalide: $new_status");
        }
        
        $old_status = $this->status;
        $this->status = $new_status;
        
        if ($tracking_number) {
            $this->tracking_number = $tracking_number;
        }
        
        $success = $this->update();
        
        if ($success) {
            $this->onStatusChanged($old_status, $new_status);
        }
        
        return $success;
    }
    
    private function onStatusChanged($old_status, $new_status) {
        $message = match($new_status) {
            self::STATUS_PROCESSING => "Commande #{$this->id} en cours de traitement",
            self::STATUS_SHIPPED => "Commande #{$this->id} expédiée" . ($this->tracking_number ? " (suivi: {$this->tracking_number})" : ""),
            self::STATUS_DELIVERED => "Commande #{$this->id} livrée avec succès",
            self::STATUS_CANCELLED => "Commande #{$this->id} annulée",
            default => "Commande #{$this->id} mise à jour"
        };
        
        $this->createNotification('orders', $message, $new_status === self::STATUS_CANCELLED ? 1 : 0);
        
        // Actions spécifiques par statut
        switch ($new_status) {
            case self::STATUS_DELIVERED:
                $this->onDelivered();
                break;
            case self::STATUS_CANCELLED:
                $this->onCancelled();
                break;
        }
    }
    
    private function onDelivered() {
        // Logique post-livraison
        // - Email de confirmation
        // - Demande d'avis client
        // - Points de fidélité
    }
    
    private function onCancelled() {
        // Remettre les articles en stock via la méthode centralisée Product::incrementStock
        // pour garantir verrouillage, logging et notifications cohérents.
        foreach ($this->items as $item) {
            try {
                $product = new Product($this->pdo, $item['item_id']);
                $ok = $product->incrementStock((int)$item['quantity']);
                if (!$ok) {
                    // Journaliser l'échec mais continuer pour les autres items
                    error_log("Order::onCancelled - impossible d'incrémenter le stock pour item ID {$item['item_id']} (order ID {$this->id})");
                }
            } catch (Exception $e) {
                // Ne pas interrompre la boucle en cas d'erreur, logger pour diagnostic
                error_log("Order::onCancelled - exception incrementStock item {$item['item_id']}: " . $e->getMessage());
            }
        }
    }
    
    public function canBeCancelled() {
        return !in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_CANCELLED]);
    }
    
    public function isDelivered() {
        return $this->status === self::STATUS_DELIVERED;
    }
    
    public function isPending() {
        return $this->status === self::STATUS_PENDING;
    }
    
    public function getStatusBadgeClass() {
        return match($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_SHIPPED => 'primary',
            self::STATUS_DELIVERED => 'success',
            self::STATUS_CANCELLED => 'danger',
            default => 'secondary'
        };
    }
    
    public function getCustomerInfo() {
        $stmt = $this->pdo->prepare("
            SELECT name, email, phone FROM users WHERE id = ?
        ");
        $stmt->execute([$this->user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function getTotalItems() {
        return array_sum(array_column($this->items, 'quantity'));
    }
    
    public function getDaysSinceOrder() {
        $order_date = new DateTime($this->order_date);
        $now = new DateTime();
        return $order_date->diff($now)->days;
    }
    
    private function checkLowStock($item_id) {
        $stmt = $this->pdo->prepare("
            SELECT name, stock, stock_alert_threshold 
            FROM items 
            WHERE id = ? AND stock <= stock_alert_threshold
        ");
        $stmt->execute([$item_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $this->createNotification(
                'stock',
                "Stock faible : {$product['name']} ({$product['stock']} restant(s))",
                1
            );
        }
    }
    
    private function createNotification($type, $message, $is_persistent = 0) {
        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (type, message, is_persistent) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$type, $message, $is_persistent]);
    }
    
    // === GETTERS ===
    
    public function getId() { return $this->id; }
    public function getUserId() { return $this->user_id; }
    public function getTotalPrice() { return $this->total_price; }
    public function getStatus() { return $this->status; }
    public function getOrderDate() { return $this->order_date; }
    public function getShippingAddress() { return $this->shipping_address; }
    public function getTrackingNumber() { return $this->tracking_number; }
    public function getItems() { return $this->items; }
    
    // === SETTERS ===
    
    public function setShippingAddress($address) {
        $this->shipping_address = $address;
    }
    
    public function setTrackingNumber($tracking) {
        $this->tracking_number = $tracking;
    }
    
    // === MÉTHODES STATIQUES UTILES ===
    
    public static function getStatusOptions() {
        return [
            self::STATUS_PENDING => 'En attente',
            self::STATUS_PROCESSING => 'En cours',
            self::STATUS_SHIPPED => 'Expédiée',
            self::STATUS_DELIVERED => 'Livrée',
            self::STATUS_CANCELLED => 'Annulée'
        ];
    }
    
    public static function getTodayStats($pdo) {
        $stats = $pdo->query("
            SELECT 
                COUNT(*) as count,
                COALESCE(SUM(total_price), 0) as revenue,
                AVG(total_price) as avg_order_value
            FROM orders 
            WHERE DATE(order_date) = CURDATE()
        ")->fetch(PDO::FETCH_ASSOC);
        
        return $stats;
    }
    
    public static function getRecentOrders($pdo, $limit = 10) {
        $stmt = $pdo->prepare("
            SELECT o.id, o.total_price, o.status, o.order_date, u.name as customer_name
            FROM orders o
            JOIN users u ON o.user_id = u.id
            ORDER BY o.order_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // === CONVERSION ===
    
    public function toArray() {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'total_price' => $this->total_price,
            'status' => $this->status,
            'order_date' => $this->order_date,
            'shipping_address' => $this->shipping_address,
            'tracking_number' => $this->tracking_number,
            'items' => $this->items,
            'items_count' => count($this->items),
            'total_items' => $this->getTotalItems(),
            'days_since_order' => $this->getDaysSinceOrder(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'status_badge_class' => $this->getStatusBadgeClass()
        ];
    }
    
    public function toJson() {
        return json_encode($this->toArray());
    }
}
?>
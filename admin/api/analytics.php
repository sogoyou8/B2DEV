<?php
class Analytics {
    private $pdo;
    private $cache = [];
    private $cache_duration = 300; // 5 minutes
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    // === MÉTRIQUES PRINCIPALES ===
    
    public function getTopProducts($days = 30, $limit = 10) {
        return $this->executeWithCache(__METHOD__ . "_{$days}_{$limit}", "
            SELECT 
                i.name,
                i.id,
                SUM(od.quantity) as total_sold,
                SUM(od.quantity * od.price) as revenue,
                COUNT(DISTINCT o.id) as orders_count,
                AVG(od.price) as avg_price
            FROM order_details od
            JOIN items i ON od.item_id = i.id
            JOIN orders o ON od.order_id = o.id
            WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND o.status IN ('delivered', 'shipped', 'processing')
            GROUP BY i.id, i.name
            ORDER BY total_sold DESC
            LIMIT ?
        ", [$days, $limit]);
    }
    
    public function getRevenueAnalysis($period = 'month') {
        $date_format = match($period) {
            'day' => '%Y-%m-%d',
            'week' => '%Y-%u',
            'month' => '%Y-%m',
            'year' => '%Y',
            default => '%Y-%m'
        };
        
        $interval = match($period) {
            'day' => '30 DAY',
            'week' => '12 WEEK',
            'month' => '12 MONTH',
            'year' => '5 YEAR',
            default => '12 MONTH'
        };
        
        return $this->executeWithCache(__METHOD__ . "_{$period}", "
            SELECT 
                DATE_FORMAT(o.order_date, ?) as period,
                COUNT(DISTINCT o.id) as orders_count,
                SUM(o.total_price) as revenue,
                AVG(o.total_price) as avg_order_value,
                COUNT(DISTINCT o.user_id) as unique_customers,
                SUM(od.quantity) as items_sold
            FROM orders o
            JOIN order_details od ON o.id = od.order_id
            WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL {$interval})
              AND o.status IN ('delivered', 'shipped', 'processing')
            GROUP BY period
            ORDER BY period ASC
        ", [$date_format]);
    }
    
    public function getCustomerAnalysis($days = 30) {
        return $this->executeWithCache(__METHOD__ . "_{$days}", "
            SELECT 
                COUNT(DISTINCT u.id) as total_customers,
                COUNT(DISTINCT CASE WHEN o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN u.id END) as active_customers,
                AVG(customer_stats.orders_count) as avg_orders_per_customer,
                AVG(customer_stats.total_spent) as avg_spent_per_customer,
                COUNT(CASE WHEN customer_stats.orders_count = 1 THEN 1 END) as one_time_customers,
                COUNT(CASE WHEN customer_stats.orders_count > 1 THEN 1 END) as repeat_customers
            FROM users u
            LEFT JOIN (
                SELECT 
                    user_id,
                    COUNT(*) as orders_count,
                    SUM(total_price) as total_spent
                FROM orders 
                WHERE status IN ('delivered', 'shipped', 'processing')
                GROUP BY user_id
            ) customer_stats ON u.id = customer_stats.user_id
            WHERE u.role = 'user'
        ", [$days]);
    }
    
    public function getInventoryAnalysis() {
        return $this->executeWithCache(__METHOD__, "
            SELECT 
                COUNT(*) as total_products,
                COUNT(CASE WHEN stock = 0 THEN 1 END) as out_of_stock,
                COUNT(CASE WHEN stock <= stock_alert_threshold AND stock > 0 THEN 1 END) as low_stock,
                COUNT(CASE WHEN stock > stock_alert_threshold THEN 1 END) as healthy_stock,
                AVG(stock) as avg_stock_level,
                SUM(stock * price) as total_inventory_value,
                (COUNT(CASE WHEN stock = 0 THEN 1 END) / COUNT(*)) * 100 as out_of_stock_rate
            FROM items
        ");
    }
    
    public function getConversionMetrics($days = 30) {
        // Simulation de données de trafic (à remplacer par vraies données)
        $visitors = match($days) {
            1 => 150,
            7 => 1200,
            30 => 5000,
            90 => 15000,
            default => 5000
        };
        
        $orders = $this->executeWithCache(__METHOD__ . "_orders_{$days}", "
            SELECT COUNT(*) 
            FROM orders 
            WHERE order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND status IN ('delivered', 'shipped', 'processing')
        ", [$days]);

        $cart_additions = $this->executeWithCache(__METHOD__ . "_cart_{$days}", "
            SELECT COUNT(DISTINCT user_id) 
            FROM cart
        ");
        
        $conversion_rate = $visitors > 0 ? round(($orders[0]['COUNT(*)'] / $visitors) * 100, 2) : 0;
        $cart_conversion = $cart_additions[0]['COUNT(DISTINCT user_id)'] > 0 ? 
            round(($orders[0]['COUNT(*)'] / $cart_additions[0]['COUNT(DISTINCT user_id)']) * 100, 2) : 0;
        
        return [
            'visitors' => $visitors,
            'orders' => $orders[0]['COUNT(*)'],
            'cart_additions' => $cart_additions[0]['COUNT(DISTINCT user_id)'],
            'conversion_rate' => $conversion_rate,
            'cart_conversion_rate' => $cart_conversion
        ];
    }
    
    // === ANALYSES AVANCÉES ===
    
    public function getProductPerformance($item_id, $days = 90) {
        return $this->executeWithCache(__METHOD__ . "_{$item_id}_{$days}", "
            SELECT 
                i.name,
                i.price as current_price,
                i.stock as current_stock,
                COUNT(DISTINCT o.id) as orders_count,
                SUM(od.quantity) as total_sold,
                SUM(od.quantity * od.price) as revenue,
                AVG(od.price) as avg_selling_price,
                (SUM(od.quantity * od.price) / COUNT(DISTINCT o.id)) as revenue_per_order,
                COUNT(DISTINCT o.user_id) as unique_buyers
            FROM items i
            LEFT JOIN order_details od ON i.id = od.item_id
            LEFT JOIN orders o ON od.order_id = o.id AND o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
            WHERE i.id = ?
            GROUP BY i.id
        ", [$days, $item_id]);
    }
    
    public function getSalesGrowth($period = 'month') {
        $current_period = $this->getRevenueForPeriod($period, 0);
        $previous_period = $this->getRevenueForPeriod($period, 1);
        
        $growth_rate = $previous_period > 0 ? 
            round((($current_period - $previous_period) / $previous_period) * 100, 2) : 
            ($current_period > 0 ? 100 : 0);
        
        return [
            'current_period' => $current_period,
            'previous_period' => $previous_period,
            'growth_rate' => $growth_rate,
            'growth_direction' => $growth_rate > 0 ? 'up' : ($growth_rate < 0 ? 'down' : 'stable')
        ];
    }
    
    public function getSeasonalAnalysis() {
        return $this->executeWithCache(__METHOD__, "
            SELECT 
                MONTH(o.order_date) as month,
                MONTHNAME(o.order_date) as month_name,
                COUNT(*) as orders_count,
                SUM(o.total_price) as revenue,
                AVG(o.total_price) as avg_order_value
            FROM orders o
            WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 2 YEAR)
              AND o.status IN ('delivered', 'shipped', 'processing')
            GROUP BY MONTH(o.order_date), MONTHNAME(o.order_date)
            ORDER BY month
        ");
    }
    
    public function getPerformanceMetrics($days = 30) {
        $metrics = $this->pdo->prepare("
            SELECT 
                COUNT(DISTINCT o.id) as total_orders,
                COUNT(DISTINCT o.user_id) as unique_customers,
                SUM(o.total_price) as total_revenue,
                AVG(o.total_price) as avg_order_value,
                SUM(od.quantity) as total_items_sold
            FROM orders o
            JOIN order_details od ON o.id = od.order_id
            WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND o.status IN ('delivered', 'shipped', 'processing')
        ");
        $metrics->execute([$days]);
        $result = $metrics->fetch(PDO::FETCH_ASSOC);
        
        // Ajouter des métriques calculées
        $result['revenue_per_customer'] = $result['unique_customers'] > 0 ? 
            round($result['total_revenue'] / $result['unique_customers'], 2) : 0;
        
        $result['orders_per_customer'] = $result['unique_customers'] > 0 ? 
            round($result['total_orders'] / $result['unique_customers'], 2) : 0;
        
        return $result;
    }
    
    // === PRÉDICTIONS ET TENDANCES ===
    
    public function getDemandForecast() {
        // Algorithme simple de prédiction basé sur les tendances
        return $this->executeWithCache(__METHOD__, "
            SELECT 
                i.id,
                i.name,
                i.stock,
                i.stock_alert_threshold,
                COALESCE(sales_data.avg_monthly_sales, 0) as avg_monthly_sales,
                COALESCE(sales_data.trend_factor, 1) as trend_factor,
                GREATEST(0, ROUND(COALESCE(sales_data.avg_monthly_sales, 0) * COALESCE(sales_data.trend_factor, 1))) as predicted_demand
            FROM items i
            LEFT JOIN (
                SELECT 
                    od.item_id,
                    AVG(monthly_sales.total) as avg_monthly_sales,
                    CASE 
                        WHEN COUNT(monthly_sales.month) >= 3 THEN
                            (SUM(monthly_sales.total * monthly_sales.month_rank) / SUM(monthly_sales.month_rank)) / 
                            (SUM(monthly_sales.total * (4 - monthly_sales.month_rank)) / SUM(4 - monthly_sales.month_rank))
                        ELSE 1
                    END as trend_factor
                FROM (
                    SELECT 
                        od.item_id,
                        DATE_FORMAT(o.order_date, '%Y-%m') as month,
                        SUM(od.quantity) as total,
                        ROW_NUMBER() OVER (PARTITION BY od.item_id ORDER BY DATE_FORMAT(o.order_date, '%Y-%m') DESC) as month_rank
                    FROM order_details od
                    JOIN orders o ON od.order_id = o.id
                    WHERE o.order_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                      AND o.status IN ('delivered', 'shipped', 'processing')
                    GROUP BY od.item_id, DATE_FORMAT(o.order_date, '%Y-%m')
                ) monthly_sales
                WHERE monthly_sales.month_rank <= 3
                GROUP BY od.item_id
            ) sales_data ON i.id = sales_data.item_id
            WHERE i.stock > 0
            ORDER BY predicted_demand DESC
        ");
    }
    
    public function getABCAnalysis() {
        return $this->executeWithCache(__METHOD__, "
            SELECT 
                items_with_revenue.*,
                CASE 
                    WHEN revenue_rank <= total_items * 0.2 THEN 'A'
                    WHEN revenue_rank <= total_items * 0.5 THEN 'B'
                    ELSE 'C'
                END as abc_category
            FROM (
                SELECT 
                    i.id,
                    i.name,
                    i.price,
                    i.stock,
                    COALESCE(SUM(od.quantity * od.price), 0) as revenue,
                    COALESCE(SUM(od.quantity), 0) as units_sold,
                    ROW_NUMBER() OVER (ORDER BY COALESCE(SUM(od.quantity * od.price), 0) DESC) as revenue_rank,
                    COUNT(*) OVER () as total_items
                FROM items i
                LEFT JOIN order_details od ON i.id = od.item_id
                LEFT JOIN orders o ON od.order_id = o.id 
                  AND o.order_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                  AND o.status IN ('delivered', 'shipped', 'processing')
                GROUP BY i.id, i.name, i.price, i.stock
            ) items_with_revenue
            ORDER BY revenue DESC
        ");
    }
    
    // === MÉTHODES UTILITAIRES ===
    
    private function executeWithCache($key, $query, $params = []) {
        // Vérifier le cache
        if (isset($this->cache[$key]) && 
            (time() - $this->cache[$key]['timestamp']) < $this->cache_duration) {
            return $this->cache[$key]['data'];
        }
        
        // Exécuter la requête
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mettre en cache
            $this->cache[$key] = [
                'data' => $result,
                'timestamp' => time()
            ];
            
            return $result;
        } catch (PDOException $e) {
            error_log("Analytics Error: " . $e->getMessage());
            return [];
        }
    }
    
    private function getRevenueForPeriod($period, $offset = 0) {
        $interval_map = [
            'day' => '1 DAY',
            'week' => '1 WEEK', 
            'month' => '1 MONTH',
            'year' => '1 YEAR'
        ];
        
        $interval = $interval_map[$period] ?? '1 MONTH';
        
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(SUM(total_price), 0) as revenue
            FROM orders 
            WHERE order_date >= DATE_SUB(DATE_SUB(NOW(), INTERVAL ? {$period}), INTERVAL {$interval})
              AND order_date < DATE_SUB(NOW(), INTERVAL ? {$period})
              AND status IN ('delivered', 'shipped', 'processing')
        ");
        $stmt->execute([$offset, $offset]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['revenue'] ?? 0;
    }
    
    public function clearCache() {
        $this->cache = [];
    }
    
    public function getCacheInfo() {
        return [
            'cache_entries' => count($this->cache),
            'cache_duration_seconds' => $this->cache_duration,
            'oldest_entry' => !empty($this->cache) ? min(array_column($this->cache, 'timestamp')) : null,
            'newest_entry' => !empty($this->cache) ? max(array_column($this->cache, 'timestamp')) : null
        ];
    }
    
    public function generateReport($period = 30) {
        return [
            'overview' => [
                'performance_metrics' => $this->getPerformanceMetrics($period),
                'revenue_analysis' => $this->getRevenueAnalysis('month'),
                'conversion_metrics' => $this->getConversionMetrics($period)
            ],
            'products' => [
                'top_products' => $this->getTopProducts($period, 10),
                'abc_analysis' => $this->getABCAnalysis(),
                'demand_forecast' => $this->getDemandForecast()
            ],
            'customers' => [
                'customer_analysis' => $this->getCustomerAnalysis($period)
            ],
            'inventory' => [
                'inventory_analysis' => $this->getInventoryAnalysis()
            ],
            'trends' => [
                'sales_growth' => $this->getSalesGrowth('month'),
                'seasonal_analysis' => $this->getSeasonalAnalysis()
            ],
            'generated_at' => date('Y-m-d H:i:s'),
            'period_days' => $period
        ];
    }
}
?>
<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include 'includes/header.php';
include '../includes/db.php';

// Messages de feedback
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show mt-3" role="alert">';
    echo '<i class="bi bi-check-circle-fill me-2"></i>' . $_SESSION['success_message'];
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">';
    echo '<i class="bi bi-exclamation-triangle-fill me-2"></i>' . $_SESSION['error_message'];
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['error_message']);
}

// === RÉCUPÉRATION DES DONNÉES ===

// Notifications importantes (critiques/persistantes)
$important_query = $pdo->query("SELECT id, message, created_at FROM notifications WHERE type IN ('important', 'security', 'critical') AND is_read = 0 ORDER BY created_at DESC");
$important_notifications = $important_query->fetchAll(PDO::FETCH_ASSOC);

// Stock faible (produits sous le seuil d'alerte)
$stock_query = $pdo->query("SELECT name, stock, stock_alert_threshold FROM items WHERE stock <= stock_alert_threshold AND stock > 0 ORDER BY stock ASC");
$low_stock_products = $stock_query->fetchAll(PDO::FETCH_ASSOC);

// Stock épuisé (rupture complète)
$out_of_stock_query = $pdo->query("SELECT name, stock FROM items WHERE stock = 0");
$out_of_stock_products = $out_of_stock_query->fetchAll(PDO::FETCH_ASSOC);

// Commandes en attente
$pending_orders_count = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending'")->fetchColumn();

// Commandes récentes (dernières 24h)
$recent_orders_query = $pdo->query("SELECT id, user_id, total_price, status, order_date FROM orders WHERE order_date >= NOW() - INTERVAL 24 HOUR ORDER BY order_date DESC LIMIT 5");
$recent_orders = $recent_orders_query->fetchAll(PDO::FETCH_ASSOC);

// Notifications système (logs admin)
$system_notifications_query = $pdo->query("SELECT id, message, created_at FROM notifications WHERE type = 'admin_action' AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
$system_notifications = $system_notifications_query->fetchAll(PDO::FETCH_ASSOC);

// Statistiques globales
$stats = [
    'total_notifications' => $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn(),
    'total_products' => $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn(),
    'total_orders_today' => $pdo->query("SELECT COUNT(*) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn(),
    'revenue_today' => $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE DATE(order_date) = CURDATE()")->fetchColumn()
];
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
<link rel="stylesheet" href="../assets/css/admin/notifications.css">

<main class="container py-4">
    <div class="row">
        <div class="col-12">
            <!-- Header avec statistiques -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-2">Centre de notifications</h2>
                    <p class="text-muted mb-0">
                        <i class="bi bi-bell me-1"></i>
                        <?php echo $stats['total_notifications']; ?> notification(s) non lue(s) • 
                        <i class="bi bi-box me-1"></i>
                        <?php echo $stats['total_products']; ?> produits • 
                        <i class="bi bi-currency-euro me-1"></i>
                        <?php echo number_format($stats['revenue_today'], 2); ?>€ aujourd'hui
                    </p>
                </div>
                <div>
                    <a href="mark_all_read.php" class="btn btn-sm btn-primary me-2">
                        <i class="bi bi-check-all"></i> Tout marquer comme lu
                    </a>
                    <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                        <i class="bi bi-arrow-clockwise"></i> Actualiser
                    </button>
                </div>
            </div>
            
            <!-- Filtres et actions -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-bold me-3">Filtres :</span>
                        <button class="btn btn-sm btn-secondary filter-btn active" data-filter="all">
                            <i class="bi bi-list"></i> Toutes (<?php echo $stats['total_notifications']; ?>)
                        </button>
                        <button class="btn btn-sm btn-outline-danger filter-btn" data-filter="important">
                            <i class="bi bi-exclamation-triangle"></i> Critiques (<?php echo count($important_notifications); ?>)
                        </button>
                        <button class="btn btn-sm btn-outline-warning filter-btn" data-filter="stock">
                            <i class="bi bi-box"></i> Stock (<?php echo count($low_stock_products) + count($out_of_stock_products); ?>)
                        </button>
                        <button class="btn btn-sm btn-outline-info filter-btn" data-filter="orders">
                            <i class="bi bi-bag"></i> Commandes (<?php echo $pending_orders_count; ?>)
                        </button>
                        <button class="btn btn-sm btn-outline-secondary filter-btn" data-filter="system">
                            <i class="bi bi-gear"></i> Système (<?php echo count($system_notifications); ?>)
                        </button>
                    </div>
                </div>
            </div>

            <!-- === NOTIFICATIONS CRITIQUES === -->
            <?php if ($important_notifications): ?>
            <div class="card mb-4 border-danger notification-card" data-type="important">
                <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-triangle-fill me-2"></i>Alertes critiques</span>
                    <span class="badge bg-light text-danger"><?php echo count($important_notifications); ?></span>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($important_notifications as $notif): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <p class="mb-1 fw-bold"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?>
                                </small>
                            </div>
                            <button class="btn btn-sm btn-outline-success mark-as-read ms-3" data-id="<?php echo $notif['id']; ?>">
                                <i class="bi bi-check"></i> Traiter
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- === STOCK ÉPUISÉ === -->
            <?php if ($out_of_stock_products): ?>
            <div class="card mb-4 border-dark notification-card" data-type="stock">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-x-circle-fill me-2"></i>Ruptures de stock</span>
                    <span class="badge bg-light text-dark"><?php echo count($out_of_stock_products); ?></span>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Action urgente requise :</strong> Ces produits sont totalement épuisés !
                    </div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($out_of_stock_products as $prod): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-box-seam text-danger me-2"></i>
                                <strong><?php echo htmlspecialchars($prod['name']); ?></strong>
                            </span>
                            <span class="badge bg-danger rounded-pill">Stock : 0</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer bg-transparent">
                        <a href="list_products.php" class="btn btn-sm btn-danger">
                            <i class="bi bi-gear"></i> Réapprovisionner maintenant
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- === STOCK FAIBLE === -->
            <?php if ($low_stock_products): ?>
            <div class="card mb-4 border-warning notification-card" data-type="stock">
                <div class="card-header bg-warning text-dark d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-exclamation-circle me-2"></i>Stock faible</span>
                    <span class="badge bg-light text-warning"><?php echo count($low_stock_products); ?></span>
                </div>
                <div class="card-body">
                    <p class="card-title fw-bold mb-3">
                        <i class="bi bi-info-circle me-2"></i>
                        Produits sous le seuil d'alerte :
                    </p>
                    <div class="list-group list-group-flush">
                        <?php foreach ($low_stock_products as $prod): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <i class="bi bi-box text-warning me-2"></i>
                                <?php echo htmlspecialchars($prod['name']); ?>
                            </span>
                            <div>
                                <span class="badge bg-warning rounded-pill me-2">
                                    Stock : <?php echo $prod['stock']; ?>
                                </span>
                                <small class="text-muted">Seuil : <?php echo $prod['stock_alert_threshold']; ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <a href="list_products.php" class="btn btn-sm btn-outline-warning">
                        <i class="bi bi-gear"></i> Gérer les stocks
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- === COMMANDES EN ATTENTE === -->
            <?php if ($pending_orders_count > 0): ?>
            <div class="card mb-4 border-info notification-card" data-type="orders">
                <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-box-seam me-2"></i>Commandes en attente</span>
                    <span class="badge bg-light text-info"><?php echo $pending_orders_count; ?></span>
                </div>
                <div class="card-body">
                    <p class="mb-3">
                        <i class="bi bi-clock me-2"></i>
                        Il y a <strong><?php echo $pending_orders_count; ?> commande(s)</strong> en attente de traitement.
                    </p>
                    
                    <?php if ($recent_orders): ?>
                    <h6 class="fw-bold mb-3">Commandes récentes (24h) :</h6>
                    <div class="list-group list-group-flush">
                        <?php foreach ($recent_orders as $order): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <span class="fw-bold">Commande #<?php echo $order['id']; ?></span>
                                <br>
                                <small class="text-muted">
                                    <?php echo date('d/m/Y H:i', strtotime($order['order_date'])); ?> • 
                                    <?php echo number_format($order['total_price'], 2); ?>€
                                </small>
                            </div>
                            <span class="badge bg-<?php echo $order['status'] == 'pending' ? 'warning' : 'success'; ?> rounded-pill">
                                <?php echo ucfirst($order['status']); ?>
                            </span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <a href="list_orders.php" class="btn btn-sm btn-info">
                        <i class="bi bi-eye"></i> Voir toutes les commandes
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- === NOTIFICATIONS SYSTÈME === -->
            <?php if ($system_notifications): ?>
            <div class="card mb-4 border-secondary notification-card" data-type="system">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-gear me-2"></i>Activité système</span>
                    <span class="badge bg-light text-secondary"><?php echo count($system_notifications); ?></span>
                </div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        <?php foreach ($system_notifications as $notif): ?>
                        <div class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="flex-grow-1">
                                <p class="mb-1"><?php echo htmlspecialchars($notif['message']); ?></p>
                                <small class="text-muted">
                                    <i class="bi bi-clock me-1"></i>
                                    <?php echo date('d/m/Y H:i', strtotime($notif['created_at'])); ?>
                                </small>
                            </div>
                            <button class="btn btn-sm btn-outline-secondary mark-as-read ms-3" data-id="<?php echo $notif['id']; ?>">
                                <i class="bi bi-check"></i> OK
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- === MESSAGE SI AUCUNE NOTIFICATION === -->
            <div class="alert alert-success d-none" id="no-notifications">
                <div class="text-center py-4">
                    <i class="bi bi-check-circle-fill display-4 text-success mb-3"></i>
                    <h5 class="mb-2">Parfait !</h5>
                    <p class="mb-0">Aucune notification de ce type pour le moment.</p>
                </div>
            </div>

            <!-- === MESSAGE SI TOUT EST OK === -->
            <?php if (!$important_notifications && !$low_stock_products && !$out_of_stock_products && $pending_orders_count == 0 && !$system_notifications): ?>
            <div class="alert alert-success text-center">
                <div class="py-4">
                    <i class="bi bi-check-circle-fill display-4 text-success mb-3"></i>
                    <h4 class="mb-3">Système en parfait état ! 🎉</h4>
                    <p class="mb-3">Aucune notification en attente. Votre e-commerce fonctionne parfaitement.</p>
                    <div class="row justify-content-center">
                        <div class="col-md-8">
                            <div class="row text-center">
                                <div class="col-3">
                                    <i class="bi bi-shield-check text-success fs-1"></i>
                                    <p class="small mb-0">Sécurité OK</p>
                                </div>
                                <div class="col-3">
                                    <i class="bi bi-box-check text-success fs-1"></i>
                                    <p class="small mb-0">Stock OK</p>
                                </div>
                                <div class="col-3">
                                    <i class="bi bi-check-circle text-success fs-1"></i>
                                    <p class="small mb-0">Commandes OK</p>
                                </div>
                                <div class="col-3">
                                    <i class="bi bi-graph-up text-success fs-1"></i>
                                    <p class="small mb-0">Performance OK</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- === ACTIONS RAPIDES === -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-lightning me-2"></i>Actions rapides</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <a href="list_products.php" class="btn btn-outline-primary btn-sm w-100">
                                <i class="bi bi-box me-1"></i> Gérer produits
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="list_orders.php" class="btn btn-outline-info btn-sm w-100">
                                <i class="bi bi-bag me-1"></i> Gérer commandes
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="prediction.php" class="btn btn-outline-success btn-sm w-100">
                                <i class="bi bi-graph-up me-1"></i> Prédictions IA
                            </a>
                        </div>
                        <div class="col-md-3 mb-2">
                            <a href="sales_history.php" class="btn btn-outline-warning btn-sm w-100">
                                <i class="bi bi-clock-history me-1"></i> Historique
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- === JAVASCRIPT POUR FILTRES ET INTERACTIONS === -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('🔔 Centre de notifications avancé chargé');
    
    // === ÉLÉMENTS DOM AVEC VÉRIFICATIONS ===
    const filterBtns = document.querySelectorAll('.filter-btn') || [];
    const notificationCards = document.querySelectorAll('.notification-card') || [];
    const noNotificationsAlert = document.getElementById('no-notifications');
    const markAsReadBtns = document.querySelectorAll('.mark-as-read') || [];
    
    // === SYSTÈME DE FILTRAGE AVANCÉ ===
    function filterNotifications(filterType) {
        let visibleCount = 0;
        
        console.log('🔍 Filtrage appliqué:', filterType);
        
        notificationCards.forEach((card, index) => {
            if (!card) return; // Sécurité
            
            const cardType = card.getAttribute('data-type');
            
            if (filterType === 'all' || cardType === filterType) {
                // Afficher avec animation décalée
                card.classList.remove('hidden');
                card.style.display = 'block';
                
                setTimeout(() => {
                    if (card) {
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }
                }, index * 100);
                
                visibleCount++;
            } else {
                // Cacher avec animation
                card.style.opacity = '0';
                card.style.transform = 'translateY(-20px)';
                
                setTimeout(() => {
                    if (card) {
                        card.style.display = 'none';
                        card.classList.add('hidden');
                    }
                }, 300);
            }
        });
        
        // Gestion du message "aucune notification"
        if (noNotificationsAlert) {
            if (visibleCount === 0 && filterType !== 'all') {
                setTimeout(() => {
                    noNotificationsAlert.classList.remove('d-none');
                    noNotificationsAlert.style.opacity = '0';
                    noNotificationsAlert.style.transform = 'scale(0.9)';
                    
                    setTimeout(() => {
                        noNotificationsAlert.style.transition = 'all 0.3s ease';
                        noNotificationsAlert.style.opacity = '1';
                        noNotificationsAlert.style.transform = 'scale(1)';
                    }, 50);
                }, 350);
            } else {
                noNotificationsAlert.classList.add('d-none');
            }
        }
        
        console.log('✅ Filtrage terminé - Notifications visibles:', visibleCount);
        
        // Effet sonore (optionnel)
        playFilterSound();
    }
    
    // === GESTION DES BOUTONS DE FILTRE ===
    filterBtns.forEach(btn => {
        if (!btn) return; // Sécurité
        
        btn.addEventListener('click', function() {
            const filterType = this.getAttribute('data-filter');
            console.log('🖱️ Clic sur filtre:', filterType);
            
            // Animation de clic
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                if (this) this.style.transform = '';
            }, 150);
            
            // Retirer active de tous les boutons
            filterBtns.forEach(b => {
                if (b) {
                    b.classList.remove('active');
                    b.classList.add('btn-outline-secondary', 'btn-outline-danger', 'btn-outline-warning', 'btn-outline-info');
                    b.classList.remove('btn-secondary', 'btn-danger', 'btn-warning', 'btn-info');
                }
            });
            
            // Activer le bouton cliqué
            this.classList.add('active');
            
            // Colorer selon le type
            switch(filterType) {
                case 'important':
                    this.classList.remove('btn-outline-danger');
                    this.classList.add('btn-danger');
                    break;
                case 'stock':
                    this.classList.remove('btn-outline-warning');
                    this.classList.add('btn-warning');
                    break;
                case 'orders':
                    this.classList.remove('btn-outline-info');
                    this.classList.add('btn-info');
                    break;
                case 'system':
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-secondary');
                    break;
                default:
                    this.classList.remove('btn-outline-secondary');
                    this.classList.add('btn-secondary');
            }
            
            // Appliquer le filtre
            filterNotifications(filterType);
        });
    });
    
    // === SYSTÈME "MARQUER COMME LUE" AVANCÉ ===
    markAsReadBtns.forEach(btn => {
        if (!btn) return; // Sécurité
        
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            
            const notificationId = this.getAttribute('data-id');
            const notificationEl = this.closest('.list-group-item');
            const originalHTML = this.innerHTML;
            
            console.log('✅ Traitement notification:', notificationId);
            
            try {
                // Animation de chargement
                this.innerHTML = '<i class="bi bi-hourglass-split spin"></i> Traitement...';
                this.disabled = true;
                this.classList.add('btn-loading');
                
                // Appel API
                const response = await fetch('api/notifications.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: notificationId})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Animation de succès
                    this.innerHTML = '<i class="bi bi-check-circle"></i> Traité !';
                    this.classList.remove('btn-loading');
                    this.classList.add('btn-success');
                    
                    // Animation de disparition de l'élément
                    if (notificationEl) {
                        notificationEl.classList.add('removing');
                        
                        setTimeout(() => {
                            if (notificationEl && notificationEl.parentNode) {
                                notificationEl.remove();
                                
                                // Vérifier si la carte est vide
                                const parentCard = document.querySelector('.notification-card');
                                if (parentCard) {
                                    const remainingItems = parentCard.querySelectorAll('.list-group-item:not(.removing)');
                                    
                                    if (remainingItems.length === 0) {
                                        parentCard.style.opacity = '0';
                                        parentCard.style.transform = 'scale(0.95)';
                                        
                                        setTimeout(() => {
                                            if (parentCard.parentNode) {
                                                parentCard.remove();
                                            }
                                            
                                            // Réappliquer le filtre actuel
                                            const activeFilterBtn = document.querySelector('.filter-btn.active');
                                            if (activeFilterBtn) {
                                                const activeFilter = activeFilterBtn.getAttribute('data-filter') || 'all';
                                                filterNotifications(activeFilter);
                                            }
                                        }, 300);
                                    }
                                }
                                
                                // Mettre à jour les badges de compteurs
                                updateFilterCounts();
                            }
                        }, 500);
                    }
                    
                    console.log('✅ Notification traitée avec succès');
                    
                    // Son de succès
                    playSuccessSound();
                    
                } else {
                    throw new Error(result.error || 'Erreur serveur');
                }
                
            } catch (error) {
                console.error('❌ Erreur:', error);
                
                // Restaurer le bouton
                this.innerHTML = originalHTML;
                this.disabled = false;
                this.classList.remove('btn-loading');
                
                // Animation d'erreur
                this.classList.add('btn-danger');
                setTimeout(() => {
                    if (this) this.classList.remove('btn-danger');
                }, 2000);
                
                // Notification d'erreur
                showErrorToast('Erreur lors du traitement de la notification');
            }
        });
    });
    
    // === ANIMATIONS D'APPARITION AU CHARGEMENT ===
    notificationCards.forEach((card, index) => {
        if (!card) return; // Sécurité
        
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        
        setTimeout(() => {
            if (card) {
                card.style.transition = 'all 0.4s cubic-bezier(0.4, 0, 0.2, 1)';
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
            }
        }, index * 150);
    });
    
    // === FONCTIONS UTILITAIRES ===
    
    // Mettre à jour les compteurs de filtres
    function updateFilterCounts() {
        try {
            // Recalculer les compteurs après suppression
            const importantCount = document.querySelectorAll('[data-type="important"]').length;
            const stockCount = document.querySelectorAll('[data-type="stock"]').length;
            const ordersCount = document.querySelectorAll('[data-type="orders"]').length;
            const systemCount = document.querySelectorAll('[data-type="system"]').length;
            
            // Mettre à jour les textes des boutons (si nécessaire)
            console.log('📊 Compteurs mis à jour:', {importantCount, stockCount, ordersCount, systemCount});
        } catch (error) {
            console.error('Erreur updateFilterCounts:', error);
        }
    }
    
    // Effets sonores (optionnels)
    function playFilterSound() {
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LFeSMFl');
        } catch(e) {
            // Silencieux si erreur audio
        }
    }
    
    function playSuccessSound() {
        try {
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLI');
        } catch(e) {
            // Silencieux si erreur audio
        }
    }
    
    // Toast d'erreur
    function showErrorToast(message) {
        try {
            const toast = document.createElement('div');
            toast.className = 'alert alert-danger position-fixed';
            toast.style.cssText = 'top: 20px; right: 20px; z-index: 9999; opacity: 0; transition: all 0.3s ease;';
            toast.innerHTML = `<i class="bi bi-exclamation-triangle me-2"></i>${message}`;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
            }, 100);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateY(-20px)';
                setTimeout(() => {
                    if (document.body.contains(toast)) {
                        document.body.removeChild(toast);
                    }
                }, 300);
            }, 3000);
        } catch (error) {
            console.error('Erreur showErrorToast:', error);
        }
    }
    
    // === RACCOURCIS CLAVIER ===
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey) {
            switch(e.key) {
                case 'r':
                    e.preventDefault();
                    location.reload();
                    break;
                case '1':
                    e.preventDefault();
                    const allBtn = document.querySelector('[data-filter="all"]');
                    if (allBtn) allBtn.click();
                    break;
                case '2':
                    e.preventDefault();
                    const importantBtn = document.querySelector('[data-filter="important"]');
                    if (importantBtn) importantBtn.click();
                    break;
                case '3':
                    e.preventDefault();
                    const stockBtn = document.querySelector('[data-filter="stock"]');
                    if (stockBtn) stockBtn.click();
                    break;
            }
        }
    });
    
    // === LOG FINAL ===
    console.log('🎯 Centre de notifications entièrement initialisé!');
    console.log('📝 Éléments détectés:');
    console.log('- Boutons filtres:', filterBtns.length);
    console.log('- Cartes notifications:', notificationCards.length);
    console.log('- Boutons "marquer comme lu":', markAsReadBtns.length);
    console.log('⌨️ Raccourcis: Ctrl+R (refresh), Ctrl+1/2/3 (filtres)');
});

// Animation CSS pour le spinner
const style = document.createElement('style');
style.textContent = `
    .spin {
        animation: spin 1s linear infinite;
    }
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .btn-loading {
        pointer-events: none;
        opacity: 0.7;
    }
`;
document.head.appendChild(style);
</script>

<?php include 'includes/footer.php'; ?>
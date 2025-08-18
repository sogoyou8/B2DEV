<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include '../includes/db.php';

try {
    $query = $pdo->query("SELECT * FROM orders ORDER BY order_date DESC");
    $orders = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $orders = [];
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}

include 'includes/header.php';
?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger text-center">
        <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success text-center">
        <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<main class="container py-4">
    <section class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Liste des commandes</h2>
            <div>
                <a href="add_order.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-bag-plus me-1"></i>Créer une commande
                </a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th style="width:80px;">ID</th>
                        <th style="width:120px;">ID Utilisateur</th>
                        <th>Prix Total</th>
                        <th>Status</th>
                        <th>Date de Commande</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($orders)): ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><?php echo (int)$order['id']; ?></td>
                                <td><?php echo (int)$order['user_id']; ?></td>
                                <td><?php echo number_format((float)$order['total_price'], 2, '.', ''); ?> €</td>
                                <td><?php echo htmlspecialchars($order['status']); ?></td>
                                <td><?php echo htmlspecialchars($order['order_date']); ?></td>
                                <td>
                                    <a href="edit_order.php?id=<?php echo (int)$order['id']; ?>" class="btn btn-warning btn-sm">
                                        <i class="bi bi-pencil-square"></i> Modifier
                                    </a>

                                    <button onclick="confirmDelete(<?php echo (int)$order['id']; ?>)" class="btn btn-danger btn-sm">
                                        <i class="bi bi-trash"></i> Supprimer
                                    </button>

                                    <!-- Optionnel : créer une commande rapide liée au même utilisateur -->
                                    <a href="add_order.php?user_id=<?php echo (int)$order['user_id']; ?>" class="btn btn-outline-primary btn-sm ms-1" title="Créer une commande pour ce client">
                                        <i class="bi bi-person-plus"></i> Créer pour ce client
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted">Aucune commande trouvée.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
    function confirmDelete(orderId) {
        if (confirm('Êtes-vous sûr de vouloir supprimer cette commande ? Cette action est irréversible.')) {
            window.location.href = 'delete_order.php?id=' + encodeURIComponent(orderId);
        }
    }
</script>

<?php include 'includes/footer.php'; ?>
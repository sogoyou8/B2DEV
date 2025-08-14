<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';

$query = $pdo->query("SELECT * FROM orders");
$orders = $query->fetchAll(PDO::FETCH_ASSOC);
include 'includes/header.php';
?>
<main>
    <section>
        <h2>Liste des commandes</h2>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>ID Utilisateur</th>
                    <th>Prix Total</th>
                    <th>Status</th>
                    <th>Date de Commande</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?php echo $order['id']; ?></td>
                        <td><?php echo $order['user_id']; ?></td>
                        <td><?php echo $order['total_price']; ?> €</td>
                        <td><?php echo $order['status']; ?></td>
                        <td><?php echo $order['order_date']; ?></td>
                        <td>
                            <a href="edit_order.php?id=<?php echo $order['id']; ?>" class="btn btn-warning btn-sm">Modifier</a>
                            <button onclick="confirmDelete(<?php echo $order['id']; ?>)" class="btn btn-danger btn-sm">Supprimer</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
<script>
    function confirmDelete(orderId) {
        if (confirm('Êtes-vous sûr de vouloir supprimer cette commande ? Cette action est irréversible.')) {
            window.location.href = 'delete_order.php?id=' + orderId;
        }
    }
</script>
<?php include 'includes/footer.php'; ?>
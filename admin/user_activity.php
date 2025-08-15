<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';
include 'includes/header.php';

$id = (int)($_GET['id'] ?? 0);

// Récupérer l'utilisateur
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$id]);
$user = $query->fetch(PDO::FETCH_ASSOC);

// Récupérer les commandes
$orders = $pdo->prepare("SELECT * FROM orders WHERE user_id = ? ORDER BY order_date DESC");
$orders->execute([$id]);
$order_list = $orders->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les favoris
$favorites = $pdo->prepare("SELECT i.name FROM favorites f JOIN items i ON f.item_id = i.id WHERE f.user_id = ?");
$favorites->execute([$id]);
$fav_list = $favorites->fetchAll(PDO::FETCH_COLUMN);

?>
<main class="container py-4">
    <h2 class="mb-4">Activité de <?php echo htmlspecialchars($user['name'] ?? 'Utilisateur'); ?></h2>
    <div class="mb-3">
        <strong>Email :</strong> <?php echo htmlspecialchars($user['email'] ?? ''); ?><br>
        <strong>Date d'inscription :</strong> <?php echo isset($user['created_at']) ? date('d/m/Y H:i', strtotime($user['created_at'])) : 'Date inconnue'; ?>
    </div>
    <h4>Commandes</h4>
    <?php if ($order_list): ?>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Total</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($order_list as $order): ?>
                <tr>
                    <td><?php echo $order['id']; ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($order['order_date'])); ?></td>
                    <td><?php echo number_format($order['total_price'], 2); ?> €</td>
                    <td><?php echo ucfirst($order['status']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Aucune commande trouvée.</p>
    <?php endif; ?>

    <h4>Favoris</h4>
    <?php if ($fav_list): ?>
        <ul>
            <?php foreach ($fav_list as $fav): ?>
                <li><?php echo htmlspecialchars($fav); ?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p>Aucun favori.</p>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
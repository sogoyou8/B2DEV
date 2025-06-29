<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/header.php';
include 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Récupérez les articles du panier
$query = $pdo->prepare("SELECT items.*, cart.quantity FROM items JOIN cart ON items.id = cart.item_id WHERE cart.user_id = ?");
$query->execute([$user_id]);
$items = $query->fetchAll(PDO::FETCH_ASSOC);

$total = 0;
foreach ($items as $item) {
    $total += $item['price'] * $item['quantity'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Enregistrez la commande dans la base de données
    $query = $pdo->prepare("INSERT INTO orders (user_id, total_price) VALUES (?, ?)");
    $query->execute([$user_id, $total]);
    $order_id = $pdo->lastInsertId();

    // Enregistrez les détails de la commande
    foreach ($items as $item) {
        $query = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");
        $query->execute([$order_id, $item['id'], $item['quantity'], $item['price']]);

        // Décrémentez le stock des articles achetés
        $query = $pdo->prepare("UPDATE items SET stock = stock - ? WHERE id = ?");
        $query->execute([$item['quantity'], $item['id']]);

        // Vérifier si le stock devient faible après la commande
        $query = $pdo->prepare("SELECT name, stock, stock_alert_threshold FROM items WHERE id = ?");
        $query->execute([$item['id']]);
        $prod = $query->fetch(PDO::FETCH_ASSOC);
        if ($prod && $prod['stock'] <= $prod['stock_alert_threshold']) {
            // Envoi d'un email d'alerte (remplace l'email par celui de l'admin)
            $to = 'admin@gmail.com';
            $subject = 'Alerte stock faible';
            $message = "Le produit '{$prod['name']}' est en stock faible ({$prod['stock']} restant, seuil {$prod['stock_alert_threshold']}).";
            mail($to, $subject, $message);

            // Notification persistante si stock à 0
            if ($prod['stock'] == 0) {
                $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
                $stmt->execute([
                    'important',
                    "Le produit '{$prod['name']}' est en rupture de stock !"
                ]);
            }
        }
    }

    // Générer la facture
    $billing_address = htmlspecialchars($_POST['billing_address']);
    $city = htmlspecialchars($_POST['city']);
    $postal_code = htmlspecialchars($_POST['postal_code']);
    $query = $pdo->prepare("INSERT INTO invoice (order_id, amount, billing_address, city, postal_code) VALUES (?, ?, ?, ?, ?)");
    $query->execute([$order_id, $total, $billing_address, $city, $postal_code]);

    // Vider le panier après la commande
    $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
    $query->execute([$user_id]);

    // Rediriger vers une page de confirmation
    header("Location: confirmation.php");
    exit;
}
?>
<main class="p-4">
    <section class="checkout-section bg-gray-100 p-6 rounded-lg shadow-md">
        <h2 class="text-3xl font-bold mb-4">Passer à la caisse</h2>
        <?php if ($items): ?>
            <ul class="mb-4">
                <?php foreach ($items as $item): ?>
                    <li class="mb-2">
                        <h3 class="text-xl font-semibold"><?php echo htmlspecialchars($item['name']); ?></h3>
                        <p>Quantité : <?php echo htmlspecialchars($item['quantity']); ?></p>
                        <p>Prix : <?php echo htmlspecialchars($item['price']); ?> €</p>
                        <p>Total : <?php echo htmlspecialchars($item['price'] * $item['quantity']); ?> €</p>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="cart-summary mb-4">
                <p class="text-xl font-semibold">Total du panier : <?php echo $total; ?> €</p>
            </div>
            <form action="checkout.php" method="post" class="space-y-4">
                <div>
                    <label for="billing_address" class="block text-sm font-medium text-gray-700">Adresse de facturation</label>
                    <input type="text" name="billing_address" id="billing_address" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="city" class="block text-sm font-medium text-gray-700">Ville</label>
                    <input type="text" name="city" id="city" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="postal_code" class="block text-sm font-medium text-gray-700">Code postal</label>
                    <input type="text" name="postal_code" id="postal_code" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
                </div>
                <button type="submit" class="btn btn-primary bg-blue-500 text-white py-2 px-4 rounded-md">Finaliser l'achat</button>
            </form>
        <?php else: ?>
            <p>Votre panier est vide.</p>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
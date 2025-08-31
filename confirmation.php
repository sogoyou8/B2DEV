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
require_once __DIR__ . '/includes/classes/Product.php';

// inclure helper adresse pour sauvegarde optionnelle
include_once __DIR__ . '/includes/address.php';

echo '<link rel="stylesheet" href="assets/css/user/confirmation.css">' ;

$user_id = $_SESSION['user_id'];

// Déterminer l'order_id à finaliser : prioriser la commande en attente en session
$pendingOrderId = isset($_SESSION['pending_order_id']) ? intval($_SESSION['pending_order_id']) : 0;

// Si pas de pendingOrderId en session, tenter de récupérer la dernière commande de l'utilisateur
if ($pendingOrderId <= 0) {
    try {
        $stmt = $pdo->prepare("SELECT id FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($last) $pendingOrderId = (int)$last['id'];
    } catch (Exception $e) {
        // impossible de récupérer -> afficher erreur plus bas
        $pendingOrderId = 0;
    }
}

$order = null;
if ($pendingOrderId > 0) {
    try {
        $q = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1");
        $q->execute([$pendingOrderId, $user_id]);
        $order = $q->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $order = null;
    }
}

if (!$order) {
    // Rien à afficher
    echo '<main class="container py-4"><section class="confirmation-section bg-light p-5 rounded shadow-sm"><h2 class="h3 mb-4 font-weight-bold">Confirmation de commande</h2><p class="text-danger">Commande introuvable.</p><a href="index.php" class="btn btn-primary">Retour à l\'accueil</a></section></main>';
    include 'includes/footer.php';
    exit;
}

/*
 * Si la commande existe mais n'a pas encore de order_details (créés au moment de la confirmation),
 * on va :
 *  - récupérer le panier de l'utilisateur (table cart)
 *  - vérifier les stocks
 *  - insérer les lignes order_details
 *  - décrémenter les stocks
 *  - créer la facture (invoice)
 *  - vider le panier (cart)
 * Le tout dans une transaction pour garder la cohérence.
 *
 * Si des order_details existent déjà pour cette commande, on n'effectue aucune opération (idempotence).
 */

// Vérifier s'il existe déjà des lignes order_details pour cette commande
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM order_details WHERE order_id = ?");
    $countStmt->execute([$pendingOrderId]);
    $detailsCount = (int)$countStmt->fetchColumn();
} catch (Exception $e) {
    $detailsCount = 0;
}

if ($detailsCount === 0) {
    // Récupérer le panier actuel (l'utilisateur doit toujours posséder son panier)
    try {
        // Verrouiller les lignes cart/items pour éviter courses
        $cartStmt = $pdo->prepare("SELECT c.item_id, c.quantity, IFNULL(i.price,0) AS price, IFNULL(i.name,'') AS name, IFNULL(i.stock,0) AS stock, IFNULL(i.stock_alert_threshold,5) AS stock_alert_threshold FROM cart c JOIN items i ON c.item_id = i.id WHERE c.user_id = ? FOR UPDATE");
        $pdo->beginTransaction();
        $cartStmt->execute([$user_id]);
        $cartRows = $cartStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cartRows)) {
            // aucun article dans le panier -> rollback et rediriger vers panier
            $pdo->rollBack();
            $_SESSION['error'] = "Votre panier est vide. Impossible de finaliser la commande.";
            header("Location: cart.php");
            exit;
        }

        // Vérifier la disponibilité du stock
        $insufficient = [];
        foreach ($cartRows as $row) {
            $available = intval($row['stock']);
            $requested = max(0, intval($row['quantity']));
            if ($requested <= 0) continue;
            if ($available < $requested) {
                $insufficient[] = [
                    'item_id' => (int)$row['item_id'],
                    'name' => $row['name'],
                    'available' => $available,
                    'requested' => $requested
                ];
            }
        }

        if (!empty($insufficient)) {
            $pdo->rollBack();
            $msgLines = [];
            foreach ($insufficient as $it) {
                $msgLines[] = "Stock insuffisant pour '{$it['name']}' (ID {$it['item_id']}): disponible {$it['available']}, demandé {$it['requested']}.";
            }
            $_SESSION['error'] = implode(" ", $msgLines);
            header("Location: cart.php");
            exit;
        }

        // Préparer statement pour insertion des détails (snapshot)
        $detailStmt = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity, price, product_name, unit_price) VALUES (?, ?, ?, ?, ?, ?)");

        // Insert lines and decrement stock via Product wrapper
        foreach ($cartRows as $row) {
            $itemId = (int)$row['item_id'];
            $qty = max(0, (int)$row['quantity']);
            $price = floatval($row['price']);
            $pname = $row['name'] ?? null;

            if ($qty <= 0) continue;

            // Insérer la ligne de commande (snapshot)
            $detailStmt->execute([$pendingOrderId, $itemId, $qty, $price, $pname, $price]);

            // Décrémenter le stock via Product::decrementStock pour centraliser verrous, notifications et logs
            try {
                $product = new Product($pdo, $itemId);
                $decremented = $product->decrementStock($qty);
                if (!$decremented) {
                    // Si la décrémentation échoue (stock insuffisant ou erreur), rollback et message d'erreur
                    $pdo->rollBack();
                    $_SESSION['error'] = "Stock insuffisant ou erreur lors de la mise à jour du stock pour '{$pname}'.";
                    header("Location: cart.php");
                    exit;
                }
            } catch (Exception $e) {
                // Erreur inattendue lors de la décrémentation via wrapper
                $pdo->rollBack();
                $_SESSION['error'] = "Erreur lors de la mise à jour du stock pour '{$pname}': " . $e->getMessage();
                header("Location: cart.php");
                exit;
            }

            // Les notifications de seuil / rupture sont gérées par Product::decrementStock()
        }

        // Générer la facture (avec champs provenant de la session pending_order_data si fournis)
        $pendingData = $_SESSION['pending_order_data'] ?? [
            'billing_address' => '',
            'city' => '',
            'postal_code' => '',
            'save_address' => 0
        ];
        $billing_address_db = $pendingData['billing_address'];
        $city_db = $pendingData['city'];
        $postal_code_db = $pendingData['postal_code'];

        $inv = $pdo->prepare("INSERT INTO invoice (order_id, amount, billing_address, city, postal_code) VALUES (?, ?, ?, ?, ?)");
        $inv->execute([$pendingOrderId, round($order['total_price'], 2), $billing_address_db, $city_db, $postal_code_db]);

        // Vider le panier après la commande
        $del = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $del->execute([$user_id]);

        // Enregistrer l'adresse et la préférence dans users si demandé (ou si colonne existe)
        if (!empty($pendingData['save_address']) && $user_id > 0) {
            // utiliser le helper centralisé (gère l'absence de colonnes)
            try {
                saveUserAddress($pdo, (int)$user_id, (string)$billing_address_db, (string)$city_db, (string)$postal_code_db, (int)$pendingData['save_address']);
            } catch (Exception $_) {
                // ignore : le helper retourne false sur échec, on ne veut pas faire échouer la finalisation
            }
        }

        $pdo->commit();

        // Mettre à jour la session : panier vidé, compteur recalculé
        $_SESSION['cart_count'] = 0;
        unset($_SESSION['pending_order_id'], $_SESSION['pending_order_data']);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['error'] = "Erreur lors de la finalisation de la commande : " . $e->getMessage();
        header("Location: cart.php");
        exit;
    }
}

// Maintenant récupérer les lignes de la commande pour affichage (elles existent)
try {
    $query = $pdo->prepare("SELECT items.*, od.quantity, od.price AS line_price FROM order_details od JOIN items ON od.item_id = items.id WHERE od.order_id = ?");
    $query->execute([$pendingOrderId]);
    $order_items = $query->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $order_items = [];
}
?>
<main class="container py-4">
    <section class="confirmation-section bg-light p-5 rounded shadow-sm">
        <h2 class="h3 mb-4 font-weight-bold">Confirmation de commande</h2>
        <p class="mb-4">Merci pour votre achat ! Votre commande a été passée avec succès.</p>
        <p class="mb-4">Vous recevrez un email de confirmation sous peu.</p>
        <h3 class="h4 mb-4 font-weight-bold">Détails de la commande</h3>
        <?php if ($order_items): ?>
        <ul class="list-group mb-4">
            <?php foreach ($order_items as $item): ?>
                <?php
                // Récupérer les images du produit
                $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                $query->execute([$item['id']]);
                $images = $query->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <li class="list-group-item d-flex align-items-center">
                    <div id="carouselOrderItem<?php echo $item['id']; ?>" class="carousel slide mr-3 order-carousel" data-ride="carousel" data-interval="false">
                        <div class="carousel-inner">
                            <?php foreach ($images as $index => $image): ?>
                                <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                    <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="d-block w-100 order-item-img">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <a class="carousel-control-prev" href="#carouselOrderItem<?php echo $item['id']; ?>" role="button" data-slide="prev">
                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                            <span class="sr-only">Previous</span>
                        </a>
                        <a class="carousel-control-next" href="#carouselOrderItem<?php echo $item['id']; ?>" role="button" data-slide="next">
                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                            <span class="sr-only">Next</span>
                        </a>
                    </div>
                    <div class="ml-3">
                        <h5 class="mb-1"><?php echo htmlspecialchars($item['name']); ?></h5>
                        <p class="mb-1"><strong>Quantité :</strong> <?php echo htmlspecialchars($item['quantity']); ?></p>
                        <p><strong>Prix :</strong> <?php echo htmlspecialchars($item['line_price']); ?> €</p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="text-xl font-semibold mb-4">Total payé : <?php echo htmlspecialchars($order['total_price']); ?> €</p>
        <a href="index.php" class="btn btn-primary">Retour à l'accueil</a>
        <?php else: ?>
            <p class="text-center">Impossible de récupérer les lignes de la commande.</p>
            <a href="index.php" class="btn btn-primary">Retour à l'accueil</a>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
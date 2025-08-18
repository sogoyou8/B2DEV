<?php
if (session_status() == PHP_SESSION_NONE) session_start();

// Auth admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once 'admin_demo_guard.php';
include_once '../includes/db.php';
include_once 'includes/header.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$old = [
    'user_id' => '',
    'product_id' => [],
    'quantity' => []
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Demo guard
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: add_order.php");
        exit;
    }

    // CSRF check
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Jeton CSRF invalide. Rechargez la page et réessayez.";
    }

    // Collect inputs
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $product_ids = isset($_POST['product_id']) && is_array($_POST['product_id']) ? $_POST['product_id'] : [];
    $quantities = isset($_POST['quantity']) && is_array($_POST['quantity']) ? $_POST['quantity'] : [];
    $status = in_array($_POST['status'] ?? 'pending', ['pending','processing','shipped','delivered','cancelled']) ? $_POST['status'] : 'pending';

    $old['user_id'] = $user_id;
    $old['product_id'] = $product_ids;
    $old['quantity'] = $quantities;

    // Basic validation
    if ($user_id <= 0) {
        $errors[] = "Utilisateur invalide.";
    }

    // Normalize lines: pair product_id/quantity, ignore empty lines
    $lines = [];
    for ($i = 0; $i < count($product_ids); $i++) {
        $pid = intval($product_ids[$i]);
        $qty = intval($quantities[$i] ?? 0);
        if ($pid > 0 && $qty > 0) {
            $lines[] = ['product_id' => $pid, 'quantity' => $qty];
        }
    }
    if (empty($lines)) {
        $errors[] = "Ajoutez au moins un produit avec une quantité supérieure à 0.";
    }

    // Validate products exist and stock availability
    if (empty($errors)) {
        try {
            $placeholders = implode(',', array_fill(0, count($lines), '?'));
            $ids = array_map(function($l){ return $l['product_id']; }, $lines);
            $stmt = $pdo->prepare("SELECT id, price, stock, stock_alert_threshold, name FROM items WHERE id IN ($placeholders) FOR UPDATE");
            $stmt->execute($ids);
            $fetched = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $byId = [];
            foreach ($fetched as $r) $byId[$r['id']] = $r;

            foreach ($lines as $l) {
                $pid = $l['product_id'];
                $qty = $l['quantity'];
                if (!isset($byId[$pid])) {
                    $errors[] = "Produit introuvable (ID $pid).";
                    continue;
                }
                // Check stock (do not allow negative stock)
                if ($byId[$pid]['stock'] < $qty) {
                    $errors[] = "Stock insuffisant pour '{$byId[$pid]['name']}' (ID $pid). Stock disponible : {$byId[$pid]['stock']}, demandé : $qty.";
                }
            }
        } catch (Exception $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        // Compute total and persist within transaction
        try {
            $pdo->beginTransaction();

            // compute total using fresh prices
            $total = 0.0;
            foreach ($lines as $l) {
                $pid = $l['product_id'];
                $qty = $l['quantity'];
                $price = floatval($byId[$pid]['price']);
                $total += $price * $qty;
            }

            // Insert order
            $ins = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, order_date) VALUES (?, ?, ?, NOW())");
            $ins->execute([$user_id, round($total,2), $status]);
            $order_id = $pdo->lastInsertId();

            // Insert order details and decrement stock
            $detailStmt = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");
            $updateStockStmt = $pdo->prepare("UPDATE items SET stock = stock - ? WHERE id = ?");
            $notifStmt = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 1)");

            foreach ($lines as $l) {
                $pid = $l['product_id'];
                $qty = $l['quantity'];
                $price = floatval($byId[$pid]['price']);

                $detailStmt->execute([$order_id, $pid, $qty, $price]);
                $updateStockStmt->execute([$qty, $pid]);

                // Check new stock level
                $stmtCheck = $pdo->prepare("SELECT stock, stock_alert_threshold, name FROM items WHERE id = ?");
                $stmtCheck->execute([$pid]);
                $row = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    if (intval($row['stock']) <= intval($row['stock_alert_threshold'])) {
                        $msg = "Le produit '{$row['name']}' (ID $pid) est en stock faible ({$row['stock']} restant, seuil {$row['stock_alert_threshold']}).";
                        $notifStmt->execute(['important', $msg, 1]);
                    }
                }
            }

            // Notification non-persistante d'action admin
            try {
                $log = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                $log->execute(['admin_action', "Nouvelle commande #{$order_id} créée par {$adminName}", 0]);
            } catch (Exception $e) {
                // ignore logging failure
            }

            $pdo->commit();
            $_SESSION['success'] = "Commande créée avec succès (ID $order_id).";
            header("Location: list_orders.php");
            exit;

        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "Erreur lors de la création de la commande : " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        $_SESSION['old_order'] = $old;
        // remain on page to show errors
    }
}

// Prepare data for form
$users = $pdo->query("SELECT id, name, email FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$products = $pdo->query("SELECT id, name, price, stock FROM items ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// If there is old data in session (after validation error), use it
if (!empty($_SESSION['old_order'])) {
    $old = $_SESSION['old_order'];
    unset($_SESSION['old_order']);
}
?>
<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-9">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-bag-plus me-2"></i>Créer une commande</h5>
                    <a href="list_orders.php" class="btn btn-sm btn-outline-secondary">Retour à la liste</a>
                </div>
                <div class="card-body">
                    <?php if (!empty($_SESSION['errors'])): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($_SESSION['errors'] as $e): ?>
                                    <li><?php echo htmlspecialchars($e); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php unset($_SESSION['errors']); ?>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
                    <?php endif; ?>

                    <form method="post" id="addOrderForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                        <div class="mb-3">
                            <label for="user_id" class="form-label">Client (utilisateur)</label>
                            <select id="user_id" name="user_id" class="form-select" required>
                                <option value="">-- Choisir un utilisateur --</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo (int)$u['id']; ?>" <?php echo ($old['user_id'] == $u['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($u['name'] . ' — ' . $u['email']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Lignes de commande</label>
                            <div id="lines">
                                <?php
                                $initialLines = max(1, count($old['product_id']) ?: 1);
                                for ($i = 0; $i < $initialLines; $i++): 
                                    $selPid = isset($old['product_id'][$i]) ? intval($old['product_id'][$i]) : '';
                                    $selQty = isset($old['quantity'][$i]) ? intval($old['quantity'][$i]) : '';
                                ?>
                                    <div class="row g-2 align-items-center mb-2 line-row">
                                        <div class="col-8 col-md-9">
                                            <select name="product_id[]" class="form-select">
                                                <option value="">-- Choisir un produit --</option>
                                                <?php foreach ($products as $p): ?>
                                                    <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo htmlspecialchars($p['price']); ?>" data-stock="<?php echo (int)$p['stock']; ?>"
                                                        <?php echo ($selPid == $p['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p['name'] . ' — ' . number_format($p['price'],2) . '€ (stock: '.$p['stock'].')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-3 col-md-2">
                                            <input type="number" name="quantity[]" class="form-control" min="1" placeholder="Qty" value="<?php echo $selQty ?: ''; ?>">
                                        </div>
                                        <div class="col-1 col-md-1">
                                            <button type="button" class="btn btn-outline-danger btn-sm remove-line" title="Supprimer">−</button>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>

                            <div class="mt-2">
                                <button type="button" id="addLineBtn" class="btn btn-sm btn-outline-primary">Ajouter une ligne</button>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="status" class="form-label">Statut</label>
                            <select id="status" name="status" class="form-select">
                                <option value="pending">En attente</option>
                                <option value="processing">En cours</option>
                                <option value="shipped">Expédiée</option>
                                <option value="delivered">Livrée</option>
                                <option value="cancelled">Annulée</option>
                            </select>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="list_orders.php" class="btn btn-outline-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Créer la commande</button>
                        </div>
                    </form>
                </div>
                <div class="card-footer small text-muted">
                    Les quantités doivent être disponibles en stock. En cas d'erreur, le formulaire affichera les messages.
                </div>
            </div>
        </div>
    </div>
</main>

<script>
(function(){
    function makeLine(selectedId, qty){
        var container = document.createElement('div');
        container.className = 'row g-2 align-items-center mb-2 line-row';
        container.innerHTML = `
            <div class="col-8 col-md-9">
                <select name="product_id[]" class="form-select">
                    <option value="">-- Choisir un produit --</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo htmlspecialchars($p['price']); ?>" data-stock="<?php echo (int)$p['stock']; ?>"
                            <?php echo ($p['id'] == 0) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($p['name'] . ' — ' . number_format($p['price'],2) . '€ (stock: '.$p['stock'].')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-3 col-md-2">
                <input type="number" name="quantity[]" class="form-control" min="1" placeholder="Qty" value="${qty || ''}">
            </div>
            <div class="col-1 col-md-1">
                <button type="button" class="btn btn-outline-danger btn-sm remove-line" title="Supprimer">−</button>
            </div>
        `;
        if (selectedId) {
            var sel = container.querySelector('select[name="product_id[]"]');
            sel.value = selectedId;
        }
        return container;
    }

    var addBtn = document.getElementById('addLineBtn');
    var lines = document.getElementById('lines');
    addBtn.addEventListener('click', function(){
        lines.appendChild(makeLine('', ''));
    });

    // Remove line (event delegation)
    lines.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('remove-line')) {
            var row = e.target.closest('.line-row');
            if (row) {
                // If only one line left, clear fields instead of removing
                if (lines.querySelectorAll('.line-row').length === 1) {
                    row.querySelector('select[name="product_id[]"]').value = '';
                    row.querySelector('input[name="quantity[]"]').value = '';
                } else {
                    row.remove();
                }
            }
        }
    });
})();
</script>
<?php include 'includes/footer.php'; ?>
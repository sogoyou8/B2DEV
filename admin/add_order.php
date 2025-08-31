<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();
// Auth admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once 'admin_demo_guard.php';
include_once '../includes/db.php';
include_once '../includes/classes/Product.php';
include_once 'includes/header.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$old = [
    'user_id' => '',
    'product_id' => [],
    'quantity' => [],
    'status' => 'pending'
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
    $old['status'] = $status;

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
        $startedTx = false;
        try {
            // START TRANSACTION before SELECT ... FOR UPDATE so the locking works
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }

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

            // If validation failed, rollback to release locks
            if (!empty($errors) && $startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
                $startedTx = false;
            }

        } catch (Exception $e) {
            if ($startedTx && $pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "Erreur base de données : " . $e->getMessage();
            $startedTx = false;
        }
    }

    if (empty($errors)) {
        // Compute total and persist within transaction
        try {
            // Ensure we are in a transaction. If validation opened it, we keep it.
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
            }

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

            // Insert order details and decrement stock via Product wrapper
            $detailStmt = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");

            foreach ($lines as $l) {
                $pid = $l['product_id'];
                $qty = $l['quantity'];
                $price = floatval($byId[$pid]['price']);

                // Insert order detail snapshot
                $detailStmt->execute([$order_id, $pid, $qty, $price]);

                // Decrement stock via Product::decrementStock to centralize locking, notifications and logs.
                $product = new Product($pdo, $pid);
                $adminId = $_SESSION['admin_id'] ?? null;
                $decremented = $product->decrementStock($qty, $adminId);
                if (!$decremented) {
                    // If decrement fails, rollback and throw to outer catch to set error
                    throw new Exception("Stock insuffisant ou erreur lors de la mise à jour du stock pour '{$byId[$pid]['name']}' (ID $pid).");
                }

                // Product::decrementStock handles notifications and logging
            }

            // Notification non-persistante d'action admin
            try {
                $log = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, ?)");
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
            if ($pdo->inTransaction()) $pdo->rollBack();
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

// Build options HTML for JS safely (avoid inline PHP inside JS)
$product_options_html = '';
foreach ($products as $p) {
    $pname = htmlspecialchars($p['name'] . ' — ' . number_format($p['price'],2) . '€ (stock: '.$p['stock'].')', ENT_QUOTES | ENT_SUBSTITUTE);
    $product_options_html .= '<option value="'.(int)$p['id'].'" data-price="'.htmlspecialchars($p['price'], ENT_QUOTES | ENT_SUBSTITUTE).'" data-stock="'.(int)$p['stock'].'">'.$pname.'</option>';
}

// If there is old data in session (after validation error), use it
if (!empty($_SESSION['old_order'])) {
    $old = $_SESSION['old_order'];
    unset($_SESSION['old_order']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Créer une commande</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Shared admin styling (par défaut utilisé sur list_* pages) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <!-- Use relative paths (../assets/...) so files resolve when project is served from a subfolder -->
    <link rel="stylesheet" href="../assets/css/admin/add_order.css">
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Créer une commande</h2>
                </div>
                <div class="small text-muted">Saisissez manuellement une commande pour un utilisateur — vérification du stock effectuée côté serveur.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_orders.php" class="btn btn-outline-secondary btn-round">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['errors'])): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($_SESSION['errors'] as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="row gx-4">
            <div class="col-12 col-lg-7">
                <form method="post" id="addOrderForm" class="card form-card shadow-sm p-3 mb-3" novalidate>
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

                    <h6 class="mb-2">Lignes de commande</h6>
                    <fieldset id="lines" aria-label="Lignes de commande">
                        <?php
                        $initialLines = max(1, count($old['product_id']) ?: 1);
                        for ($i = 0; $i < $initialLines; $i++):
                            $selPid = isset($old['product_id'][$i]) ? intval($old['product_id'][$i]) : '';
                            $selQty = isset($old['quantity'][$i]) ? intval($old['quantity'][$i]) : '';
                        ?>
                            <div class="line-row">
                                <label class="w-100">
                                    <div class="small text-muted mb-1">Produit</div>
                                    <select name="product_id[]">
                                        <option value="">-- Choisir un produit --</option>
                                        <?php foreach ($products as $p): ?>
                                            <option value="<?php echo (int)$p['id']; ?>" data-price="<?php echo htmlspecialchars($p['price']); ?>" data-stock="<?php echo (int)$p['stock']; ?>" <?php echo ($selPid == $p['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($p['name'] . ' — ' . number_format($p['price'],2) . '€ (stock: '.$p['stock'].')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <label style="width:110px;">
                                    <div class="small text-muted mb-1">Quantité</div>
                                    <input type="number" name="quantity[]" min="1" placeholder="Qty" class="form-control" value="<?php echo $selQty ? $selQty : ''; ?>">
                                </label>

                                <div style="align-self:flex-end;">
                                    <button type="button" class="btn btn-outline-danger btn-sm remove-line" title="Supprimer">−</button>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </fieldset>

                    <div class="mb-3">
                        <button type="button" id="addLineBtn" class="btn btn-sm btn-outline-primary">Ajouter une ligne</button>
                    </div>

                    <div class="mb-3">
                        <label for="status" class="form-label">Statut</label>
                        <select id="status" name="status" class="form-select" required>
                            <option value="pending" <?php if (($old['status'] ?? 'pending') === 'pending') echo 'selected'; ?>>En attente</option>
                            <option value="processing" <?php if (($old['status'] ?? '') === 'processing') echo 'selected'; ?>>En cours</option>
                            <option value="shipped" <?php if (($old['status'] ?? '') === 'shipped') echo 'selected'; ?>>Expédiée</option>
                            <option value="delivered" <?php if (($old['status'] ?? '') === 'delivered') echo 'selected'; ?>>Livrée</option>
                            <option value="cancelled" <?php if (($old['status'] ?? '') === 'cancelled') echo 'selected'; ?>>Annulée</option>
                        </select>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="list_orders.php" class="btn btn-outline-secondary">Annuler</a>
                        <button type="submit" class="btn btn-primary">Créer la commande</button>
                    </div>
                </form>

                <div class="card shadow-sm p-3">
                    <div class="card-body p-0">
                        <h6 class="mb-2">Conseils</h6>
                        <ul class="mb-0">
                            <li class="help-note">Les quantités doivent être disponibles en stock. En cas d'erreur, le formulaire affichera les messages.</li>
                            <li class="help-note">Vous pouvez ajouter plusieurs lignes, la vérification se fait côté serveur.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card shadow-sm p-3">
                    <div class="card-body">
                        <h6 class="mb-3">Aperçu rapide</h6>
                        <div class="mb-2"><strong>Client :</strong>
                            <div id="previewClient" class="text-muted"><?php echo $old['user_id'] ? htmlspecialchars(array_values(array_filter($users, function($u) use ($old){ return $u['id'] == $old['user_id']; }))[0]['name'] ?? '') : '—'; ?></div>
                        </div>

                        <div>
                            <strong>Lignes :</strong>
                            <ul id="previewLines" class="text-muted mb-2"></ul>
                        </div>

                        <small class="text-muted">L’aperçu est purement informatif — la validation finale est effectuée côté serveur.</small>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function(){
    // product options populated from PHP (safe JSON string)
    var productOptions = <?php echo json_encode($product_options_html, JSON_UNESCAPED_UNICODE); ?>;

    function makeLine(selectedId, qty){
        var container = document.createElement('div');
        container.className = 'line-row';

        var selectHtml = '<select name="product_id[]"><option value="">-- Choisir un produit --</option>' + (productOptions || '') + '</select>';
        var qtyHtml = '<input type="number" name="quantity[]" min="1" placeholder="Qty" class="form-control" value="' + (qty || '') + '">';
        var btnHtml = '<button type="button" class="btn btn-outline-danger btn-sm remove-line" title="Supprimer">−</button>';
        container.innerHTML = '<label class="w-100"><div class="small text-muted mb-1">Produit</div>' + selectHtml + '</label>' +
                              '<label style="width:110px;"><div class="small text-muted mb-1">Quantité</div>' + qtyHtml + '</label>' +
                              '<div style="align-self:flex-end;">' + btnHtml + '</div>';
        if (selectedId) {
            var sel = container.querySelector('select[name="product_id[]"]');
            if (sel) sel.value = selectedId;
        }
        return container;
    }

    var addBtn = document.getElementById('addLineBtn');
    var lines = document.getElementById('lines');
    var previewClient = document.getElementById('previewClient');
    var previewLines = document.getElementById('previewLines');

    function rebuildPreview() {
        var selUser = document.getElementById('user_id');
        previewClient.textContent = selUser && selUser.options[selUser.selectedIndex] ? selUser.options[selUser.selectedIndex].text : '—';

        var rows = Array.from(document.querySelectorAll('#lines .line-row'));
        previewLines.innerHTML = '';
        if (rows.length === 0) {
            previewLines.innerHTML = '<li>Aucune ligne pour l\'instant</li>';
            return;
        }
        rows.forEach(function(r){
            var s = r.querySelector('select[name="product_id[]"]');
            var q = r.querySelector('input[name="quantity[]"]');
            var txt = (s && s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '').trim();
            var qty = q ? q.value : '';
            if (!txt && !qty) return;
            var li = document.createElement('li');
            li.textContent = (txt ? txt : 'Produit non sélectionné') + ' × ' + (qty ? qty : '0');
            previewLines.appendChild(li);
        });
        if (previewLines.children.length === 0) previewLines.innerHTML = '<li>Aucune ligne pour l\'instant</li>';
    }

    addBtn && addBtn.addEventListener('click', function(){
        lines.appendChild(makeLine('', ''));
        rebuildPreview();
    });

    // bind change events (delegation)
    lines.addEventListener('change', function(e){
        rebuildPreview();
    });

    // Remove line (event delegation)
    lines.addEventListener('click', function(e){
        if (e.target && e.target.classList.contains('remove-line')) {
            var row = e.target.closest('.line-row');
            if (row) {
                // If only one line left, clear fields instead of removing
                if (lines.querySelectorAll('.line-row').length === 1) {
                    var sel = row.querySelector('select[name="product_id[]"]');
                    if (sel) sel.value = '';
                    var q = row.querySelector('input[name="quantity[]"]');
                    if (q) q.value = '';
                } else {
                    row.remove();
                }
                rebuildPreview();
            }
        }
    });

    // update preview when user selection changes
    var userSelect = document.getElementById('user_id');
    userSelect && userSelect.addEventListener('change', rebuildPreview);

    // initial preview
    rebuildPreview();
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
</html>
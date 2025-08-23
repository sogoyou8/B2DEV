<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();

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

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID de commande invalide.";
    header("Location: list_orders.php");
    exit;
}

// Charger la commande + utilisateur
try {
    $stmt = $pdo->prepare("SELECT o.*, u.name AS user_name, u.email AS user_email FROM orders o JOIN users u ON o.user_id = u.id WHERE o.id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $order = false;
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}

if (!$order) {
    header("Location: list_orders.php");
    exit;
}

// Charger les lignes de commande — joindre l'image principale (position = 0) si disponible
try {
    $dStmt = $pdo->prepare("
        SELECT od.*, i.name AS item_name,
               COALESCE(pi.image, '') AS item_image
        FROM order_details od
        LEFT JOIN items i ON od.item_id = i.id
        LEFT JOIN product_images pi ON pi.product_id = i.id AND pi.position = 0
        WHERE od.order_id = ?
        ORDER BY od.id
    ");
    $dStmt->execute([$id]);
    $order_lines = $dStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $order_lines = [];
}

/**
 * Résout un chemin d'image web utilisable par les pages admin.
 */
function resolveImageSrc($imageName) {
    $assetsFsDir = realpath(__DIR__ . '/../assets/images');
    $candidates = [];

    if ($assetsFsDir !== false) {
        if (!empty($imageName)) {
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . basename($imageName);
        }
        $defaultFs = $assetsFsDir . DIRECTORY_SEPARATOR . 'default.png';
    } else {
        $candidates[] = __DIR__ . "/../assets/images/" . $imageName;
        $candidates[] = __DIR__ . "/../assets/images/products/" . $imageName;
        $defaultFs = __DIR__ . "/../assets/images/default.png";
    }

    foreach ($candidates as $p) {
        if (!$p) continue;
        if (@file_exists($p)) {
            return '../assets/images/' . rawurlencode(basename($p));
        }
    }

    if (@file_exists($defaultFs)) {
        return '../assets/images/default.png';
    }

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="16">No image</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

// Handle POST (mise à jour statut)
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Demo guard
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: edit_order.php?id=" . $id);
        exit;
    }

    // CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Jeton CSRF invalide. Rechargez la page et réessayez.";
    }

    $new_status = in_array($_POST['status'] ?? '', ['pending','processing','shipped','delivered','cancelled']) ? $_POST['status'] : null;
    if (!$new_status) $errors[] = "Statut invalide.";

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $ok = $stmt->execute([$new_status, $id]);

            if ($ok) {
                // Log notification non-persistante pour changement important
                try {
                    $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                    $notif = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                    $notif->execute(['admin_action', "Commande #{$id} de {$order['user_name']} ({$order['user_email']}) mise à '{$new_status}' par {$adminName}"]);
                } catch (Exception $e) {
                    // ignore logging failure
                }

                $_SESSION['success'] = "Statut de la commande mis à jour.";
                header("Location: list_orders.php");
                exit;
            } else {
                $errors[] = "Impossible de mettre à jour la commande.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        header("Location: edit_order.php?id=" . $id);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier une commande - #<?php echo (int)$order['id']; ?></title>
    <style>
        :root{
            --card-radius:12px;
            --muted:#6c757d;
            --bg-gradient-1:#f8fbff;
            --bg-gradient-2:#eef7ff;
            --accent:#0d6efd;
            --accent-2:#6610f2;
        }
        body.admin-page {
            background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2));
        }
        .panel-card {
            border-radius: var(--card-radius);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
            box-shadow: 0 12px 36px rgba(3,37,76,0.06);
            padding: 1.25rem;
        }
        .page-title { display:flex; gap:1rem; align-items:center; }
        .page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
        .small-muted { color:#6c757d; font-size:0.9rem; }
        .thumb { width:64px; height:64px; object-fit:cover; border-radius:8px; }
        .order-table td .item-info { display:flex; gap:12px; align-items:center; }
        .badge-status { border-radius:8px; padding:.35em .6em; font-size:.9rem; }
        .controls { display:flex; gap:.5rem; align-items:center; }
        .btn-round { border-radius:8px; }
        .preview-card { border-radius:10px; background:#fff; padding:1rem; box-shadow:0 6px 18px rgba(3,37,76,0.03); }
    </style>
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Modifier une commande — #<?php echo (int)$order['id']; ?></h2>
                <div class="small text-muted">Utilisateur : <?php echo htmlspecialchars($order['user_name'] . ' — ' . $order['user_email']); ?></div>
            </div>
            <div class="controls">
                <a href="list_orders.php" class="btn btn-outline-secondary btn-sm btn-round">Retour à la liste</a>
                <a href="user_activity.php?user_id=<?php echo (int)$order['user_id']; ?>" class="btn btn-outline-info btn-sm btn-round">Voir l'utilisateur</a>
            </div>
        </div>

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
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="mb-3">Détails de la commande</h5>

                        <div class="row">
                            <div class="col-6">
                                <div><strong>Référence :</strong> #<?php echo (int)$order['id']; ?></div>
                                <div class="small-muted"><strong>Date :</strong> <?php echo htmlspecialchars($order['order_date']); ?></div>
                            </div>
                            <div class="col-6 text-end">
                                <div><strong>Montant total :</strong> <?php echo number_format((float)$order['total_price'],2,'.',''); ?> €</div>
                                <div class="small-muted"><strong>Client :</strong> <?php echo htmlspecialchars($order['user_name']); ?></div>
                            </div>
                        </div>

                        <hr>

                        <h6>Lignes</h6>
                        <?php if (!empty($order_lines)): ?>
                            <table class="table order-table mt-2">
                                <thead>
                                    <tr>
                                        <th>Produit</th>
                                        <th>Prix</th>
                                        <th>Quantité</th>
                                        <th>Sous-total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order_lines as $ln): ?>
                                        <?php
                                            $itemImage = $ln['item_image'] ?? '';
                                            $imgSrc = resolveImageSrc($itemImage);
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="item-info">
                                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="thumb">
                                                    <div>
                                                        <div><?php echo htmlspecialchars($ln['item_name'] ?? ("ID ".$ln['item_id'])); ?></div>
                                                        <div class="small-muted"><?php echo number_format((float)$ln['price'],2,'.',''); ?> € / unité</div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><?php echo number_format((float)$ln['price'],2,'.',''); ?> €</td>
                                            <td><?php echo (int)$ln['quantity']; ?></td>
                                            <td><?php echo number_format((float)$ln['price'] * (int)$ln['quantity'],2,'.',''); ?> €</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p class="text-muted">Aucune ligne enregistrée pour cette commande.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="text-muted small">
                    Les modifications sont journalisées dans le centre de notifications.
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="preview-card mb-3">
                    <h6>Modifier le statut</h6>
                    <form action="edit_order.php?id=<?php echo $id; ?>" method="post" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <label for="status" class="form-label small-muted">Statut</label>
                            <select id="status" name="status" class="form-select form-select-sm" required>
                                <option value="pending" <?php if ($order['status'] === 'pending') echo 'selected'; ?>>En attente</option>
                                <option value="processing" <?php if ($order['status'] === 'processing') echo 'selected'; ?>>En cours</option>
                                <option value="shipped" <?php if ($order['status'] === 'shipped') echo 'selected'; ?>>Expédiée</option>
                                <option value="delivered" <?php if ($order['status'] === 'delivered') echo 'selected'; ?>>Livrée</option>
                                <option value="cancelled" <?php if ($order['status'] === 'cancelled') echo 'selected'; ?>>Annulée</option>
                            </select>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
                            <a href="list_orders.php" class="btn btn-outline-secondary btn-sm">Annuler</a>
                        </div>
                    </form>
                </div>

                <div class="card shadow-sm p-3">
                    <h6 class="mb-2">Informations client</h6>
                    <div><strong>Nom :</strong> <?php echo htmlspecialchars($order['user_name']); ?></div>
                    <div><strong>Email :</strong> <?php echo htmlspecialchars($order['user_email']); ?></div>
                    <div class="mt-2 small-muted"><strong>ID client :</strong> <?php echo (int)$order['user_id']; ?></div>
                    <div class="mt-3">
                        <a href="user_activity.php?user_id=<?php echo (int)$order['user_id']; ?>" class="btn btn-sm btn-outline-primary">Voir l'activité</a>
                        <a href="../orders_invoices.php?order_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">Facture</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function(){
    // nothing fancy required here — keep form behaviour straightforward
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
</html>
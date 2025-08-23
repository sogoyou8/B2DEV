<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();
// Simple admin guard
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'includes/header.php';

// Accept either "id" or "user_id" in querystring to be compatible with other pages
$id = null;
$rawUserId = null;
if (isset($_GET['user_id'])) {
    $rawUserId = $_GET['user_id'];
} elseif (isset($_GET['id'])) {
    $rawUserId = $_GET['id'];
}
if ($rawUserId !== null) {
    // accept numeric strings only, cast to int
    $id = filter_var($rawUserId, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
}

if (!$id || $id <= 0) {
    $_SESSION['error'] = "Utilisateur introuvable (paramètre manquant ou invalide).";
    header("Location: list_users.php");
    exit;
}

// helper for non-blocking warnings
$warnings = [];

// === Data retrieval (preserve logic) ===
try {
    $stmt = $pdo->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $_SESSION['error'] = "Utilisateur introuvable.";
        header("Location: list_users.php");
        exit;
    }
} catch (Exception $e) {
    // fatal for this page
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_users.php");
    exit;
}

// Récupérer les commandes (liste courte, plus récentes en haut)
$order_list = [];
try {
    $ordersStmt = $pdo->prepare("SELECT id, total_price, status, order_date, user_id FROM orders WHERE user_id = ? ORDER BY order_date DESC");
    $ordersStmt->execute([$id]);
    $order_list = $ordersStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $order_list = [];
    $warnings[] = "Impossible de récupérer les commandes : " . $e->getMessage();
}

// Préparer la requête de détails (optimisé : préparée une fois)
// Inclut item_id et image principale si disponible
try {
    $detailsStmt = $pdo->prepare("
        SELECT od.quantity, od.price, od.item_id, COALESCE(i.name, '') AS item_name, COALESCE(pi.image, '') AS item_image
        FROM order_details od
        LEFT JOIN items i ON od.item_id = i.id
        LEFT JOIN product_images pi ON pi.product_id = i.id AND pi.position = 0
        WHERE od.order_id = ?
        ORDER BY od.id
    ");
} catch (Exception $e) {
    // si la préparation échoue, on garde la variable null et on affichera un avertissement
    $detailsStmt = null;
    $warnings[] = "Impossible de préparer la requête des détails de commande : " . $e->getMessage();
}

// Récupérer les favoris (noms des produits + image principale si disponible)
$fav_list = [];
try {
    $favQ = $pdo->prepare("
        SELECT i.id AS item_id, i.name AS item_name, COALESCE(pi.image, '') AS image
        FROM favorites f
        JOIN items i ON f.item_id = i.id
        LEFT JOIN product_images pi ON pi.product_id = i.id AND pi.position = 0
        WHERE f.user_id = ?
        ORDER BY f.id DESC
    ");
    $favQ->execute([$id]);
    $fav_list = $favQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $fav_list = [];
    if (empty($_SESSION['error'])) {
        $warnings[] = "Impossible de récupérer les favoris : " . $e->getMessage();
    }
}

// Helper to render status badge class (kept consistent)
function statusBadgeClass($status) {
    return match($status) {
        'pending' => 'bg-warning text-dark',
        'processing' => 'bg-secondary text-white',
        'shipped' => 'bg-info text-white',
        'delivered' => 'bg-success text-white',
        'cancelled' => 'bg-danger text-white',
        default => 'bg-secondary text-white'
    };
}

/**
 * Resolve image path for admin pages (returns relative path or data URI)
 */
function resolveImageSrcAdmin(string $imageName = ''): string {
    $assetsFsDir = realpath(__DIR__ . '/../assets/images');
    $candidates = [];
    if ($assetsFsDir !== false) {
        if ($imageName !== '') {
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

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Activité utilisateur - <?php echo htmlspecialchars($user['name'] ?? 'Utilisateur'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{
            --card-radius:12px;
            --muted:#6c757d;
            --bg-gradient-1:#f8fbff;
            --bg-gradient-2:#eef7ff;
            --accent:#0d6efd;
            --accent-2:#6610f2;
        }
        body.admin-page { background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2)); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }
        .panel-card { border-radius: var(--card-radius); background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff); box-shadow: 0 12px 36px rgba(3,37,76,0.06); padding: 1.25rem; }
        .page-title { display:flex; gap:1rem; align-items:center; }
        .page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
        .controls { display:flex; gap:.5rem; align-items:center; }
        .btn-round { border-radius:8px; }
        .small-muted { color:var(--muted); font-size:.95rem; }
        .thumb { width:56px; height:56px; object-fit:cover; border-radius:8px; box-shadow:0 8px 20px rgba(3,37,76,0.04); }
        .preview-thumb { width:42px; height:42px; object-fit:cover; border-radius:6px; }
        .badge-status { border-radius:8px; padding:.35em .6em; font-size:.9rem; }
        table { width:100%; border-collapse:collapse; }
        table td, table th { padding:8px; vertical-align:middle; }
        .order-items img { width:48px; height:48px; object-fit:cover; border-radius:6px; margin-right:8px; }
        @media (max-width: 992px) { .col-lg-7, .col-lg-5 { width:100%; display:block; } }
    </style>
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Activité de <?php echo htmlspecialchars($user['name'] ?? 'Utilisateur'); ?></h2>
                <div class="small text-muted ms-2">Détails des commandes, historique et favoris — vue enrichie.</div>
            </div>
            <div class="controls">
                <a href="list_users.php" class="btn btn-outline-secondary btn-sm btn-round">← Retour à la liste</a>
                <a href="edit_user.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-primary btn-sm btn-round">Modifier l'utilisateur</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (!empty($warnings)): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($warnings as $w): ?>
                        <li><?php echo htmlspecialchars($w); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-4">
                <div class="card p-3 shadow-sm mb-3">
                    <h6>Informations utilisateur</h6>
                    <div><strong>Nom :</strong> <?php echo htmlspecialchars($user['name']); ?></div>
                    <div><strong>Email :</strong> <?php echo htmlspecialchars($user['email']); ?></div>
                    <div class="mt-2 small-muted"><strong>Date d'inscription :</strong>
                        <?php
                            echo !empty($user['created_at'])
                                ? htmlspecialchars((new DateTime($user['created_at']))->format('d/m/Y H:i'))
                                : 'Date inconnue';
                        ?>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <a href="list_orders.php?user_id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-primary">Voir toutes les commandes</a>
                        <a href="edit_user.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-secondary">Modifier</a>
                    </div>
                </div>

                <div class="card p-3 shadow-sm">
                    <h6>Favoris</h6>
                    <?php if ($fav_list): ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($fav_list as $fav): ?>
                                <?php
                                    $imgSrc = resolveImageSrcAdmin($fav['image'] ?? '');
                                    $itemName = htmlspecialchars($fav['item_name'] ?? ('Article #' . (int)$fav['item_id']));
                                ?>
                                <li class="d-flex align-items-center mb-2">
                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" class="preview-thumb me-2">
                                    <div>
                                        <div><?php echo $itemName; ?> <small class="text-muted">ID <?php echo (int)$fav['item_id']; ?></small></div>
                                        <div class="mt-1">
                                            <a href="../product_detail.php?id=<?php echo (int)$fav['item_id']; ?>" class="btn btn-sm btn-outline-primary">Voir</a>
                                            <a href="sales_history.php?product_id=<?php echo (int)$fav['item_id']; ?>" class="btn btn-sm btn-outline-info">Historique ventes</a>
                                        </div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="text-muted">Aucun favori.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-12 col-lg-8">
                <div class="card p-3 shadow-sm mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="mb-0">Commandes (<?php echo count($order_list); ?>)</h6>
                        <div class="small text-muted">Les modifications de commandes sont journalisées dans le centre de notifications.</div>
                    </div>

                    <?php if ($order_list): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:80px;">ID</th>
                                        <th>Date</th>
                                        <th class="text-end">Total</th>
                                        <th>Statut</th>
                                        <th>Détails</th>
                                        <th style="width:180px;" class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($order_list as $order): ?>
                                        <?php
                                            $orderId = (int)$order['id'];
                                            $orderDate = isset($order['order_date']) ? (new DateTime($order['order_date']))->format('d/m/Y H:i') : '-';
                                            $orderTotal = number_format((float)$order['total_price'], 2, ',', ' ') . ' €';
                                            $orderStatus = $order['status'] ?? 'pending';
                                            $items = [];
                                            if ($detailsStmt) {
                                                try {
                                                    $detailsStmt->execute([$orderId]);
                                                    $items = $detailsStmt->fetchAll(PDO::FETCH_ASSOC);
                                                } catch (Exception $e) {
                                                    $items = [];
                                                    // non-fatal: push warning but continue rendering
                                                    $warnings[] = "Impossible de récupérer les détails de la commande #$orderId : " . $e->getMessage();
                                                }
                                            }
                                        ?>
                                        <tr>
                                            <td class="fw-bold text-secondary"><?php echo $orderId; ?></td>
                                            <td><?php echo htmlspecialchars($orderDate); ?></td>
                                            <td class="text-end"><?php echo $orderTotal; ?></td>
                                            <td><span class="badge <?php echo statusBadgeClass($orderStatus); ?> badge-status"><?php echo ucfirst(htmlspecialchars($orderStatus)); ?></span></td>
                                            <td>
                                                <?php if (!empty($items)): ?>
                                                    <div class="order-items">
                                                        <ul class="list-unstyled mb-0">
                                                            <?php foreach ($items as $it): ?>
                                                                <?php
                                                                    $iname = htmlspecialchars($it['item_name'] ?? ('Article #' . ((int)$it['item_id'])));
                                                                    $iqty = (int)$it['quantity'];
                                                                    $iprice = number_format((float)$it['price'], 2, ',', ' ') . ' €';
                                                                    $itImage = $it['item_image'] ?? '';
                                                                    $itSrc = resolveImageSrcAdmin($itImage);
                                                                ?>
                                                                <li class="d-flex align-items-center mb-1">
                                                                    <img src="<?php echo htmlspecialchars($itSrc); ?>" alt="" class="order-items-img me-2" style="width:48px;height:48px;object-fit:cover;border-radius:6px;">
                                                                    <div>
                                                                        <div><?php echo $iname; ?> × <?php echo $iqty; ?></div>
                                                                        <div class="small text-muted"><?php echo $iprice; ?> / unité</div>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="text-muted small">Aucun détail de commande disponible.</div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                                    <a href="edit_order.php?id=<?php echo $orderId; ?>" class="btn btn-sm btn-warning">Voir / Modifier</a>
                                                    <a href="../orders_invoices.php?order_id=<?php echo $orderId; ?>" class="btn btn-sm btn-outline-secondary">Facture</a>
                                                    <a href="user_activity.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-sm btn-outline-info">Actualiser</a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Aucune commande trouvée.</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($warnings)): ?>
                    <div class="alert alert-warning">
                        <ul class="mb-0">
                            <?php foreach ($warnings as $w): ?>
                                <li><?php echo htmlspecialchars($w); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </section>
</main>

<script>
(function(){
    // nothing fancy needed — keep page behaviour simple and consistent with other admin lists
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
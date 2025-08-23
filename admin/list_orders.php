<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'includes/header.php';
?>
<script>
try { document.body.classList.add('admin-page'); } catch(e){}
</script>
<?php
// optional date filter (GET)
$from = !empty($_GET['from']) ? $_GET['from'] : '';
$to = !empty($_GET['to']) ? $_GET['to'] : '';

try {
    $sql = "
        SELECT o.*, u.name AS user_name, u.email AS user_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
    ";
    $params = [];
    if ($from || $to) {
        $clauses = [];
        if ($from) { $clauses[] = "DATE(o.order_date) >= ?"; $params[] = $from; }
        if ($to)   { $clauses[] = "DATE(o.order_date) <= ?"; $params[] = $to; }
        $sql .= " WHERE " . implode(' AND ', $clauses);
    }
    $sql .= " ORDER BY o.order_date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $orders = [];
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}
?>
<style>
:root{
    --card-radius:12px;
    --muted:#6c757d;
    --bg-gradient-1:#f8fbff;
    --bg-gradient-2:#eef7ff;
    --accent:#0d6efd;
    --accent-2:#6610f2;
}

/* page-wide admin look */
body.admin-page {
    background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2));
}

/* main panel */
.panel-card {
    border-radius: var(--card-radius);
    background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
    box-shadow: 0 14px 40px rgba(3,37,76,0.06);
    padding: 1.25rem;
}

/* header */
.page-title {
    display:flex;
    align-items:center;
    gap:1rem;
}
.page-title h2 {
    margin:0;
    font-weight:700;
    color:var(--accent-2);
    background: linear-gradient(90deg, var(--accent), var(--accent-2));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

/* controls */
.controls { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.controls .btn { border-radius:8px; }

/* table */
.table thead th {
    background: linear-gradient(180deg,#fbfdff,#f2f7ff);
    font-weight:600;
    border-bottom:1px solid rgba(3,37,76,0.06);
}
.badge-status { border-radius:8px; padding:.35em .6em; font-size:.9rem; }

/* search */
.input-search { max-width:540px; width:100%; }

/* responsive tweaks */
@media (max-width: 768px) {
    .controls { width:100%; justify-content:space-between; }
}
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Gestion des commandes</h2>
                <div class="small text-muted">Historique & gestion — journalisé dans le centre de notifications</div>
            </div>

            <div class="controls">
                <a href="add_order.php" class="btn btn-primary btn-sm"><i class="bi bi-bag-plus me-1"></i> Créer une commande</a>
                <a href="list_orders.php" class="btn btn-outline-secondary btn-sm">Actualiser</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3 d-flex gap-3 align-items-center flex-wrap">
                <div class="input-group me-auto input-search">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input id="ordersSearch" type="search" class="form-control" placeholder="Rechercher par ID, client, statut ou montant...">
                </div>

                <form method="get" class="d-flex gap-2 align-items-center">
                    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($from); ?>">
                    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($to); ?>">
                    <button class="btn btn-sm btn-outline-primary">Filtrer</button>
                </form>
            </div>
        </div>

        <div class="table-responsive rounded shadow-sm">
            <table class="table table-hover table-striped align-middle mb-0" id="ordersTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px;">ID</th>
                        <th>Client</th>
                        <th class="text-end">Montant</th>
                        <th>Statut</th>
                        <th>Date</th>
                        <th style="width:240px;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">Aucune commande trouvée.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <?php
                                $oid = (int)$order['id'];
                                $client_raw = ($order['user_name'] ?? 'Utilisateur') . ' — ' . ($order['user_email'] ?? '');
                                $client = htmlspecialchars($client_raw);
                                $amount = number_format((float)$order['total_price'], 2, '.', '');
                                $status = $order['status'] ?? 'pending';
                                $date = htmlspecialchars($order['order_date']);
                                $badgeClass = match($status) {
                                    'pending' => 'bg-warning text-dark',
                                    'processing' => 'bg-secondary text-white',
                                    'shipped' => 'bg-info text-white',
                                    'delivered' => 'bg-success text-white',
                                    'cancelled' => 'bg-danger text-white',
                                    default => 'bg-secondary text-white'
                                };
                                $searchText = strtolower($oid . ' ' . $client_raw . ' ' . $status . ' ' . $amount);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($searchText); ?>">
                                <td class="fw-bold text-secondary"><?php echo $oid; ?></td>
                                <td><?php echo $client; ?></td>
                                <td class="text-end"><strong><?php echo $amount; ?> €</strong></td>
                                <td><span class="badge <?php echo $badgeClass; ?> badge-status"><?php echo ucfirst(htmlspecialchars($status)); ?></span></td>
                                <td><small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($date)); ?></small></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <a href="edit_order.php?id=<?php echo $oid; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i> Modifier</a>
                                        <a href="user_activity.php?user_id=<?php echo (int)$order['user_id']; ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-person"></i> Client</a>
                                        <button type="button" onclick="confirmDelete(<?php echo $oid; ?>)" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Supprimer</button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script>
(function(){
    var input = document.getElementById('ordersSearch');
    var rows = Array.from(document.querySelectorAll('#ordersTable tbody tr[data-search]'));
    if (!input) return;
    input.addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        rows.forEach(function(r){
            var txt = r.getAttribute('data-search') || '';
            r.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();

function confirmDelete(orderId) {
    if (!confirm('Êtes-vous sûr de vouloir supprimer cette commande ? Cette action est irréversible.')) return;
    window.location.href = 'delete_order.php?id=' + encodeURIComponent(orderId);
}
</script>

<?php include 'includes/footer.php'; ?>

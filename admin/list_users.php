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

// Récupérer utilisateurs
try {
    $stmt = $pdo->query("SELECT id, name, email, role, created_at FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}

// helper pour étiquettes de rôle
function roleLabel(string $role): string {
    return ($role === 'admin') ? 'Admin' : 'Utilisateur';
}

?>
<script>try { document.body.classList.add('admin-page'); } catch(e){}</script>

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
    -webkit-font-smoothing:antialiased;
    -moz-osx-font-smoothing:grayscale;
}
.panel-card {
    border-radius: var(--card-radius);
    background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
    box-shadow: 0 12px 36px rgba(3,37,76,0.06);
    padding: 1.25rem;
}
.page-title { display:flex; gap:1rem; align-items:center; }
.page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
.controls { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
.btn-round { border-radius:8px; }
.table thead th {
    background: linear-gradient(180deg,#fbfdff,#f2f7ff);
    border-bottom:1px solid rgba(3,37,76,0.06);
    font-weight:600;
}
.badge-role { border-radius:8px; padding:.35em .6em; font-size:.9rem; }
.input-search { max-width:620px; width:100%; }
.small-muted { color:var(--muted); font-size:.95rem; }
@media (max-width:768px) {
    .controls { width:100%; justify-content:space-between; }
}
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Gestion des utilisateurs</h2>
                <div class="small text-muted ms-2">Liste, recherche et actions rapides — journalisé dans le centre de notifications</div>
            </div>

            <div class="controls">
                <a href="add_user.php" class="btn btn-primary btn-sm btn-round"><i class="bi bi-person-plus me-1"></i> Ajouter un utilisateur</a>
                <a href="list_users.php" class="btn btn-outline-secondary btn-sm btn-round">Actualiser</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3 p-3">
            <div class="d-flex gap-3 align-items-center w-100 flex-wrap">
                <div class="input-group input-search me-auto">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input id="usersSearch" type="search" class="form-control" placeholder="Rechercher par ID, nom ou email..." aria-label="Rechercher utilisateurs">
                </div>

                <div>
                    <a href="list_users.php" class="btn btn-outline-secondary btn-sm">Exporter CSV</a>
                </div>
            </div>
        </div>

        <div class="table-responsive rounded shadow-sm">
            <table class="table table-hover table-striped align-middle mb-0" id="usersTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px;">ID</th>
                        <th>Nom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th>Créé le</th>
                        <th style="width:300px;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">Aucun utilisateur trouvé.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $uid = (int)$user['id'];
                                $name = htmlspecialchars($user['name'] ?? '');
                                $email = htmlspecialchars($user['email'] ?? '');
                                $role = $user['role'] ?? 'user';
                                $created = !empty($user['created_at']) && $user['created_at'] !== '0000-00-00 00:00:00'
                                    ? date('d/m/Y H:i', strtotime($user['created_at']))
                                    : '-';
                                $roleLabel = roleLabel($role);
                                $roleClass = ($role === 'admin') ? 'bg-danger text-white' : 'bg-primary text-white';
                                $dataSearch = strtolower($uid . ' ' . $name . ' ' . $email . ' ' . $role);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($dataSearch); ?>">
                                <td class="fw-bold text-secondary"><?php echo $uid; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo $name; ?></div>
                                </td>
                                <td><a href="mailto:<?php echo $email; ?>"><?php echo $email; ?></a></td>
                                <td><span class="badge <?php echo $roleClass; ?> badge-role"><?php echo htmlspecialchars($roleLabel); ?></span></td>
                                <td><small class="text-muted"><?php echo $created; ?></small></td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <a href="edit_user.php?id=<?php echo $uid; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i> Modifier</a>
                                        <a href="user_activity.php?user_id=<?php echo $uid; ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-list-check"></i> Activité</a>
                                        <a href="reset_user_password.php?id=<?php echo $uid; ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-key"></i> Réinitialiser</a>

                                        <?php
                                            // Protection : empêcher suppression du compte admin connecté
                                            $currentAdminId = intval($_SESSION['admin_id'] ?? 0);
                                            $isProtected = ($role === 'admin' && $uid === $currentAdminId);
                                        ?>
                                        <?php if (!$isProtected): ?>
                                            <a href="delete_user.php?id=<?php echo $uid; ?>" class="btn btn-sm btn-danger"
                                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ? Cette action est irréversible.');">
                                               <i class="bi bi-trash"></i> Supprimer
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled title="Protection admin — suppression désactivée"><i class="bi bi-shield-lock"></i> Protégé</button>
                                        <?php endif; ?>
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
    var input = document.getElementById('usersSearch');
    var rows = Array.from(document.querySelectorAll('#usersTable tbody tr[data-search]'));
    if (!input) return;
    input.addEventListener('input', function(){
        var q = this.value.trim().toLowerCase();
        rows.forEach(function(r){
            var txt = r.getAttribute('data-search') || '';
            r.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
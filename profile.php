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
$user_id = $_SESSION['user_id'] ?? 0;

// Récupérez les informations de l'utilisateur à partir de la base de données
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$user_id]);
$user = $query->fetch(PDO::FETCH_ASSOC);

// Vérifiez si la clé 'user_name' est définie dans la session / bdd
$user_name = isset($user['name']) ? $user['name'] : 'Utilisateur';
$user_email = isset($user['email']) ? $user['email'] : '';
$created_at = isset($user['created_at']) ? $user['created_at'] : '';

/**
 * Retourne les initiales pour l'avatar placeholder
 */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: 'U';
}
?>
<style>
:root{
    --card-radius:12px;
    --muted:#6c757d;
    --bg:#f6f9fc;
    --panel-bg: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
    --accent:#0d6efd;
    --accent-2:#6610f2;
}
body { background: var(--bg); }
.profile-wrap { max-width: 980px; margin: 0 auto; }
.panel-card {
    border-radius: var(--card-radius);
    background: var(--panel-bg);
    box-shadow: 0 12px 36px rgba(3,37,76,0.06);
    padding: 1.25rem;
}
.profile-header {
    display:flex;
    gap:1rem;
    align-items:center;
    margin-bottom:1rem;
}
.avatar {
    width:84px;
    height:84px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.6rem;
    font-weight:700;
    color:#fff;
    background: linear-gradient(135deg,var(--accent),var(--accent-2));
    box-shadow:0 8px 20px rgba(3,37,76,0.06);
}
.profile-meta h2 { margin:0; font-size:1.35rem; }
.profile-meta .muted { color:var(--muted); font-size:.95rem; margin-top:.25rem; }
.grid {
    display:grid;
    grid-template-columns: 1fr 420px;
    gap:1.25rem;
}
@media (max-width: 992px) {
    .grid { grid-template-columns: 1fr; }
}
.info-card { padding:1rem; border-radius:10px; background:#fff; box-shadow:0 6px 18px rgba(3,37,76,0.03); }
.form-card { padding:1rem; border-radius:10px; background:#fff; box-shadow:0 6px 18px rgba(3,37,76,0.03); }
.small-muted { color:var(--muted); font-size:0.92rem; }
.btn-block { width:100%; }
.form-label { font-weight:600; }
.form-actions { display:flex; gap:.5rem; justify-content:space-between; align-items:center; margin-top:1rem; flex-wrap:wrap; }
@media (max-width:420px){ .form-actions { flex-direction:column; align-items:stretch; } }
</style>

<main class="container py-4 profile-wrap">
    <section class="panel-card mb-4">
        <div class="profile-header">
            <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars(initials($user_name)); ?></div>
            <div class="profile-meta">
                <h2>Profil de <?php echo htmlspecialchars($user_name); ?></h2>
                <div class="muted small-muted">Membre depuis : <?php echo $created_at ? htmlspecialchars((new DateTime($created_at))->format('d/m/Y')) : '—'; ?></div>
            </div>
            <div style="margin-left:auto;">
                <a href="update_profile.php" class="btn btn-outline-primary btn-sm">Modifier le profil</a>
                <a href="update_password.php" class="btn btn-outline-secondary btn-sm">Changer le mot de passe</a>
            </div>
        </div>

        <?php if (isset($_SESSION['profile_update_success'])): ?>
            <div class="alert alert-success mb-3">
                <?php echo htmlspecialchars($_SESSION['profile_update_success']); unset($_SESSION['profile_update_success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['demo_error'])): ?>
            <div class="alert alert-danger mb-3"><?php echo htmlspecialchars($_SESSION['demo_error']); unset($_SESSION['demo_error']); ?></div>
        <?php endif; ?>

        <div class="grid">
            <div>
                <div class="info-card mb-3">
                    <h5 class="mb-2">Informations</h5>
                    <p class="small-muted mb-2"><strong>Email :</strong> <?php echo htmlspecialchars($user_email); ?></p>
                    <p class="small-muted mb-0"><strong>Date de création :</strong> <?php echo $created_at ? htmlspecialchars((new DateTime($created_at))->format('d/m/Y H:i')) : '—'; ?></p>
                </div>

                <div class="info-card mb-3">
                    <h5 class="mb-2">Mes commandes récentes</h5>
                    <?php
                        // quick fetch of last 5 orders
                        try {
                            $s = $pdo->prepare("SELECT id, total_price, status, order_date FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 5");
                            $s->execute([$user_id]);
                            $recent = $s->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) { $recent = []; }
                    ?>
                    <?php if ($recent): ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach ($recent as $o): ?>
                                <li class="mb-2">
                                    <a href="order_details.php?id=<?php echo (int)$o['id']; ?>" class="fw-semibold">Commande #<?php echo (int)$o['id']; ?></a>
                                    <div class="small-muted"><?php echo htmlspecialchars((new DateTime($o['order_date']))->format('d/m/Y')); ?> — <?php echo number_format((float)$o['total_price'],2,',',' ') ; ?> €</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="mt-2"><a href="orders_invoices.php" class="btn btn-sm btn-outline-primary">Voir toutes mes commandes</a></div>
                    <?php else: ?>
                        <div class="text-muted">Aucune commande récente.</div>
                    <?php endif; ?>
                </div>

                <div class="info-card">
                    <h5 class="mb-2">Favoris</h5>
                    <?php
                        try {
                            $f = $pdo->prepare("SELECT i.id, i.name FROM favorites f JOIN items i ON f.item_id = i.id WHERE f.user_id = ? ORDER BY f.id DESC LIMIT 6");
                            $f->execute([$user_id]);
                            $favs = $f->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) { $favs = []; }
                    ?>
                    <?php if ($favs): ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($favs as $fv): ?>
                                <a href="product_detail.php?id=<?php echo (int)$fv['id']; ?>" class="btn btn-outline-secondary btn-sm"><?php echo htmlspecialchars($fv['name']); ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Aucun favori.</div>
                    <?php endif; ?>
                </div>
            </div>

            <aside>
                <div class="form-card">
                    <h5 class="mb-3">Actions rapides</h5>

                    <div class="d-grid gap-2 mb-3">
                        <a href="update_profile.php" class="btn btn-primary btn-block">Modifier le profil</a>
                        <a href="update_password.php" class="btn btn-outline-secondary btn-block">Changer le mot de passe</a>
                    </div>

                    <div class="mt-2 small-muted">Les modifications sensibles peuvent être restreintes en mode démo.</div>
                </div>

                <div class="form-card mt-3 text-center">
                    <h6 class="mb-2">Supprimer le compte</h6>
                    <form action="delete_account.php" method="post" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.');">
                        <button type="submit" class="btn btn-danger btn-block">Supprimer</button>
                    </form>
                </div>
            </aside>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
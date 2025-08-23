<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'admin_demo_guard.php';
include_once 'includes/header.php';

// Générer token CSRF si absent (partagé avec autres pages admin)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID utilisateur invalide.";
    header("Location: list_users.php");
    exit;
}

// Récupérer l'utilisateur
try {
    $query = $pdo->prepare("SELECT id, email, password FROM users WHERE id = ?");
    $query->execute([$id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_users.php");
    exit;
}

if (!$user) {
    $_SESSION['error'] = "Utilisateur introuvable.";
    header("Location: list_users.php");
    exit;
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Demo guard (même logique que les autres pages)
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $errors[] = "Action désactivée en mode démo.";
    }

    // CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Jeton CSRF invalide. Rechargez la page et réessayez.";
    }

    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation basique + complexité
    if ($new_password === '') {
        $errors[] = "Le nouveau mot de passe est requis.";
    } elseif (strlen($new_password) < 8) {
        $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
    } else {
        if (!preg_match('/[A-Z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
        if (!preg_match('/[a-z]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
        if (!preg_match('/[\W_]/', $new_password)) $errors[] = "Le mot de passe doit contenir au moins un caractère spécial.";
    }

    if ($new_password !== $confirm_password) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }

    // Ne pas réutiliser l'ancien mot de passe
    if (empty($errors) && !empty($user['password'])) {
        if (password_verify($new_password, $user['password'])) {
            $errors[] = "Le nouveau mot de passe ne doit pas être identique à l'ancien.";
        }
    }

    if (empty($errors)) {
        try {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            // ATTENTION : la table `users` de ta base n'a pas de colonne `updated_at`,
            // on met à jour uniquement le password pour éviter SQLSTATE[42S22].
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $ok = $stmt->execute([$hashed, $id]);
            if ($ok) {
                $success = "Mot de passe réinitialisé avec succès.";

                // Notification admin (non-persistante)
                try {
                    $notif = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                    $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                    $notif->execute([
                        'admin_action',
                        "Mot de passe réinitialisé pour l'utilisateur ID $id ({$user['email']}) par {$adminName}"
                    ]);
                } catch (Exception $e) {
                    // ignore logging failure
                }

                $_SESSION['success'] = $success;
                header("Location: edit_user.php?id=" . $id);
                exit;
            } else {
                $errors[] = "Erreur lors de la mise à jour du mot de passe.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    // Si erreurs, on les conserve dans $errors et on affiche la page (pas de redirection)
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
body.admin-page { background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2)); -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale; }
.panel-card { border-radius: var(--card-radius); background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff); box-shadow: 0 12px 36px rgba(3,37,76,0.06); padding: 1.25rem; }
.page-title { display:flex; gap:1rem; align-items:center; }
.page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
.form-card { border-radius:12px; }
</style>

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Réinitialiser le mot de passe</h2>
                <div class="small text-muted ms-2">Réinitialisez le mot de passe d'un utilisateur — action journalisée.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_users.php" class="btn btn-outline-secondary btn-sm btn-round">← Retour à la liste</a>
                <a href="edit_user.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-primary btn-sm btn-round">← Fiche utilisateur</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-12 col-md-7">
                <div class="card form-card shadow-sm p-3 mb-3">
                    <div class="card-body">
                        <h5 class="mb-3">Utilisateur</h5>
                        <p class="small text-muted mb-0">Réinitialiser le mot de passe pour : <strong><?php echo htmlspecialchars($user['email'] ?? ('ID ' . intval($id))); ?></strong></p>
                        <p class="small text-muted">ID : <?php echo (int)$id; ?></p>
                    </div>
                </div>

                <form method="post" class="card p-3 shadow-sm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                    <div class="mb-3">
                        <label for="new_password" class="form-label">Nouveau mot de passe</label>
                        <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8" autocomplete="new-password">
                        <div class="form-text">Au moins 8 caractères, une majuscule, une minuscule et un caractère spécial.</div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmer le mot de passe</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required minlength="8" autocomplete="new-password">
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <a href="edit_user.php?id=<?php echo (int)$id; ?>" class="btn btn-outline-secondary">Annuler</a>
                        <button type="submit" class="btn btn-warning">Réinitialiser</button>
                    </div>
                </form>
            </div>

            <div class="col-12 col-md-5">
                <div class="card shadow-sm p-3">
                    <h6 class="mb-2">Notes</h6>
                    <ul class="mb-0 small text-muted">
                        <li>Cette action est soumise aux protections du mode démo.</li>
                        <li>Le système journalise l'action dans le centre de notifications pour audit.</li>
                        <li>Si l'opération réussit, vous serez redirigé vers la fiche utilisateur.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
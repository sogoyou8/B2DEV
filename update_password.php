<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Activer buffering pour éviter "headers already sent" si includes/header.php émet du HTML.
 */
if (!ob_get_level()) {
    ob_start();
}

/*
 * Vérification connexion utilisateur : redirection avant inclusion du header/affichage
 */
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

include 'includes/db.php';
include 'admin/admin_demo_guard.php';

$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

/*
 * Traitement POST : fait avant include 'includes/header.php' pour permettre header() sans erreur.
 */
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Protection mode démo
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['demo_error'] = "Action désactivée en mode démo.";
        header("Location: profile.php");
        exit;
    }

    $current_password = trim($_POST['current_password'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');

    // Validation des données côté serveur
    if ($current_password === '') {
        $errors[] = "Le mot de passe actuel est requis.";
    }
    if ($new_password === '') {
        $errors[] = "Le nouveau mot de passe est requis.";
    }
    if ($confirm_password === '') {
        $errors[] = "La confirmation du nouveau mot de passe est requise.";
    }
    if ($new_password !== '' && $confirm_password !== '' && $new_password !== $confirm_password) {
        $errors[] = "Les nouveaux mots de passe ne correspondent pas.";
    }
    if ($new_password !== '' && strlen($new_password) < 8) {
        $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
    }

    if (empty($errors)) {
        try {
            // Vérifier le mot de passe actuel dans la base
            $query = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $query->execute([$user_id]);
            $user = $query->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $errors[] = "Utilisateur introuvable.";
            } else {
                if (password_verify($current_password, $user['password'])) {
                    // Ne pas autoriser le même mot de passe
                    if (!empty($user['password']) && password_verify($new_password, $user['password'])) {
                        $errors[] = "Le nouveau mot de passe doit être différent de l'ancien.";
                    } else {
                        $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
                        $upd = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $upd->execute([$new_password_hashed, $user_id]);

                        $_SESSION['profile_update_success'] = "Votre mot de passe a été mis à jour avec succès.";
                        // Redirection PRG vers profile.php
                        header("Location: profile.php");
                        exit;
                    }
                } else {
                    $errors[] = "Le mot de passe actuel est incorrect.";
                }
            }
        } catch (Exception $e) {
            $errors[] = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    }
}

/*
 * Inclure le header maintenant que tout traitement POST/redirect possible est fait.
 */
include 'includes/header.php';
?>
<style>
/* Amélioration visuelle pour update_password.php */
.update-password-wrapper { max-width: 640px; margin: 28px auto; padding: 0 16px; }
.card { background:#ffffff; padding:22px; border-radius:12px; box-shadow:0 8px 20px rgba(20,20,50,0.04); }
.header-row { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:12px; }
.title { font-size:1.25rem; font-weight:700; margin:0; }
.subtitle { color:#6b7280; font-size:0.95rem; margin-top:4px; }
.form-group { margin-bottom:14px; }
.form-group label { display:block; font-weight:600; margin-bottom:6px; color:#111827; }
.form-control { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.95rem; box-sizing:border-box; }
.actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:12px; }
.btn { padding:10px 16px; border-radius:8px; font-weight:600; cursor:pointer; border:1px solid transparent; }
.btn-primary { background:#2563eb; color:#fff; border-color:transparent; }
.btn-outline { background:transparent; color:#2563eb; border:1px solid #cfe0ff; }
.btn-ghost { background:#f8fafc; color:#0f172a; border:1px solid #e6eefc; }
.btn-danger { background:#ef4444; color:#fff; border-color:transparent; }
.alert-success { background:#ecfdf5; color:#065f46; padding:12px; border-radius:8px; margin-bottom:12px; border:1px solid #bbf7d0; }
.alert-error { background:#fff1f2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:12px; border:1px solid #fecaca; }
.hint { color:#6b7280; font-size:0.9rem; margin-top:6px; }
.pw-strength { height:8px; border-radius:6px; background:#e6eefc; margin-top:8px; overflow:hidden; }
.pw-strength > i { display:block; height:100%; width:0%; background:linear-gradient(90deg,#f97316,#10b981); transition:width .25s ease; }
@media (max-width:640px){ .update-password-wrapper { padding:0 12px; } }
</style>

<main class="update-password-wrapper" role="main" aria-labelledby="updatePasswordTitle">
    <section class="card" aria-labelledby="updatePasswordTitle">
        <div class="header-row">
            <div>
                <h2 id="updatePasswordTitle" class="title">Changer le mot de passe</h2>
                <div class="subtitle">Modifiez votre mot de passe. Vous pouvez annuler pour revenir à votre profil.</div>
            </div>
            <div>
                <a href="profile.php" class="btn btn-outline" title="Retourner au profil">← Retour au profil</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-error" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['profile_update_success'])): ?>
            <div class="alert-success" role="status"><?php echo htmlspecialchars($_SESSION['profile_update_success']); unset($_SESSION['profile_update_success']); ?></div>
        <?php endif; ?>

        <form id="updatePasswordForm" action="update_password.php" method="post" novalidate autocomplete="off">
            <div class="form-group">
                <label for="current_password">Mot de passe actuel :</label>
                <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="new_password">Nouveau mot de passe :</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required autocomplete="new-password" minlength="8">
                <div class="hint">Au moins 8 caractères. Inclure majuscule/minuscule et caractère spécial recommandé.</div>
                <div class="pw-strength" aria-hidden="true"><i id="pwBar" style="width:0%"></i></div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirmer le nouveau mot de passe :</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required autocomplete="new-password" minlength="8">
            </div>

            <div class="actions" role="group" aria-label="Actions du formulaire">
                <button type="submit" class="btn btn-primary">Changer le mot de passe</button>
            </div>
        </form>
    </section>
</main>

<script>
(function(){
    var form = document.getElementById('updatePasswordForm');
    var newPw = document.getElementById('new_password');
    var confirmPw = document.getElementById('confirm_password');
    var cancelBtn = document.getElementById('cancelBtn');
    var pwBar = document.getElementById('pwBar');
    var isDirty = false;

    function scorePassword(pw) {
        var score = 0;
        if (!pw) return 0;
        if (pw.length >= 8) score += 1;
        if (/[A-Z]/.test(pw)) score += 1;
        if (/[a-z]/.test(pw)) score += 1;
        if (/[0-9]/.test(pw)) score += 1;
        if (/[\W_]/.test(pw)) score += 1;
        return score;
    }

    function updateStrength() {
        var s = scorePassword(newPw.value);
        var pct = Math.min(100, Math.round((s / 5) * 100));
        pwBar.style.width = pct + '%';
        if (pct < 40) pwBar.style.background = '#ef4444'; // red
        else if (pct < 70) pwBar.style.background = '#f59e0b'; // amber
        else pwBar.style.background = 'linear-gradient(90deg,#f97316,#10b981)';
    }

    if (newPw) {
        newPw.addEventListener('input', function(){
            isDirty = true;
            updateStrength();
        }, false);
    }
    if (confirmPw) {
        confirmPw.addEventListener('input', function(){ isDirty = true; }, false);
    }
    if (form) {
        form.addEventListener('submit', function(e){
            var localErrors = [];
            if (!document.getElementById('current_password').value.trim()) localErrors.push('Le mot de passe actuel est requis.');
            var npw = newPw.value.trim();
            if (!npw) localErrors.push('Le nouveau mot de passe est requis.');
            if (npw && npw.length < 8) localErrors.push('Le nouveau mot de passe doit contenir au moins 8 caractères.');
            if (npw !== confirmPw.value.trim()) localErrors.push('Les nouveaux mots de passe ne correspondent pas.');
            if (localErrors.length) {
                e.preventDefault();
                alert(localErrors.join('\\n'));
                return false;
            }
            return true;
        }, false);
    }

    cancelBtn && cancelBtn.addEventListener('click', function(){
        if (isDirty) {
            if (!confirm('Vous avez des modifications non enregistrées. Voulez-vous annuler et revenir au profil ?')) {
                return;
            }
        }
        window.location.href = 'profile.php';
    }, false);

    // initial strength update (in case browser autofill)
    updateStrength();
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php if (ob_get_level()) { ob_end_flush(); } ?>
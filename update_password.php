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

    // Vérifier correspondance des mots de passe (si fournis)
    if ($new_password !== '' && $confirm_password !== '' && $new_password !== $confirm_password) {
        $errors[] = "Les nouveaux mots de passe ne correspondent pas.";
    }

    /*
     * Appliquer les mêmes règles de complexité que register.php / admin/reset_user_password.php :
     * - au moins 8 caractères
     * - au moins une majuscule
     * - au moins une minuscule
     * - au moins un caractère spécial (ou underscore)
     */
    if ($new_password !== '') {
        if (strlen($new_password) < 8) {
            $errors[] = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
        } else {
            if (!preg_match('/[A-Z]/', $new_password)) {
                $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
            }
            if (!preg_match('/[a-z]/', $new_password)) {
                $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
            }
            if (!preg_match('/[\W_]/', $new_password)) {
                $errors[] = "Le mot de passe doit contenir au moins un caractère spécial.";
            }
        }
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

// charger le fichier CSS séparé au lieu du style inline
echo '<link rel="stylesheet" href="assets/css/user/update_password.css">' ;
?>
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
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Conteneur pour erreurs côté client (JS) : même apparence que register.php -->
        <div id="clientErrors" class="alert alert-danger" style="display:none;">
            <ul class="mb-0" id="clientErrorsList"></ul>
        </div>

        <?php if (!empty($_SESSION['profile_update_success'])): ?>
            <div class="alert alert-success" role="status"><?php echo htmlspecialchars($_SESSION['profile_update_success']); unset($_SESSION['profile_update_success']); ?></div>
        <?php endif; ?>

        <form id="updatePasswordForm" action="update_password.php" method="post" novalidate autocomplete="off">
            <div class="form-group">
                <label for="current_password">Mot de passe actuel :</label>
                <input type="password" name="current_password" id="current_password" class="form-control" required autocomplete="current-password">
            </div>

            <div class="form-group">
                <label for="new_password">Nouveau mot de passe :</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required autocomplete="new-password" minlength="8">
                <div class="hint">Au moins 8 caractères. Inclure majuscule/minuscule et caractère spécial.</div>
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
    var pwBar = document.getElementById('pwBar');
    var clientErrorsEl = document.getElementById('clientErrors');
    var clientErrorsList = document.getElementById('clientErrorsList');

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

    function showClientErrors(errors) {
        if (!clientErrorsEl || !clientErrorsList) return;
        clientErrorsList.innerHTML = '';
        errors.forEach(function(err){
            var li = document.createElement('li');
            li.textContent = err;
            clientErrorsList.appendChild(li);
        });
        clientErrorsEl.style.display = 'block';
        // Scroller vers le conteneur d'erreurs pour visibilité
        try { clientErrorsEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch(e){}
    }

    function clearClientErrors() {
        if (!clientErrorsEl || !clientErrorsList) return;
        clientErrorsList.innerHTML = '';
        clientErrorsEl.style.display = 'none';
    }

    if (newPw) {
        newPw.addEventListener('input', function(){
            updateStrength();
            clearClientErrors();
        }, false);
    }
    if (confirmPw) {
        confirmPw.addEventListener('input', function(){ clearClientErrors(); }, false);
    }
    if (form) {
        form.addEventListener('submit', function(e){
            var localErrors = [];
            if (!document.getElementById('current_password').value.trim()) localErrors.push('Le mot de passe actuel est requis.');
            var npw = newPw.value.trim();
            var cpw = confirmPw.value.trim();
            if (!npw) localErrors.push('Le nouveau mot de passe est requis.');
            if (!cpw) localErrors.push('La confirmation du mot de passe est requise.');
            if (npw && npw.length < 8) localErrors.push('Le nouveau mot de passe doit contenir au moins 8 caractères.');
            if (npw && !/[A-Z]/.test(npw)) localErrors.push('Le mot de passe doit contenir au moins une majuscule.');
            if (npw && !/[a-z]/.test(npw)) localErrors.push('Le mot de passe doit contenir au moins une minuscule.');
            if (npw && !/[\W_]/.test(npw)) localErrors.push('Le mot de passe doit contenir au moins un caractère spécial.');
            if (npw && cpw && npw !== cpw) localErrors.push('Les nouveaux mots de passe ne correspondent pas.');

            if (localErrors.length) {
                e.preventDefault();
                showClientErrors(localErrors);
                return false;
            }
            // laisser le submit se faire pour envoi au serveur
            return true;
        }, false);
    }

    // initial strength update (in case browser autofill)
    updateStrength();
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php if (ob_get_level()) { ob_end_flush(); } ?>
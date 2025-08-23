<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/db.php';
include 'includes/header.php';
include 'admin/admin_demo_guard.php';

$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['demo_error'] = "Action désactivée en mode démo.";
        header("Location: profile.php");
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // Validation des données
    if (empty($name)) {
        $errors[] = "Le nom est requis.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Un email valide est requis.";
    }

    if (empty($errors)) {
        try {
            $query = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $query->execute([$name, $email, $user_id]);
            $success = "Votre profil a été mis à jour avec succès.";
            // mettre à jour la session si besoin
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
        } catch (Exception $e) {
            $errors[] = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
    }
}

// Récupérez les informations actuelles de l'utilisateur
try {
    $query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $query->execute([$user_id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $user = ['name' => '', 'email' => ''];
    $errors[] = "Impossible de charger les informations utilisateur.";
}
?>
<style>
/* Simple visual enhancements for update_profile.php */
.profile-container { max-width: 720px; margin: 28px auto; padding: 0 16px; }
.profile-card { background:#fff; padding:22px; border-radius:12px; box-shadow:0 8px 22px rgba(15,23,42,0.04); }
.profile-header { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
.profile-title { font-size:1.25rem; font-weight:700; margin:0; }
.small-muted { color:#6b7280; font-size:0.95rem; }
.form-row { margin-bottom:12px; }
.form-row label { display:block; font-weight:600; margin-bottom:6px; color:#111827; }
.form-control { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.95rem; }
.form-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
.btn { padding:10px 16px; border-radius:8px; cursor:pointer; font-weight:600; border:1px solid transparent; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-outline { background:transparent; color:#2563eb; border:1px solid #cfe0ff; }
.btn-ghost { background:#f8fafc; color:#0f172a; border:1px solid #e6eefc; }
.btn-danger { background:#ef4444; color:#fff; }
.alert-success { background:#ecfdf5; color:#065f46; padding:12px; border-radius:8px; margin-bottom:12px; border:1px solid #bbf7d0; }
.alert-error { background:#fff1f2; color:#991b1b; padding:12px; border-radius:8px; margin-bottom:12px; border:1px solid #fecaca; }
.preview-card { background:#f8fafc; padding:12px; border-radius:8px; margin-top:12px; font-size:0.95rem; color:#0f172a; }
@media (max-width:640px) {
    .profile-container { padding: 0 12px; }
}
</style>

<main class="profile-container" role="main" aria-labelledby="updateProfileTitle">
    <section class="profile-card" aria-labelledby="updateProfileTitle">
        <div class="profile-header">
            <div>
                <h2 id="updateProfileTitle" class="profile-title">Mettre à jour le profil</h2>
                <div class="small-muted">Modifiez vos informations — annulez pour revenir au profil.</div>
            </div>
            <div>
                <a href="profile.php" class="btn btn-outline" title="Retourner à la page profil">← Retour au profil</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert-error" role="alert">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form id="updateProfileForm" action="update_profile.php" method="post" novalidate>
            <div class="form-row">
                <label for="name">Nom :</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required>
            </div>

            <div class="form-row">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" required>
            </div>

            <div class="form-actions" role="group" aria-label="Actions du formulaire">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>

            <div id="livePreview" class="preview-card" aria-live="polite">
                <div><strong>Prévisualisation :</strong></div>
                <div style="margin-top:6px;"><strong>Nom :</strong> <span id="previewName"><?php echo htmlspecialchars($user['name'] ?? '—'); ?></span></div>
                <div style="margin-top:4px;"><strong>Email :</strong> <span id="previewEmail"><?php echo htmlspecialchars($user['email'] ?? '—'); ?></span></div>
            </div>
        </form>
    </section>
</main>

<script>
(function(){
    var form = document.getElementById('updateProfileForm');
    if (!form) return;

    var nameEl = document.getElementById('name');
    var emailEl = document.getElementById('email');
    var previewName = document.getElementById('previewName');
    var previewEmail = document.getElementById('previewEmail');
    var cancelBtn = document.getElementById('cancelBtn');
    var isDirty = false;

    function setDirty() { isDirty = true; }

    nameEl && nameEl.addEventListener('input', function(e){
        previewName.textContent = nameEl.value.trim() || '—';
        setDirty();
    }, false);

    emailEl && emailEl.addEventListener('input', function(e){
        previewEmail.textContent = emailEl.value.trim() || '—';
        setDirty();
    }, false);

    // Confirm before leaving if changes present
    cancelBtn && cancelBtn.addEventListener('click', function(){
        if (isDirty) {
            if (!confirm('Vous avez des modifications non enregistrées. Voulez-vous annuler et revenir au profil ?')) {
                return;
            }
        }
        window.location.href = 'profile.php';
    }, false);

    // Simple client-side validation on submit
    form && form.addEventListener('submit', function(e){
        var localErrors = [];
        if (!nameEl.value.trim()) localErrors.push('Le nom est requis.');
        var emailVal = emailEl.value.trim();
        if (!emailVal) localErrors.push('L\'email est requis.');
        else {
            var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!re.test(emailVal)) localErrors.push('Veuillez saisir une adresse email valide.');
        }
        if (localErrors.length) {
            e.preventDefault();
            alert(localErrors.join('\n'));
        }
    }, false);
})();
</script>

<?php include 'includes/footer.php'; ?>
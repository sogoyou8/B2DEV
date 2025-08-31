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

// helper adresse centralisé
include_once __DIR__ . '/includes/address.php';

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

    // address fields (optional)
    $billing_address = trim((string)($_POST['billing_address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? ''));
    $postal_code = trim((string)($_POST['postal_code'] ?? ''));
    $save_address = isset($_POST['save_address']) && ($_POST['save_address'] == '1' || $_POST['save_address'] === 'on') ? 1 : 0;

    // Validation des données
    if (empty($name)) {
        $errors[] = "Le nom est requis.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Un email valide est requis.";
    }

    // adresse : si un des champs est renseigné, valider minimalement
    if ($billing_address !== '' || $city !== '' || $postal_code !== '') {
        if ($billing_address === '') $errors[] = "Adresse de facturation requise si vous souhaitez l'enregistrer.";
        if ($city === '') $errors[] = "Ville requise si vous souhaitez l'enregistrer.";
        if ($postal_code === '') $errors[] = "Code postal requis si vous souhaitez l'enregistrer.";
    }

    if (empty($errors)) {
        try {
            // Mettre à jour nom/email dans users
            $query = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
            $query->execute([$name, $email, $user_id]);

            // Sauvegarder l'adresse avec le helper (gère l'absence de colonnes)
            // Si tous les champs d'adresse vides et save_address=0 => ne rien faire
            if ($billing_address !== '' || $city !== '' || $postal_code !== '' || $save_address) {
                // saveUserAddress vérifie l'existence des colonnes et effectue UPDATE
                saveUserAddress($pdo, (int)$user_id, $billing_address, $city, $postal_code, (int)$save_address);
            }

            $success = "Votre profil a été mis à jour avec succès.";
            // mettre à jour la session si besoin
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;

            // recharger les données utilisateur pour affichage (PRG non utilisé ici)
            try {
                $q = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $q->execute([$user_id]);
                $user = $q->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                // ignore
            }

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
    $user = ['name' => '', 'email' => '', 'billing_address' => '', 'city' => '', 'postal_code' => ''];
    $errors[] = "Impossible de charger les informations utilisateur.";
}

// Charger la feuille de styles dédiée
echo '<link rel="stylesheet" href="assets/css/user/update_profile.css">' ;
?>
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

            <hr style="margin:12px 0;border:none;border-top:1px solid #eee;">

            <h5 class="mb-2">Adresse de facturation (optionnel)</h5>

            <div class="form-row">
                <label for="billing_address">Adresse :</label>
                <input type="text" name="billing_address" id="billing_address" class="form-control" value="<?php echo htmlspecialchars($user['billing_address'] ?? ''); ?>">
            </div>

            <div class="form-grid" style="display:grid;grid-template-columns:1fr 160px;gap:8px;">
                <div class="form-row">
                    <label for="city">Ville :</label>
                    <input type="text" name="city" id="city" class="form-control" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>">
                </div>
                <div class="form-row">
                    <label for="postal_code">Code postal :</label>
                    <input type="text" name="postal_code" id="postal_code" class="form-control" pattern="^[0-9A-Za-z \-]{2,10}$" value="<?php echo htmlspecialchars($user['postal_code'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-check" style="margin-top:12px;">
                <?php $saved = !empty($user['save_address_default']) ? true : false; ?>
                <input type="checkbox" name="save_address" id="save_address" value="1" <?php echo $saved ? 'checked' : ''; ?>>
                <label for="save_address">Enregistrer comme adresse par défaut</label>
            </div>

            <div class="form-actions" role="group" aria-label="Actions du formulaire" style="margin-top:14px;">
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>

            <div id="livePreview" class="preview-card" aria-live="polite" style="margin-top:12px;">
                <div><strong>Prévisualisation :</strong></div>
                <div style="margin-top:6px;"><strong>Nom :</strong> <span id="previewName"><?php echo htmlspecialchars($user['name'] ?? '—'); ?></span></div>
                <div style="margin-top:4px;"><strong>Email :</strong> <span id="previewEmail"><?php echo htmlspecialchars($user['email'] ?? '—'); ?></span></div>
                <?php if (!empty($user['billing_address']) || !empty($user['city']) || !empty($user['postal_code'])): ?>
                    <div style="margin-top:8px;"><strong>Adresse :</strong><br>
                        <?php
                            $addr = [
                                'billing_address' => $user['billing_address'] ?? '',
                                'city' => $user['city'] ?? '',
                                'postal_code' => $user['postal_code'] ?? ''
                            ];
                            echo formatAddressForDisplay($addr);
                        ?>
                    </div>
                <?php endif; ?>
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
            alert(localErrors.join('\\n'));
        }
    }, false);
})();
</script>

<?php include 'includes/footer.php'; ?>
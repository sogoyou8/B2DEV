<?php
if (session_status() == PHP_SESSION_NONE) session_start();
ob_start();

// Vérification accès admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'admin_demo_guard.php';
include_once 'includes/header.php';

// Ensure admin visual style (some headers may omit)
?>
<script>try { document.body.classList.add('admin-page'); } catch (e) {}</script>
<?php

// Générer token CSRF si absent
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

// Traitement POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // protection mode démo
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: add_user.php");
        exit;
    }

    // CSRF
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $errors[] = "Jeton CSRF invalide. Rechargez la page et réessayez.";
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = in_array($_POST['role'] ?? 'user', ['user','admin']) ? $_POST['role'] : 'user';

    // Server-side validations
    if ($name === '' || mb_strlen($name) < 2) {
        $errors[] = "Le nom est requis (min 2 caractères).";
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Un email valide est requis.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Le mot de passe doit contenir au moins 6 caractères.";
    }

    // Vérifier unicité email
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $errors[] = "Cet email est déjà utilisé.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        try {
            $ins = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
            $ok = $ins->execute([$name, $email, $hashed, $role]);
            if ($ok) {
                // Notification non-persistante (journal)
                try {
                    $note = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
                    $note->execute([
                        'admin_action',
                        "Nouvel utilisateur créé : $name ($email) par " . ($_SESSION['admin_name'] ?? 'admin')
                    ]);
                } catch (Exception $e) {
                    // ignore logging failure
                }

                $_SESSION['success'] = "Utilisateur créé avec succès.";
                header("Location: list_users.php");
                exit;
            } else {
                $errors[] = "Impossible de créer l'utilisateur, réessayez.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur lors de l'insertion : " . $e->getMessage();
        }
    }

    // stocker erreurs en session pour affichage après redirection si besoin
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}

// Page HTML - harmonisée avec le style des listes admin (list_products/list_orders/list_users)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Ajouter un utilisateur</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <!-- moved inline admin CSS to external file -->
    <link rel="stylesheet" href="../assets/css/admin/add_user.css">
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Ajouter un utilisateur</h2>
                </div>
                <div class="small text-muted">Créez un compte utilisateur — actions sensibles protégées en mode démo.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_users.php" class="btn btn-outline-secondary btn-sm btn-round">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

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

        <div class="row gx-4">
            <div class="col-12 col-lg-7">
                <div class="card form-card shadow-sm mb-3">
                    <div class="card-body">
                        <form method="post" id="addUserForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                            <div class="mb-3">
                                <label for="name" class="form-label">Nom</label>
                                <input id="name" name="name" class="form-control" required minlength="2" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                            </div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input id="email" name="email" class="form-control" type="email" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Mot de passe</label>
                                <input id="password" name="password" class="form-control" type="password" required minlength="6" autocomplete="new-password">
                                <div class="form-text">Minimum 6 caractères.</div>
                            </div>

                            <div class="mb-3">
                                <label for="role" class="form-label">Rôle</label>
                                <select id="role" name="role" class="form-select" required>
                                    <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : ''; ?>>Utilisateur</option>
                                    <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : ''; ?>>Administrateur</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <a href="list_users.php" class="btn btn-outline-secondary">Annuler</a>
                                <button type="submit" class="btn btn-primary">Créer l'utilisateur</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="mb-2">Conseils</h6>
                        <ul class="mb-0">
                            <li>Le mot de passe doit contenir au moins 6 caractères.</li>
                            <li>Les administrateurs ont accès au panneau d'administration.</li>
                        </ul>
                        <div class="mt-2 small text-muted">Les actions sensibles sont protégées en mode démo.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="mb-2">Aperçu rapide</h6>
                        <div><strong>Nom :</strong> <span id="previewName" class="text-muted">—</span></div>
                        <div class="mt-2"><strong>Email :</strong> <span id="previewEmail" class="text-muted">—</span></div>
                        <div class="mt-3 help-note">Ce panneau n'affiche pas le mot de passe pour des raisons de sécurité.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function(){
    'use strict';
    var form = document.getElementById('addUserForm');
    if (!form) return;

    form.addEventListener('submit', function(event) {
        // simple HTML5 check
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
            alert('Veuillez corriger les erreurs du formulaire.');
        }
    }, false);

    // live preview
    var nameEl = document.getElementById('name');
    var emailEl = document.getElementById('email');
    var pName = document.getElementById('previewName');
    var pEmail = document.getElementById('previewEmail');

    function updatePreview() {
        pName.textContent = nameEl && nameEl.value ? nameEl.value : '—';
        pEmail.textContent = emailEl && emailEl.value ? emailEl.value : '—';
    }

    if (nameEl) nameEl.addEventListener('input', updatePreview);
    if (emailEl) emailEl.addEventListener('input', updatePreview);
    updatePreview();
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
</html>
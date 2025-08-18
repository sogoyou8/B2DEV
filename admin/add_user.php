<?php
if (session_status() == PHP_SESSION_NONE) session_start();

// Vérification accès admin
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'admin_demo_guard.php';
include_once 'includes/header.php';

// Générer token CSRF si absent
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors = [];
$success = '';

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

    // Validations
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
                // Notification de création (non-persistante)
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
?>

<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-7">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-2"></i>Ajouter un utilisateur</h5>
                    <a href="list_users.php" class="btn btn-sm btn-outline-secondary">Retour à la liste</a>
                </div>
                <div class="card-body">

                    <?php if (!empty($_SESSION['error'])): ?>
                        <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['success'])): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
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

                    <form method="post" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom</label>
                            <input id="name" name="name" class="form-control" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input id="email" name="email" type="email" class="form-control" required value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">Mot de passe</label>
                            <input id="password" name="password" type="password" class="form-control" required minlength="6" autocomplete="new-password">
                            <div class="form-text">Minimum 6 caractères.</div>
                        </div>

                        <div class="mb-3">
                            <label for="role" class="form-label">Rôle</label>
                            <select id="role" name="role" class="form-select">
                                <option value="user" <?php echo (isset($_POST['role']) && $_POST['role'] === 'user') ? 'selected' : ''; ?>>Utilisateur</option>
                                <option value="admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'admin') ? 'selected' : ''; ?>>Administrateur</option>
                            </select>
                            <div class="form-text">Choisissez le rôle. Attention : les admins peuvent accéder au panneau.</div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="list_users.php" class="btn btn-outline-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Créer l'utilisateur</button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    <span class="me-2"><i class="bi bi-info-circle"></i></span>
                    Les actions sensibles sont protégées en mode démo.
                </div>
            </div>
        </div>
    </div>
</main>

<?php include 'includes/footer.php'; ?>
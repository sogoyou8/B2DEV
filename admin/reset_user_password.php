<?php
if (session_status() == PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';
include 'admin_demo_guard.php';

$id = $_GET['id'];
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$id]);
$user = $query->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION['error'] = "Utilisateur introuvable.";
    header("Location: list_users.php");
    exit;
}

$success = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!guardDemoAdmin()) {
        $errors[] = "Action désactivée en mode démo.";
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Modalités du mot de passe
        if (strlen($new_password) < 6) {
            $errors[] = "Le mot de passe doit contenir au moins 6 caractères.";
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
        }
        if (!preg_match('/[a-z]/', $new_password)) {
            $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
        }
        if (!preg_match('/[0-9]/', $new_password)) {
            $errors[] = "Le mot de passe doit contenir au moins un chiffre.";
        }

        if ($new_password !== $confirm_password) {
            $errors[] = "Les mots de passe ne correspondent pas.";
        }
        if (password_verify($new_password, $user['password'])) {
            $errors[] = "Le nouveau mot de passe ne doit pas être identique à l'ancien.";
        }

        if (empty($errors)) {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hashed, $id]);
            $success = "Mot de passe réinitialisé avec succès.";

            // Notification admin
            $notif = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
            $notif->execute([
                'admin_action',
                "Mot de passe réinitialisé pour l'utilisateur ID $id par admin ID " . ($_SESSION['admin_id'] ?? 'N/A')
            ]);
        }
    }
}
include 'includes/header.php';
?>
<main class="container py-4">
    <h2>Réinitialiser le mot de passe de <?php echo htmlspecialchars($user['name']); ?></h2>
    <?php if ($success): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul>
                <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <div class="mb-3">
            <label for="new_password" class="form-label">Nouveau mot de passe</label>
            <input type="password" name="new_password" id="new_password" class="form-control" required minlength="6" autocomplete="new-password">
            <div class="form-text">
                Minimum 6 caractères, 1 majuscule, 1 minuscule, 1 chiffre.
            </div>
        </div>
        <div class="mb-3">
            <label for="confirm_password" class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="confirm_password" id="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
        </div>
        <button type="submit" class="btn btn-warning">Réinitialiser</button>
        <a href="edit_user.php?id=<?php echo $id; ?>" class="btn btn-secondary">Annuler</a>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
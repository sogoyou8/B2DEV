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

$user_id = $_SESSION['user_id'];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = trim($_POST['current_password']);
    $new_password = trim($_POST['new_password']);
    $confirm_password = trim($_POST['confirm_password']);

    // Validation des données
    if (empty($current_password)) {
        $errors[] = "Le mot de passe actuel est requis.";
    }
    if (empty($new_password)) {
        $errors[] = "Le nouveau mot de passe est requis.";
    }
    if ($new_password !== $confirm_password) {
        $errors[] = "Les nouveaux mots de passe ne correspondent pas.";
    }

    if (empty($errors)) {
        // Vérifiez le mot de passe actuel
        $query = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $query->execute([$user_id]);
        $user = $query->fetch(PDO::FETCH_ASSOC);

        if (password_verify($current_password, $user['password'])) {
            // Mettez à jour le mot de passe de l'utilisateur dans la base de données
            $new_password_hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $query = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $query->execute([$new_password_hashed, $user_id]);

            // Redirigez vers la page de profil avec un message de confirmation
            $_SESSION['profile_update_success'] = "Votre mot de passe a été mis à jour avec succès.";
            header("Location: profile.php");
            exit;
        } else {
            $errors[] = "Le mot de passe actuel est incorrect.";
        }
    }
}
?>
<main class="container py-4">
    <section class="update-password-section bg-light p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
        <h2 class="h3 mb-4 font-weight-bold">Changer le mot de passe</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <form action="update_password.php" method="post">
            <div class="form-group">
                <label for="current_password">Mot de passe actuel :</label>
                <input type="password" name="current_password" id="current_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="new_password">Nouveau mot de passe :</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmer le nouveau mot de passe :</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Changer le mot de passe</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
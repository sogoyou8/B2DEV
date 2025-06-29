<?php
include 'includes/header.php';
include 'includes/db.php';

$token = $_GET['token'];
$query = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expiry > NOW()");
$query->execute([$token]);
$user = $query->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die("Token invalide ou expiré.");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Les mots de passe ne correspondent pas.";
    } else {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $query = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE reset_token = ?");
        $query->execute([$hashed_password, $token]);

        $success = "Mot de passe réinitialisé avec succès.";
    }
}
?>
<main class="container py-4">
    <section class="reset-password-section bg-light p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
        <h2 class="h3 mb-4 font-weight-bold">Réinitialiser le mot de passe</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="post">
            <div class="form-group">
                <label for="password">Nouveau mot de passe :</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmer le mot de passe :</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Réinitialiser</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
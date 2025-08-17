<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $query = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $query->execute([$email]);
    $user = $query->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_id'] = $user['id'];
        $_SESSION['admin_name'] = $user['name'];
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['is_demo'] = $user['is_demo'];
        header("Location: dashboard.php");
        exit;
    } else {
    $error = "Email ou mot de passe incorrect.";

    // Ajout d'une notification persistante en base
    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
    $stmt->execute([
        'security',
        "Tentative de connexion admin échouée pour l'email : " . htmlspecialchars($email)
    ]);
}
}
include 'includes/header.php';
?>
<main>
    <section class="mt-5">
        <h2>Connexion Admin</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <form action="admin_login.php" method="post">
            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary">Se connecter</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
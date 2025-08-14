<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
include 'includes/header.php';
include 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    $query = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $query->execute([$email]);
    $user = $query->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['logged_in'] = true;

        // Charger les données de panier et de favoris
        $query = $pdo->prepare("SELECT item_id, quantity FROM cart WHERE user_id = ?");
        $query->execute([$user['id']]);
        $_SESSION['cart'] = $query->fetchAll(PDO::FETCH_KEY_PAIR);

        $query = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
        $query->execute([$user['id']]);
        $_SESSION['favorites'] = $query->fetchAll(PDO::FETCH_COLUMN);

        // Ajouter les articles temporaires aux favoris
        if (isset($_SESSION['temp_favorites'])) {
            foreach ($_SESSION['temp_favorites'] as $item_id) {
                if (!in_array($item_id, $_SESSION['favorites'])) {
                    $query = $pdo->prepare("INSERT INTO favorites (user_id, item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE item_id = item_id");
                    $query->execute([$user['id'], $item_id]);
                }
            }
            unset($_SESSION['temp_favorites']);
        }

        header("Location: index.php");
        exit;
    } else {
        $error = "Email ou mot de passe incorrect.";

        // Notification persistante (sécurité)
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'security',
            "Tentative de connexion échouée pour l'email : " . htmlspecialchars($email)
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
        <section class="login-section bg-white p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
            <h2 class="h3 mb-4 font-weight-bold">Connexion</h2>
            <?php if (isset($_GET['logout']) && $_GET['logout'] == 'success'): ?>
                <div class="alert alert-success mb-4">Vous avez été déconnecté avec succès.</div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
                <div class="alert alert-danger mb-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <form action="login.php" method="post">
                <div class="form-group">
                    <label for="email">Email :</label>
                    <input type="email" name="email" id="email" required class="form-control">
                </div>
                <div class="form-group">
                    <label for="password">Mot de passe :</label>
                    <input type="password" name="password" id="password" required class="form-control">
                </div>
                <button type="submit" class="btn btn-primary btn-block mt-3">Connexion</button>
            </form>
            <p class="mt-3 text-center">Mot de passe oublié ? <a href="forgot_password.php" class="text-primary">Réinitialiser</a></p>
        </section>
    </main>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
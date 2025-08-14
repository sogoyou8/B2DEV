<?php
include 'includes/header.php';
include 'includes/db.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($name)) {
        $errors[] = "Le nom est requis.";
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
    }

    if (strlen($password) < 6) {
        $errors[] = "Le mot de passe doit contenir au moins 6 caractères.";
    }

    if ($password !== $confirm_password) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }

    $query = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $query->execute([$email]);
    if ($query->fetch()) {
        $errors[] = "Cet email est déjà utilisé.";
    }

    if (empty($errors)) {
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);

        $query = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
        if ($query->execute([$name, $email, $hashed_password])) {
            session_start();
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['user_name'] = $name;
            $_SESSION['logged_in'] = true;

            header("Location: index.php");
            exit;
        } else {
            $errors[] = "Erreur technique lors de la création du compte. Veuillez réessayer plus tard.";

            // Notification persistante (technique)
            $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
            $stmt->execute([
                'error',
                "Erreur SQL lors de la création d'un compte utilisateur (email : " . htmlspecialchars($email) . ")"
            ]);
        }
    }
}
?>
<main class="container py-4">
    <section class="register-section bg-light p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
        <h2 class="h3 mb-4 font-weight-bold">Inscription</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <form action="register.php" method="post">
            <div class="form-group">
                <label for="name">Nom :</label>
                <input type="text" name="name" id="name" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmer le mot de passe :</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">S'inscrire</button>
        </form>
        <p class="mt-3 text-center">Déjà inscrit ? <a href="login.php" class="text-primary">Se connecter</a></p>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
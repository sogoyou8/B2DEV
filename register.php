<?php
include 'includes/header.php';
include 'includes/db.php';

$errors = [];
$success = '';

// Protection mode démo
if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) {
    $errors[] = "La création de compte est désactivée en mode démo.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($errors)) {
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

            $success = "Inscription réussie !";
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
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
    <section class="register-section bg-white p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
        <h2 class="h3 mb-4 font-weight-bold text-center">Inscription</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <form action="register.php" method="post">
            <div class="form-group">
                <label for="name">Nom :</label>
                <input type="text" name="name" id="name" class="form-control" required <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
            </div>
            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" required <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
            </div>
            <div class="form-group">
                <label for="password">Mot de passe :</label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control" required <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
                            <span id="togglePasswordIcon" class="bi bi-eye"></span>
                        </button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirmer le mot de passe :</label>
                <div class="input-group">
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" id="toggleConfirmPassword" tabindex="-1">
                            <span id="toggleConfirmPasswordIcon" class="bi bi-eye"></span>
                        </button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3" <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>S'inscrire</button>
        </form>
        <p class="mt-3 text-center">Déjà inscrit ? <a href="login.php" class="text-primary">Se connecter</a></p>
    </section>
</main>
<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.js"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const passwordInput = document.getElementById('password');
    const icon = document.getElementById('togglePasswordIcon');
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        passwordInput.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
});
document.getElementById('toggleConfirmPassword').addEventListener('click', function () {
    const confirmInput = document.getElementById('confirm_password');
    const icon = document.getElementById('toggleConfirmPasswordIcon');
    if (confirmInput.type === 'password') {
        confirmInput.type = 'text';
        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');
    } else {
        confirmInput.type = 'password';
        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
});
</script>
<?php include 'includes/footer.php'; ?>
</body>
</html>
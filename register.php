<?php
// Bloquer l'accès si l'utilisateur (ou admin) est déjà connecté
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    header('Location: profile.php');
    exit;
}
if (!empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    header('Location: admin/dashboard.php');
    exit;
}

// Inclure la connexion DB avant le traitement pour pouvoir interagir avec la BDD
include 'includes/db.php';

$errors = [];
$success = '';

// Protection mode démo
if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) {
    $errors[] = "La création de compte est désactivée en mode démo.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($errors)) {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm_password = (string)($_POST['confirm_password'] ?? '');

    // Validation nom/email
    if ($name === '') {
        $errors[] = "Le nom est requis.";
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "L'email n'est pas valide.";
    }

    // Politique de mot de passe : au moins 8 caractères, une majuscule, une minuscule, un caractère spécial
    if ($password === '') {
        $errors[] = "Le mot de passe est requis.";
    } else {
        if (strlen($password) < 8) {
            $errors[] = "Le mot de passe doit contenir au moins 8 caractères.";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une majuscule.";
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins une minuscule.";
        }
        if (!preg_match('/[\W_]/', $password)) {
            $errors[] = "Le mot de passe doit contenir au moins un caractère spécial.";
        }
    }

    if ($confirm_password === '') {
        $errors[] = "La confirmation du mot de passe est requise.";
    }

    // Vérifier la correspondance uniquement si les deux champs sont renseignés
    if ($password !== '' && $confirm_password !== '' && $password !== $confirm_password) {
        $errors[] = "Les mots de passe ne correspondent pas.";
    }

    // Vérifier si l'email existe déjà (seulement si pas d'erreurs précédentes)
    if (empty($errors)) {
        try {
            $query = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $query->execute([$email]);
            if ($query->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = "Cet email est déjà utilisé.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur base de données lors de la vérification de l'email : " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            $query = $pdo->prepare("INSERT INTO users (name, email, password) VALUES (?, ?, ?)");
            if ($query->execute([$name, $email, $hashed_password])) {
                // session déjà démarrée en haut — ne pas redémarrer
                $newUserId = (int)$pdo->lastInsertId();
                $_SESSION['user_id'] = $newUserId;
                $_SESSION['user_name'] = $name;
                $_SESSION['logged_in'] = true;

                // Charger favoris persistants (si existants) et initialiser session
                try {
                    $favStmt = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
                    $favStmt->execute([$newUserId]);
                    $_SESSION['favorites'] = $favStmt->fetchAll(PDO::FETCH_COLUMN);
                    if (!is_array($_SESSION['favorites'])) $_SESSION['favorites'] = [];
                } catch (Exception $e) {
                    $_SESSION['favorites'] = [];
                }
                $_SESSION['favorites_count'] = count($_SESSION['favorites']);

                // Charger compteur panier à partir de la BDD
                try {
                    $cartStmt = $pdo->prepare("SELECT SUM(quantity) AS qty FROM cart WHERE user_id = ?");
                    $cartStmt->execute([$newUserId]);
                    $cartQty = (int)($cartStmt->fetchColumn() ?: 0);
                    $_SESSION['cart_count'] = $cartQty;
                } catch (Exception $e) {
                    $_SESSION['cart_count'] = 0;
                }

                // Ajouter les éléments temporaires aux favoris persistants (si présents)
                if (!empty($_SESSION['temp_favorites']) && is_array($_SESSION['temp_favorites'])) {
                    foreach ($_SESSION['temp_favorites'] as $item_id) {
                        $item_id = (int)$item_id;
                        if ($item_id <= 0) continue;
                        if (!in_array($item_id, $_SESSION['favorites'])) {
                            try {
                                // ON DUPLICATE KEY nécessite contrainte unique ; si absente l'insert peut dupliquer
                                $ins = $pdo->prepare("INSERT INTO favorites (user_id, item_id) VALUES (?, ?)");
                                $ins->execute([$newUserId, $item_id]);
                                $_SESSION['favorites'][] = $item_id;
                            } catch (Exception $e) {
                                // tenter une protection avec UPDATE en cas de duplicate key ou ignorer l'erreur
                                try {
                                    $ins2 = $pdo->prepare("INSERT INTO favorites (user_id, item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE item_id = item_id");
                                    $ins2->execute([$newUserId, $item_id]);
                                    if (!in_array($item_id, $_SESSION['favorites'])) {
                                        $_SESSION['favorites'][] = $item_id;
                                    }
                                } catch (Exception $e2) {
                                    // ignore individual insert failure
                                }
                            }
                        }
                    }
                    unset($_SESSION['temp_favorites']);
                    $_SESSION['favorites_count'] = count($_SESSION['favorites']);
                }

                // --- Fusionner le panier temporaire de session dans la table cart ---
                if (!empty($_SESSION['temp_cart']) && is_array($_SESSION['temp_cart'])) {
                    foreach ($_SESSION['temp_cart'] as $item_id => $qty) {
                        $item_id = (int)$item_id;
                        $qty = max(0, (int)$qty);
                        if ($item_id <= 0 || $qty <= 0) continue;

                        try {
                            // Vérifier s'il existe déjà une ligne cart pour cet utilisateur/item
                            $checkStmt = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND item_id = ? LIMIT 1");
                            $checkStmt->execute([$newUserId, $item_id]);
                            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

                            if ($existing) {
                                // additionner les quantités
                                $upd = $pdo->prepare("UPDATE cart SET quantity = quantity + ? WHERE id = ?");
                                $upd->execute([$qty, $existing['id']]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?)");
                                $ins->execute([$newUserId, $item_id, $qty]);
                            }
                        } catch (Exception $e) {
                            // ignorer l'échec d'une ligne individuelle pour ne pas bloquer l'inscription
                        }
                    }
                    // retirer le panier temporaire de session et recalculer le compteur
                    unset($_SESSION['temp_cart']);
                    try {
                        $cartStmt = $pdo->prepare("SELECT SUM(quantity) AS qty FROM cart WHERE user_id = ?");
                        $cartStmt->execute([$newUserId]);
                        $_SESSION['cart_count'] = (int)($cartStmt->fetchColumn() ?: 0);
                    } catch (Exception $e) {
                        $_SESSION['cart_count'] = 0;
                    }
                }
                // --- fin fusion panier ---

                $success = "Inscription réussie !";
                header("Location: index.php");
                exit;
            } else {
                $errors[] = "Erreur technique lors de la création du compte. Veuillez réessayer plus tard.";

                // Notification persistante (technique)
                try {
                    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
                    $stmt->execute([
                        'error',
                        "Erreur SQL lors de la création d'un compte utilisateur (email : " . htmlspecialchars($email, ENT_QUOTES) . ")"
                    ]);
                } catch (Exception $e) {
                    // ignore logging errors
                }
            }
        } catch (Exception $e) {
            $errors[] = "Erreur technique lors de la création du compte : " . $e->getMessage();
        }
    }
}

/*
 * Inclure le header maintenant que tout traitement POST/redirect possible est fait.
 */
include 'includes/header.php';

// charger le fichier CSS séparé au lieu du style inline
echo '<link rel="stylesheet" href="assets/css/user/register.css">';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscription</title>
</head>
<body>
<main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
    <section class="register-section bg-white p-5 rounded shadow-sm mx-auto">
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
                <input type="text" name="name" id="name" class="form-control" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
            </div>
            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" <?php if (!empty($_SESSION['is_demo']) && $_SESSION['is_demo'] == 1) echo 'disabled'; ?>>
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
                <small class="form-text text-muted">Au moins 8 caractères, une majuscule, une minuscule et un caractère spécial.</small>
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
document.getElementById('togglePassword') && document.getElementById('togglePassword').addEventListener('click', function () {
    const passwordInput = document.getElementById('password');
    const icon = document.getElementById('togglePasswordIcon');
    if (!passwordInput) return;
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
document.getElementById('toggleConfirmPassword') && document.getElementById('toggleConfirmPassword').addEventListener('click', function () {
    const confirmInput = document.getElementById('confirm_password');
    const icon = document.getElementById('toggleConfirmPasswordIcon');
    if (!confirmInput) return;
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
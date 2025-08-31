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

include 'includes/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = "Veuillez renseigner l'email et le mot de passe.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                // Auth OK — initialiser la session
                // Regarder le rôle
                if (isset($user['role']) && $user['role'] === 'admin') {
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_name'] = $user['name'] ?? '';
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'] ?? '';
                    $_SESSION['user_email'] = $user['email'] ?? '';
                } else {
                    $_SESSION['logged_in'] = true;
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'] ?? '';
                    $_SESSION['user_email'] = $user['email'] ?? '';
                }

                // Charger compte favoris
                $favStmt = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
                $favStmt->execute([$user['id']]);
                $_SESSION['favorites'] = $favStmt->fetchAll(PDO::FETCH_COLUMN);
                $_SESSION['favorites_count'] = count($_SESSION['favorites']);

                // Charger compteur panier
                $cartStmt = $pdo->prepare("SELECT SUM(quantity) AS qty FROM cart WHERE user_id = ?");
                $cartStmt->execute([$user['id']]);
                $cartQty = (int)($cartStmt->fetchColumn() ?: 0);
                $_SESSION['cart_count'] = $cartQty;

                // Ajouter les éléments temporaires aux favoris persistants (si présents)
                if (!empty($_SESSION['temp_favorites']) && is_array($_SESSION['temp_favorites'])) {
                    foreach ($_SESSION['temp_favorites'] as $item_id) {
                        $item_id = (int)$item_id;
                        if ($item_id <= 0) continue;
                        if (!in_array($item_id, $_SESSION['favorites'])) {
                            try {
                                $ins = $pdo->prepare("INSERT INTO favorites (user_id, item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE item_id = item_id");
                                $ins->execute([$user['id'], $item_id]);
                                $_SESSION['favorites'][] = $item_id;
                            } catch (Exception $e) {
                                // ignore individual insert failure
                            }
                        }
                    }
                    unset($_SESSION['temp_favorites']);
                    $_SESSION['favorites_count'] = count($_SESSION['favorites']);
                }

                // --- Fusionner le panier temporaire de session dans la table cart (si présent) ---
                if (!empty($_SESSION['temp_cart']) && is_array($_SESSION['temp_cart'])) {
                    foreach ($_SESSION['temp_cart'] as $item_id => $qty) {
                        $item_id = (int)$item_id;
                        $qty = max(0, (int)$qty);
                        if ($item_id <= 0 || $qty <= 0) continue;

                        try {
                            // vérifier si ligne existante
                            $check = $pdo->prepare("SELECT id, quantity FROM cart WHERE user_id = ? AND item_id = ? LIMIT 1");
                            $check->execute([$user['id'], $item_id]);
                            $existing = $check->fetch(PDO::FETCH_ASSOC);

                            if ($existing) {
                                $upd = $pdo->prepare("UPDATE cart SET quantity = quantity + ? WHERE id = ?");
                                $upd->execute([$qty, $existing['id']]);
                            } else {
                                $ins = $pdo->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?)");
                                $ins->execute([$user['id'], $item_id, $qty]);
                            }
                        } catch (Exception $e) {
                            // ignore per-line failure
                        }
                    }
                    // unset temp and recalc counter
                    unset($_SESSION['temp_cart']);
                    try {
                        $cartStmt = $pdo->prepare("SELECT SUM(quantity) AS qty FROM cart WHERE user_id = ?");
                        $cartStmt->execute([$user['id']]);
                        $_SESSION['cart_count'] = (int)($cartStmt->fetchColumn() ?: 0);
                    } catch (Exception $e) {
                        $_SESSION['cart_count'] = 0;
                    }
                }
                // --- fin fusion panier ---

                // Redirection après login
                header("Location: index.php");
                exit;
            } else {
                $error = "Email ou mot de passe incorrect.";

                // Journaliser tentative échouée
                try {
                    $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
                    $stmt->execute([
                        'security',
                        "Tentative de connexion échouée pour l'email : " . htmlspecialchars($email, ENT_QUOTES)
                    ]);
                } catch (Exception $e) {
                    // ignore logging errors
                }
            }
        } catch (Exception $e) {
            $error = "Erreur technique lors de la connexion : " . $e->getMessage();
        }
    }
}

// Inclure header + CSS de la page
include 'includes/header.php';
echo '<link rel="stylesheet" href="assets/css/user/login.css">' ;
?>
<main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
    <section class="login-section bg-white p-5 rounded shadow-sm mx-auto" style="max-width:420px;">
        <h2 class="h3 mb-4 font-weight-bold text-center">Connexion</h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form action="login.php" method="post" autocomplete="off">
            <div class="form-group mb-3">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autofocus>
            </div>

            <div class="form-group mb-3">
                <label for="password">Mot de passe :</label>
                <div class="input-group">
                    <input type="password" name="password" id="password" class="form-control" required>
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
                            <span id="togglePasswordIcon" class="bi bi-eye"></span>
                        </button>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Se connecter</button>
        </form>

        <p class="mt-3 text-center small-muted">Pas encore inscrit ? <a href="register.php">Créer un compte</a> — <a href="forgot_password.php">Mot de passe oublié</a></p>
    </section>
</main>

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
</script>

<?php include 'includes/footer.php'; ?>
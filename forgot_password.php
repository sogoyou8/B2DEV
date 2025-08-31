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

include 'includes/header.php';
echo '<link rel="stylesheet" href="assets/css/user/forgot_password.css">' ;
include 'includes/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Veuillez fournir une adresse email valide.";
    } else {
        try {
            // Vérifier que la table users contient les colonnes nécessaires pour la réinitialisation
            $colStmt = $pdo->prepare("
                SELECT COUNT(*) as cnt
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'users'
                  AND COLUMN_NAME IN ('reset_token','reset_token_expiry')
            ");
            $colStmt->execute();
            $count = (int)($colStmt->fetchColumn() ?: 0);
            $dbColumnsExist = ($count >= 2);

            if (!$dbColumnsExist) {
                $message = "La fonctionnalité de réinitialisation n'est pas disponible : la table `users` ne contient pas les colonnes nécessaires (reset_token, reset_token_expiry).";
                $message .= " Demandez à l'administrateur d'exécuter la requête suivante :\n\nALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(255) NULL, ADD COLUMN `reset_token_expiry` DATETIME NULL;";
            } else {
                // Rechercher l'utilisateur par email
                $query = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $query->execute([$email]);
                $user = $query->fetch(PDO::FETCH_ASSOC);

                if ($user) {
                    // Protection mode démo si champ is_demo présent et vrai
                    if (!empty($user['is_demo']) && $user['is_demo'] == 1) {
                        $message = "La réinitialisation du mot de passe est désactivée pour ce compte démo.";
                    } else {
                        // Générer token de réinitialisation et sauvegarder en base
                        $token = bin2hex(random_bytes(50));
                        $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?");
                        $update->execute([$token, $email]);

                        // Construire un lien de réinitialisation basé sur l'hôte courant
                        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                        $scriptDir = ($scriptDir === '/' || $scriptDir === '\\') ? '' : $scriptDir;
                        $reset_link = $scheme . '://' . $host . $scriptDir . '/reset_password.php?token=' . urlencode($token);

                        // Envoi d'email (simplifié)
                        $subject = "Réinitialisation de mot de passe";
                        $body = "Bonjour,\n\nPour réinitialiser votre mot de passe, cliquez sur le lien suivant (valide 1 heure) :\n\n" . $reset_link . "\n\nSi vous n'avez pas demandé cette réinitialisation, ignorez ce message.";
                        // Utiliser mail() si configuré ; stocker message utilisateur quel que soit l'envoi
                        @mail($email, $subject, $body);

                        $message = "Si un compte existe pour cette adresse, un email de réinitialisation a été envoyé (vérifiez la boîte de réception / spam).";
                    }
                } else {
                    // Réponse muette pour éviter la divulgation d'existence de compte
                    $message = "Si un compte existe pour cette adresse, un email de réinitialisation a été envoyé (vérifiez la boîte de réception / spam).";
                }
            }
        } catch (Exception $e) {
            $message = "Erreur technique lors de la demande de réinitialisation : " . $e->getMessage();
        }
    }
}
?>
<main class="p-4">
    <section class="forgot-password-section bg-gray-100 p-6 rounded-lg shadow-md mx-auto" style="max-width:540px;">
        <h2 class="h3 mb-4 font-weight-bold">Réinitialisation du mot de passe</h2>

        <?php if ($message): ?>
            <div class="alert alert-info mb-4" style="white-space:pre-wrap;"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form action="forgot_password.php" method="post" class="mb-0">
            <div class="form-group mb-3">
                <label for="email">Email :</label>
                <input type="email" id="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Demander la réinitialisation</button>
        </form>

        <p class="mt-3 small text-muted">Si vous avez déjà un compte, connectez-vous plutôt. <a href="login.php">Se connecter</a></p>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
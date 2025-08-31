<?php
// Bloquer l'accès à cette page si l'utilisateur est déjà connecté (utilisateur ou admin)
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
include 'includes/db.php';
include 'admin/admin_demo_guard.php';

// Charger la feuille de styles
echo '<link rel="stylesheet" href="assets/css/user/reset_password.css">' ;

// Récupérer et normaliser le token (peut être absent)
$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';

// Vérifier si la colonne reset_token et reset_token_expiry existent dans la table users
$dbColumnsExist = false;
$dbColumnsMessage = '';
try {
    $colStmt = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND COLUMN_NAME IN ('reset_token','reset_token_expiry')
    ");
    $colStmt->execute();
    $count = (int)($colStmt->fetchColumn() ?: 0);
    // On s'attend à 2 colonnes ; si <2 on considère qu'il manque au moins une colonne nécessaire
    $dbColumnsExist = ($count >= 2);
    if (!$dbColumnsExist) {
        $dbColumnsMessage = "La table `users` ne contient pas les colonnes nécessaires pour la réinitialisation (reset_token, reset_token_expiry).";
        $dbColumnsMessage .= " Exécutez la requête SQL suivante pour ajouter les colonnes :";
        $dbColumnsMessage .= "\n\nALTER TABLE `users` ADD COLUMN `reset_token` VARCHAR(255) NULL, ADD COLUMN `reset_token_expiry` DATETIME NULL;";
    }
} catch (Exception $e) {
    // Si la requête sur information_schema échoue, marquer comme absent et afficher le message d'erreur
    $dbColumnsExist = false;
    $dbColumnsMessage = "Impossible de vérifier la structure de la base : " . $e->getMessage();
}

/*
 * Si le token est fourni et que la base possède les colonnes, on tente de récupérer l'utilisateur.
 * Sinon on affichera un message adapté dans la partie HTML plus bas.
 */
$user = false;
if ($token !== '' && $dbColumnsExist) {
    try {
        $query = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1");
        $query->execute([$token]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Gérer proprement les erreurs PDO (par ex. colonne manquante malgré la vérification)
        $user = false;
        $dbColumnsExist = false;
        $dbColumnsMessage = "Erreur lors de l'accès aux données de réinitialisation : " . $e->getMessage();
    }
}

/*
 * Traitement POST : soumission du nouveau mot de passe.
 * Le formulaire POST est déclenché sur la même URL contenant le token en GET.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérifier que le token est présent
    $postedToken = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
    if ($postedToken === '') {
        $error = "Token manquant. Veuillez utiliser le lien envoyé par email ou redemander une réinitialisation.";
    } elseif (!$dbColumnsExist) {
        $error = "La base de données n'est pas prête pour la réinitialisation des mots de passe. " . PHP_EOL . $dbColumnsMessage;
    } else {
        // Demo guard : bloquer si nécessaire
        if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
            $error = "Action désactivée en mode démo.";
        } else {
            $password = trim($_POST['password'] ?? '');
            $confirm_password = trim($_POST['confirm_password'] ?? '');

            if ($password === '' || $confirm_password === '') {
                $error = "Veuillez renseigner et confirmer le nouveau mot de passe.";
            } elseif ($password !== $confirm_password) {
                $error = "Les mots de passe ne correspondent pas.";
            } elseif (strlen($password) < 8) {
                $error = "Le mot de passe doit contenir au moins 8 caractères.";
            } else {
                try {
                    // S'assurer que le token correspond toujours à un utilisateur valide
                    $q = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_token_expiry > NOW() LIMIT 1");
                    $q->execute([$postedToken]);
                    $u = $q->fetch(PDO::FETCH_ASSOC);
                    if (!$u) {
                        $error = "Token invalide ou expiré.";
                    } else {
                        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                        $update = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?");
                        $update->execute([$hashed_password, $u['id']]);
                        $success = "Mot de passe réinitialisé avec succès. Vous pouvez maintenant vous connecter.";
                    }
                } catch (Exception $e) {
                    $error = "Erreur lors de la mise à jour du mot de passe : " . $e->getMessage();
                }
            }
        }
    }
}
?>
<main class="container py-4">
    <section class="reset-password-section bg-light p-5 rounded shadow-sm mx-auto">
        <h2 class="h3 mb-4 font-weight-bold">Réinitialiser le mot de passe</h2>

        <?php if (!empty($dbColumnsMessage) && !$dbColumnsExist): ?>
            <div class="alert alert-danger" style="white-space:pre-wrap;"><?php echo htmlspecialchars($dbColumnsMessage); ?></div>
            <p>Si vous ne souhaitez pas modifier la structure de la base de données vous pouvez contacter l'administrateur.</p>
            <p><a href="forgot_password.php" class="btn btn-outline-primary btn-sm">Demander un nouvel email de réinitialisation</a></p>
        <?php else: ?>
            <?php if ($token === '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
                <div class="alert alert-warning">Token manquant. Utilisez le lien de réinitialisation envoyé par email ou <a href="forgot_password.php">demandez un nouvel email</a>.</div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
                <div class="mt-3">
                    <a href="login.php" class="btn btn-primary">Se connecter</a>
                </div>
            <?php else: ?>
                <?php
                // Si token fourni mais aucun utilisateur trouvé => message d'erreur simple
                if ($token !== '' && !$user && !isset($error)): ?>
                    <div class="alert alert-danger">Token invalide ou expiré. <a href="forgot_password.php">Demander un nouvel email</a>.</div>
                <?php endif; ?>

                <?php if ($token !== '' && $user): ?>
                    <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="post" autocomplete="off">
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
                <?php else: ?>
                    <!-- Pas de formulaire si pas de token valide -->
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<?php include 'includes/footer.php';
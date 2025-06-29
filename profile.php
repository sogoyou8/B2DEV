<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/header.php';
include 'includes/db.php';

$user_id = $_SESSION['user_id'];

// Récupérez les informations de l'utilisateur à partir de la base de données
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$user_id]);
$user = $query->fetch(PDO::FETCH_ASSOC);

// Vérifiez si la clé 'user_name' est définie dans la session
$user_name = isset($user['name']) ? $user['name'] : 'Utilisateur';

?>
<main class="container py-4">
    <section class="profile-section bg-light p-5 rounded shadow-sm">
        <h2 class="display-4 mb-4">Profil de <?php echo htmlspecialchars($user_name); ?></h2>
        <?php if (isset($_SESSION['profile_update_success'])): ?>
            <div class="alert alert-success mb-4">
                <?php echo htmlspecialchars($_SESSION['profile_update_success']); ?>
                <?php unset($_SESSION['profile_update_success']); ?>
            </div>
        <?php endif; ?>
        <p class="mb-2"><strong>Email :</strong> <?php echo htmlspecialchars($user['email']); ?></p>
        <p class="mb-4"><strong>Date de création :</strong> <?php echo htmlspecialchars($user['created_at']); ?></p>
        
        <!-- Formulaire de mise à jour du profil -->
        <h3 class="h4 mb-4">Mettre à jour le profil</h3>
        <form action="update_profile.php" method="post" class="mb-4">
            <div class="mb-3">
                <label for="name" class="form-label">Nom :</label>
                <input type="text" name="name" id="name" value="<?php echo htmlspecialchars($user['name']); ?>" required class="form-control">
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email :</label>
                <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" required class="form-control">
            </div>
            <button type="submit" class="btn btn-primary">Mettre à jour</button>
        </form>

        <!-- Formulaire de changement de mot de passe -->
        <h3 class="h4 mb-4">Changer le mot de passe</h3>
        <form action="update_password.php" method="post" class="mb-4">
            <div class="mb-3">
                <label for="current_password" class="form-label">Mot de passe actuel :</label>
                <input type="password" name="current_password" id="current_password" required class="form-control">
            </div>
            <div class="mb-3">
                <label for="new_password" class="form-label">Nouveau mot de passe :</label>
                <input type="password" name="new_password" id="new_password" required class="form-control">
            </div>
            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirmer le nouveau mot de passe :</label>
                <input type="password" name="confirm_password" id="confirm_password" required class="form-control">
            </div>
            <button type="submit" class="btn btn-primary">Changer le mot de passe</button>
        </form>

        <!-- Formulaire de suppression de compte -->
        <h3 class="h4 mb-4">Supprimer le compte</h3>
        <form action="delete_account.php" method="post" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.');">
            <button type="submit" class="btn btn-danger">Supprimer mon compte</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
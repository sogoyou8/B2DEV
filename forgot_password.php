<?php
include 'includes/header.php';
include 'includes/db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];

    $query = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $query->execute([$email]);
    $user = $query->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // Générer un token de réinitialisation
        $token = bin2hex(random_bytes(50));
        $query = $pdo->prepare("UPDATE users SET reset_token = ?, reset_token_expiry = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE email = ?");
        $query->execute([$token, $email]);

        // Envoyer un email avec le lien de réinitialisation (simplifié ici)
        $reset_link = "http://localhost/E-commerce%20dynamique/reset_password.php?token=$token";
        mail($email, "Réinitialisation de mot de passe", "Cliquez sur ce lien pour réinitialiser votre mot de passe : $reset_link");

        $message = "Un email de réinitialisation a été envoyé.";
    } else {
        $message = "Aucun compte trouvé avec cet email.";
    }
}
?>
<main class="p-4">
    <section class="forgot-password-section bg-gray-100 p-6 rounded-lg shadow-md">
        <h2 class="text-3xl font-bold mb-4">Réinitialisation du mot de passe</h2>
        <?php if ($message): ?>
            <div class="alert alert-info mb-4">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        <form action="forgot_password.php" method="post" class="space-y-4">
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email :</label>
                <input type="email" name="email" id="email" required class="mt-1 block w-full p-2 border border-gray-300 rounded-md">
            </div>
            <button type="submit" class="btn btn-primary bg-blue-500 text-white py-2 px-4 rounded-md">Envoyer</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
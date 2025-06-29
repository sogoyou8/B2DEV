<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/db.php';
include 'includes/header.php';

$user_id = $_SESSION['user_id'];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);

    // Validation des données
    if (empty($name)) {
        $errors[] = "Le nom est requis.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Un email valide est requis.";
    }

    if (empty($errors)) {
        // Mettez à jour les informations de l'utilisateur dans la base de données
        $query = $pdo->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
        $query->execute([$name, $email, $user_id]);

        $success = "Votre profil a été mis à jour avec succès.";
    }
}

// Récupérez les informations actuelles de l'utilisateur
$query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$query->execute([$user_id]);
$user = $query->fetch(PDO::FETCH_ASSOC);
?>
<main class="container py-4">
    <section class="update-profile-section bg-light p-5 rounded shadow-sm mx-auto" style="max-width: 500px;">
        <h2 class="h3 mb-4 font-weight-bold">Mettre à jour le profil</h2>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <p><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <form action="update_profile.php" method="post">
            <div class="form-group">
                <label for="name">Nom :</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo htmlspecialchars($user['name']); ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email :</label>
                <input type="email" name="email" id="email" class="form-control" value="<?php echo htmlspecialchars($user['email']); ?>" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-3">Mettre à jour</button>
        </form>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
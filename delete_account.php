<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}
include 'includes/db.php';

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Supprimer l'utilisateur de la base de données
    $query = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $query->execute([$user_id]);

    // Détruire la session
    session_unset();
    session_destroy();

    // Rediriger vers la page d'accueil avec un message de succès
    header("Location: index.php?message=account_deleted");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supprimer le compte</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
        <section class="delete-account-section bg-white p-5 rounded shadow-sm">
            <h2 class="h3 mb-4 font-weight-bold">Supprimer le compte</h2>
            <p class="mb-4">Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.</p>
            <form action="delete_account.php" method="post">
                <button type="submit" class="btn btn-danger btn-block mb-2">Supprimer mon compte</button>
                <a href="profile.php" class="btn btn-secondary btn-block">Annuler</a>
            </form>
        </section>
    </main>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
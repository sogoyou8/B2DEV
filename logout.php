<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (isset($_POST['confirm_logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php?logout=success");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Déconnexion</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <main class="d-flex justify-content-center align-items-center min-vh-100 bg-light">
        <section class="logout-section bg-white p-5 rounded shadow-sm">
            <h2 class="h3 mb-4 font-weight-bold">Déconnexion</h2>
            <p class="mb-4">Êtes-vous sûr de vouloir vous déconnecter ?</p>
            <form action="logout.php" method="post">
                <button type="submit" name="confirm_logout" class="btn btn-primary btn-block mb-2">Oui, me déconnecter</button>
                <a href="index.php" class="btn btn-secondary btn-block">Annuler</a>
            </form>
        </section>
    </main>
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
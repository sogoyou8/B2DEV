<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';

$query = $pdo->query("SELECT * FROM users ORDER BY id DESC");
$users = $query->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger text-center">
        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success text-center">
        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
<?php endif; ?>

<main class="container py-4">
    <section class="mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="mb-0">Liste des utilisateurs</h2>
            <div>
                <a href="add_user.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-plus me-1"></i>Ajouter un utilisateur
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-info">
                <?php echo $_SESSION['message']; unset($_SESSION['message']); ?>
            </div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-striped align-middle">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>Nom</th>
                        <th>Email</th>
                        <th>Rôle</th>
                        <th style="width:220px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['role']); ?></td>
                            <td>
                                <a href="edit_user.php?id=<?php echo (int)$user['id']; ?>" class="btn btn-warning btn-sm">
                                    <i class="bi bi-pencil-square"></i> Modifier
                                </a>

                                <a href="delete_user.php?id=<?php echo (int)$user['id']; ?>"
                                   class="btn btn-danger btn-sm"
                                   onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?');">
                                    <i class="bi bi-trash"></i> Supprimer
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted">Aucun utilisateur trouvé.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php include 'includes/footer.php';
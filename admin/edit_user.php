<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'admin_demo_guard.php';
include_once 'includes/header.php';

// Validate ID parameter
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID utilisateur invalide.";
    header("Location: list_users.php");
    exit;
}

$id = (int)$_GET['id'];

// Fetch user
try {
    $query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $query->execute([$id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);

    if (!$user || !is_array($user)) {
        // persistent notification for audit
        try {
            $stmt = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 1)");
            $stmt->execute([
                'error',
                "Échec modification utilisateur : ID $id introuvable (admin: " . ($_SESSION['admin_name'] ?? 'Unknown') . ")"
            ]);
        } catch (Exception $e) {
            // ignore logging failure
        }
        $_SESSION['error'] = "Utilisateur introuvable.";
        header("Location: list_users.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_users.php");
    exit;
}

// Handle POST (update user)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: edit_user.php?id=$id");
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';

    $errors = [];

    if ($name === '' || mb_strlen($name) < 2) {
        $errors[] = "Le nom est requis (min 2 caractères).";
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email valide requis.";
    }

    if (!in_array($role, ['user', 'admin'], true)) {
        $errors[] = "Rôle invalide.";
    }

    // Check email uniqueness
    if (empty($errors)) {
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check->execute([$email, $id]);
            if ($check->fetch()) {
                $errors[] = "Cet email est déjà utilisé par un autre utilisateur.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur lors de la vérification : " . $e->getMessage();
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $ok = $stmt->execute([$name, $email, $role, $id]);

            if ($ok) {
                // audit log (non-persistent)
                try {
                    $stmtN = $pdo->prepare("INSERT INTO notifications (`type`,`message`,`is_persistent`) VALUES (?, ?, 0)");
                    $stmtN->execute([
                        'admin_action',
                        "Utilisateur '" . addslashes($name) . "' modifié avec succès par " . ($_SESSION['admin_name'] ?? 'Admin')
                    ]);
                } catch (Exception $e) {
                    // ignore notification error
                }

                $_SESSION['success'] = "Utilisateur modifié avec succès.";
                header("Location: list_users.php");
                exit;
            } else {
                $errors[] = "Erreur lors de la mise à jour.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        // Refresh $user to repopulate form with current DB values (do not redirect away)
        try {
            $q = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $q->execute([$id]);
            $user = $q->fetch(PDO::FETCH_ASSOC) ?: $user;
        } catch (Exception $e) {
            // ignore
        }
    }
}

?>
<script>try { document.body.classList.add('admin-page'); } catch(e){}</script>

<link rel="stylesheet" href="../assets/css/admin/edit_user.css">

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Modifier l'utilisateur</h2>
                    <div class="small text-muted ms-2">Édition & actions rapides — journalisé pour audit</div>
                </div>
            </div>
            <div class="controls">
                <a href="list_users.php" class="btn btn-outline-secondary btn-sm btn-round">← Retour à la liste</a>
                <a href="add_user.php" class="btn btn-primary btn-sm btn-round">Ajouter un utilisateur</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($_SESSION['errors'] as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="card form-card shadow-sm p-3">
                    <div class="card-body">
                        <h5 class="mb-3">Informations</h5>
                        <p class="small text-muted">Modification de : <strong><?php echo htmlspecialchars($user['name'] ?? 'Utilisateur inconnu'); ?></strong> — ID: <?php echo (int)$user['id']; ?></p>
                        <p class="small text-muted mb-0">Créé le :
                            <?php
                                if (!empty($user['created_at']) && $user['created_at'] !== '0000-00-00 00:00:00') {
                                    echo date('d/m/Y H:i', strtotime($user['created_at']));
                                } else {
                                    echo 'Date inconnue';
                                }
                            ?>
                        </p>
                    </div>
                </div>

                <div class="card mt-3 shadow-sm p-3">
                    <h6 class="mb-2">Actions avancées</h6>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="reset_user_password.php?id=<?php echo $id; ?>" class="btn btn-outline-warning btn-sm">Réinitialiser le mot de passe</a>
                        <a href="user_activity.php?id=<?php echo $id; ?>" class="btn btn-outline-info btn-sm">Voir l'activité</a>

                        <?php if (($user['role'] ?? '') !== 'admin' || $id !== ($_SESSION['admin_id'] ?? 0)): ?>
                            <a href="delete_user.php?id=<?php echo $id; ?>" class="btn btn-danger btn-sm"
                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ? Cette action est irréversible.')">
                                Supprimer l'utilisateur
                            </a>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-sm" disabled title="Protection admin — suppression désactivée">Suppression protégée</button>
                        <?php endif; ?>
                    </div>
                    <div class="mt-2 small text-muted">Les actions sensibles sont désactivées en mode démo.</div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <form action="edit_user.php?id=<?php echo $id; ?>" method="post" class="card p-3 shadow-sm" novalidate>
                    <div class="mb-3">
                        <label for="name" class="form-label">Nom complet *</label>
                        <input type="text" id="name" name="name" class="form-control" required minlength="2" maxlength="100" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Adresse email *</label>
                        <input type="email" id="email" name="email" class="form-control" required value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="role" class="form-label">Rôle *</label>
                        <select id="role" name="role" class="form-select" required>
                            <option value="user" <?php echo (($user['role'] ?? '') === 'user') ? 'selected' : ''; ?>>Utilisateur</option>
                            <option value="admin" <?php echo (($user['role'] ?? '') === 'admin') ? 'selected' : ''; ?>>Administrateur</option>
                        </select>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <a href="list_users.php" class="btn btn-outline-secondary">Annuler</a>
                        <button type="submit" class="btn btn-success">Enregistrer les modifications</button>
                    </div>

                    <div class="mt-2 small text-muted">* Champs obligatoires. Les modifications sont journalisées dans le centre de notifications.</div>
                </form>
            </div>
        </div>
    </section>
</main>

<script>
(function(){
    var form = document.querySelector('form');
    var initialEmail = '<?php echo addslashes($user['email'] ?? ''); ?>';
    var initialRole = '<?php echo addslashes($user['role'] ?? ''); ?>';
    if (!form) return;

    form.addEventListener('submit', function(e){
        var email = document.getElementById('email').value.trim();
        var role = document.getElementById('role').value;

        if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            alert('Veuillez saisir une adresse email valide.');
            e.preventDefault();
            return;
        }

        if (email !== initialEmail || role !== initialRole) {
            if (!confirm('Attention : vous modifiez un champ sensible (email ou rôle). Voulez-vous vraiment continuer ?')) {
                e.preventDefault();
                return;
            }
        }
    }, false);
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
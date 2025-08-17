<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}
include '../includes/db.php';
include 'admin_demo_guard.php';

// INITIALISER LES VARIABLES
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!guardDemoAdmin()) {
        $error = "Action désactivée en mode démo.";
    } else {
        $name = $_POST['name'];
        $email = $_POST['email'];
        $password = $_POST['password'];
        $hashed_password = password_hash($password, PASSWORD_BCRYPT);
        $role = 'admin';

        // Vérifier si l'email existe déjà
        $query = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
        $query->execute([$email]);
        $email_exists = $query->fetchColumn();

        if ($email_exists) {
            $error = "L'email $email existe déjà.";
            // Notification persistante
            $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
            $stmt->execute([
                'error',
                "Échec création admin : email $email déjà utilisé (admin ID " . $_SESSION['admin_id'] . ")"
            ]);
        } else {
            $query = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
            if ($query->execute([$name, $email, $hashed_password, $role])) {
                $success = "Administrateur créé avec succès.";
                
                // Notification de succès
                $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
                $stmt->execute([
                    'admin_action',
                    "Nouvel admin créé : $name ($email) par admin ID " . $_SESSION['admin_id']
                ]);
            } else {
                $error = "Erreur lors de la création de l'administrateur.";
                // Notification persistante
                $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
                $stmt->execute([
                    'error',
                    "Erreur SQL lors de la création d'un admin (admin ID " . $_SESSION['admin_id'] . ")"
                ]);
            }
        }
    }
}

include 'includes/header.php';
?>

<main class="container py-4">
    <section class="row justify-content-center">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0"><i class="bi bi-person-plus me-2"></i>Créer un administrateur</h2>
                </div>
                <div class="card-body">
                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form action="create_admin.php" method="post">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom :</label>
                            <input type="text" name="name" id="name" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="email" class="form-label">Email :</label>
                            <input type="email" name="email" id="email" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="password" class="form-label">Mot de passe :</label>
                            <input type="password" name="password" id="password" class="form-control" required>
                            <div class="form-text">Minimum 6 caractères recommandés</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-person-plus me-2"></i>Créer l'administrateur
                            </button>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted">
                    <small><i class="bi bi-info-circle me-1"></i>L'administrateur pourra se connecter immédiatement après création</small>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include 'includes/footer.php'; ?>
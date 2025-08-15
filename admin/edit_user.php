<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include '../includes/db.php';

// Vérification de l'ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "ID utilisateur invalide.";
    header("Location: list_users.php");
    exit;
}

$id = (int)$_GET['id'];

// Récupérer l'utilisateur
try {
    $query = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $query->execute([$id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);

    if (!$user || !is_array($user)) {
        // Notification persistante en cas d'échec
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Échec modification utilisateur : ID $id introuvable (admin: " . ($_SESSION['admin_name'] ?? 'Unknown') . ")"
        ]);
        $_SESSION['error'] = "Utilisateur introuvable.";
        header("Location: list_users.php");
        exit;
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_users.php");
    exit;
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "Le nom est requis.";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email valide requis.";
    }
    
    if (!in_array($role, ['user', 'admin'])) {
        $errors[] = "Rôle invalide.";
    }
    
    // Vérifier que l'email n'est pas déjà utilisé par un autre utilisateur
    if (empty($errors)) {
        try {
            $check_email = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $check_email->execute([$email, $id]);
            $result = $check_email->fetch();
            if ($result) {
                $errors[] = "Cet email est déjà utilisé par un autre utilisateur.";
            }
        } catch (PDOException $e) {
            $errors[] = "Erreur lors de la vérification : " . $e->getMessage();
        }
    }
    
    // Si pas d'erreurs, mettre à jour
    if (empty($errors)) {
        try {
            $query = $pdo->prepare("UPDATE users SET name = ?, email = ?, role = ? WHERE id = ?");
            $success = $query->execute([$name, $email, $role, $id]);
            
            if ($success) {
                // Notification de succès
                $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 0)");
                $stmt->execute([
                    'admin_action',
                    "Utilisateur '$name' modifié avec succès par " . ($_SESSION['admin_name'] ?? 'Admin')
                ]);
                
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
    
    // Si des erreurs, les stocker pour affichage
    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
    }
}

include 'includes/header.php';
?>

<main class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between align-items-center">
                        <h4 class="mb-0">
                            <i class="bi bi-person-fill-gear me-2"></i>Modifier l'utilisateur
                        </h4>
                        <a href="list_users.php" class="btn btn-outline-light btn-sm">
                            <i class="bi bi-arrow-left me-1"></i>Retour à la liste
                        </a>
                    </div>
                </div>
                
                <div class="card-body">
                    <!-- Affichage des erreurs -->
                    <?php if (isset($_SESSION['errors']) && is_array($_SESSION['errors'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Erreurs détectées :</strong>
                            <ul class="mb-0 mt-2">
                                <?php foreach ($_SESSION['errors'] as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                        <?php unset($_SESSION['errors']); ?>
                    <?php endif; ?>
                    
                    <!-- Informations utilisateur actuel -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Modification de :</strong> <?php echo htmlspecialchars($user['name'] ?? 'Utilisateur inconnu'); ?> 
                        (ID: <?php echo $user['id'] ?? 'N/A'; ?>) - 
                        Créé le 
                        <?php
                        if (!empty($user['created_at']) && $user['created_at'] !== '0000-00-00 00:00:00') {
                            echo date('d/m/Y H:i', strtotime($user['created_at']));
                        } else {
                            echo 'Date inconnue';
                        }
                        ?>
                    </div>
                    
                    <!-- Formulaire -->
                    <form action="edit_user.php?id=<?php echo $id; ?>" method="post" class="needs-validation" novalidate>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">
                                        <i class="bi bi-person me-1"></i>Nom complet *
                                    </label>
                                    <input type="text" 
                                           name="name" 
                                           id="name" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" 
                                           required
                                           minlength="2"
                                           maxlength="100">
                                    <div class="invalid-feedback">
                                        Veuillez saisir un nom valide (2-100 caractères).
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">
                                        <i class="bi bi-envelope me-1"></i>Adresse email *
                                    </label>
                                    <input type="email" 
                                           name="email" 
                                           id="email" 
                                           class="form-control" 
                                           value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
                                           required>
                                    <div class="invalid-feedback">
                                        Veuillez saisir une adresse email valide.
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="role" class="form-label">
                                        <i class="bi bi-shield-check me-1"></i>Rôle *
                                    </label>
                                    <select name="role" id="role" class="form-select" required>
                                        <option value="">-- Sélectionner un rôle --</option>
                                        <option value="user" <?php echo (($user['role'] ?? '') == 'user') ? 'selected' : ''; ?>>
                                            👤 Utilisateur standard
                                        </option>
                                        <option value="admin" <?php echo (($user['role'] ?? '') == 'admin') ? 'selected' : ''; ?>>
                                            👑 Administrateur
                                        </option>
                                    </select>
                                    <div class="invalid-feedback">
                                        Veuillez sélectionner un rôle.
                                    </div>
                                    <div class="form-text">
                                        <i class="bi bi-info-circle me-1"></i>
                                        L'administrateur a accès au panneau d'administration.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">
                                        <i class="bi bi-calendar me-1"></i>Informations supplémentaires
                                    </label>
                                    <div class="card bg-light">
                                        <div class="card-body py-2">
                                            <small class="text-muted">
                                                <strong>ID :</strong> <?php echo $user['id'] ?? 'N/A'; ?><br>
                                                <strong>Créé :</strong>
                                                <?php
                                                if (!empty($user['created_at']) && $user['created_at'] !== '0000-00-00 00:00:00') {
                                                    echo date('d/m/Y à H:i', strtotime($user['created_at']));
                                                } else {
                                                    echo 'Date inconnue';
                                                }
                                                ?><br>
                                                <strong>Rôle actuel :</strong> 
                                                <span class="badge bg-<?php echo (($user['role'] ?? '') == 'admin') ? 'danger' : 'primary'; ?>">
                                                    <?php echo ucfirst($user['role'] ?? 'inconnu'); ?>
                                                </span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <div>
                                <small class="text-muted">* Champs obligatoires</small>
                            </div>
                            <div>
                                <a href="list_users.php" class="btn btn-outline-secondary me-2">
                                    <i class="bi bi-x-circle me-1"></i>Annuler
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check-circle me-1"></i>Enregistrer les modifications
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Carte d'actions supplémentaires -->
            <div class="card mt-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="bi bi-gear me-2"></i>Actions avancées
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <a href="reset_user_password.php?id=<?php echo $id; ?>" class="btn btn-outline-warning w-100">
                                <i class="bi bi-key me-1"></i>Réinitialiser le mot de passe
                            </a>
                        </div>
                        <div class="col-md-4">
                            <a href="user_activity.php?id=<?php echo $id; ?>" class="btn btn-outline-info w-100">
                                <i class="bi bi-activity me-1"></i>Voir l'activité
                            </a>
                        </div>
                        <div class="col-md-4">
                            <?php if (($user['role'] ?? '') != 'admin' || $id != ($_SESSION['admin_id'] ?? 0)): ?>
                            <a href="delete_user.php?id=<?php echo $id; ?>" 
                               class="btn btn-outline-danger w-100"
                               onclick="return confirm('Êtes-vous sûr de vouloir supprimer cet utilisateur ?')">
                                <i class="bi bi-trash me-1"></i>Supprimer
                            </a>
                            <?php else: ?>
                            <button class="btn btn-outline-secondary w-100" disabled>
                                <i class="bi bi-shield-x me-1"></i>Protection admin
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Script de validation -->
<script>
(function() {
    'use strict';
    
    // Validation Bootstrap
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });
    
    // Validation en temps réel de l'email
    const emailInput = document.getElementById('email');
    if (emailInput) {
        emailInput.addEventListener('input', function() {
            const email = this.value;
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
            if (email.length > 0) {
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            } else {
                this.classList.remove('is-valid', 'is-invalid');
            }
        });
    }
    
    // Prévisualisation du changement de rôle
    const roleSelect = document.getElementById('role');
    if (roleSelect) {
        roleSelect.addEventListener('change', function() {
            const currentRole = '<?php echo $user['role'] ?? ''; ?>';
            const newRole = this.value;
            if (currentRole !== newRole && newRole) {
                const alert = document.createElement('div');
                alert.className = 'alert alert-warning alert-dismissible fade show mt-2';
                alert.innerHTML = `
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Attention :</strong> Vous changez le rôle de "${currentRole}" vers "${newRole}".
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                `;
                const existingAlert = roleSelect.parentNode.querySelector('.alert-warning');
                if (existingAlert) {
                    existingAlert.remove();
                }
                roleSelect.parentNode.appendChild(alert);
            }
        });
    }

    // === AJOUT : Confirmation pour modifications sensibles ===
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const initialEmail = '<?php echo addslashes($user['email'] ?? ''); ?>';
            const initialRole = '<?php echo addslashes($user['role'] ?? ''); ?>';
            const newEmail = document.getElementById('email').value;
            const newRole = document.getElementById('role').value;
            if (initialEmail !== newEmail || initialRole !== newRole) {
                if (!confirm('Attention : vous modifiez un champ sensible (email ou rôle). Voulez-vous vraiment continuer ?')) {
                    e.preventDefault();
                }
            }
        });
    }
})();
</script>

<style>
.card {
    border: none;
    border-radius: 15px;
}

.card-header {
    border-radius: 15px 15px 0 0 !important;
}

.form-control, .form-select {
    border-radius: 10px;
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
}

.btn {
    border-radius: 10px;
    font-weight: 500;
}

.alert {
    border-radius: 10px;
    border: none;
}

.badge {
    font-size: 0.75rem;
}
</style>

<?php include 'includes/footer.php'; ?>
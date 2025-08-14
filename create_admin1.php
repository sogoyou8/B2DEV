<?php
include 'includes/db.php';

$name = 'Admin1';
$email = 'admin1@gmail.com'; // Modifie l'email si besoin
$password = 'Adminmdp1';
$role = 'admin';

// Vérifier si l'utilisateur existe déjà
$query = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
$query->execute([$email]);
if ($query->fetchColumn() > 0) {
    echo "L'utilisateur avec cet email existe déjà.";
    exit;
}

// Hacher le mot de passe
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

// Insérer l'utilisateur
$query = $pdo->prepare("INSERT INTO users (name, email, password, role, created_at) VALUES (?, ?, ?, ?, NOW())");
$success = $query->execute([$name, $email, $hashed_password, $role]);

if ($success) {
    echo "Utilisateur Admin1 créé avec succès.";
} else {
    echo "Erreur lors de la création de l'utilisateur.";
}
?>
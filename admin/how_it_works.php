<?php
if (session_status() == PHP_SESSION_NONE) session_start();
include 'includes/header.php';

// Détection de la page source
$page = $_GET['page'] ?? 'prediction';

// Détermination du retour
$back_url = $_SERVER['HTTP_REFERER'] ?? 'prediction.php';
?>

<main class="container py-4">
    <h2><i class="bi bi-info-circle me-2"></i>Comment ça marche ?</h2>
    <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn btn-outline-primary mb-3">
        <i class="bi bi-arrow-left me-1"></i>Retour à la page précédente
    </a>
    <?php if ($page === 'prediction'): ?>
        <div class="alert alert-info">
            <h4>Prédiction IA des ventes</h4>
            <ul>
                <li>Choisissez la période d’analyse (3, 6 ou 12 mois).</li>
                <li>Cliquez sur "Rafraîchir la prédiction IA" pour générer les prévisions.</li>
                <li>Consultez la demande prévue, la confiance et les recommandations pour chaque produit.</li>
                <li>Les badges indiquent l’urgence et la fiabilité des résultats.</li>
                <li>Utilisez le bouton "Historique des prévisions" pour voir les anciennes prévisions.</li>
                <li>Le bouton "Comment ça marche ?" affiche cette explication.</li>
            </ul>
        </div>
    <?php elseif ($page === 'history'): ?>
        <div class="alert alert-info">
            <h4>Historique des prévisions</h4>
            <ul>
                <li>Filtrez par produit ou par mois pour retrouver une prévision passée.</li>
                <li>Exportez l’historique en CSV pour analyse externe.</li>
                <li>Utilisez le bouton "Retour à la prédiction IA" pour revenir à la page principale.</li>
                <li>Le bouton "Comment ça marche ?" affiche cette explication.</li>
            </ul>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary">
            <h4>Fonctionnalité non documentée</h4>
            <p>Cette page n’a pas encore d’explication dédiée.</p>
        </div>
    <?php endif;
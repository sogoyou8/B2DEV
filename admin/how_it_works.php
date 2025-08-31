<?php
if (session_status() == PHP_SESSION_NONE) session_start();
include 'includes/header.php';

// Détection de la page source
$page = $_GET['page'] ?? 'prediction';

// Détermination du retour
$back_url = $_SERVER['HTTP_REFERER'] ?? 'prediction.php';
?>
<link rel="stylesheet" href="../assets/css/admin/how_it_works.css">

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
                <li>Le moteur calcule une prévision par produit basée sur l'historique des ventes et une régression linéaire.</li>
                <li>La colonne <strong>Confiance</strong> indique la fiabilité : <em>données insuffisantes</em> → score faible.</li>
                <li>Utilisez le bouton <strong>Générer</strong> pour lancer le calcul (opération lourde ; désactivée en mode démo).</li>
                <li>Consultez l'historique des prédictions via <a href="prediction_history.php">Historique</a>.</li>
            </ul>
        </div>

    <?php elseif ($page === 'bulk_update' || $page === 'bulk_update_products'): ?>
        <!-- Section BUP : version synthétique pour administrateurs -->
        <div class="alert alert-info">
            <h4>Mise à jour en masse des produits</h4>

            <p class="mb-2">
                Utilisez cette interface pour appliquer rapidement des changements (prix, stock, catégorie, seuil)
                à plusieurs produits sélectionnés. La page propose un aperçu avant application et un import CSV simple.
            </p>

            <h6 class="mt-3">Points essentiels (niveau administrateur)</h6>
            <ul>
                <li>Sélectionnez les produits (ou la case "Tout sélectionner" pour la page courante) après avoir filtré la liste.</li>
                <li>Renseignez au moins un champ à modifier : Prix (absolu ou %), Stock (absolu ou delta), Catégorie ou Seuil alerte.</li>
                <li>Le bouton <strong>Appliquer aux sélectionnés</strong> montre un aperçu local avant d'envoyer la modification.</li>
                <li>Format CSV accepté : <code>id,price,stock,category</code> — l'import effectue des validations et signale les lignes problématiques.</li>
                <li>En mode démo, les actions sensibles sont bloquées (message et protection).</li>
            </ul>

            <h6 class="mt-3">Règles rapides</h6>
            <ul>
                <li>Ne renseignez pas simultanément Prix absolu et Prix %.</li>
                <li>Ne renseignez pas simultanément Stock absolu et Stock delta.</li>
                <li>Les modifications sont appliquées en transaction et journalisées pour traçabilité.</li>
            </ul>

            <p class="small text-muted mt-2">
                Voir la page : <a href="admin/bulk_update_products.php">admin/bulk_update_products.php</a>.
            </p>
        </div>

    <?php elseif ($page === 'demo'): ?>
        <div class="alert alert-warning">
            <h4><i class="bi bi-exclamation-triangle-fill me-2"></i>Mode Démo</h4>
            <ul>
                <li>Le mode démo permet de tester toutes les fonctionnalités sans modifier les vraies données.</li>
                <li>Les actions sensibles (ajout, modification, suppression, export CSV, génération IA) sont désactivées.</li>
                <li>Un badge <span class="badge bg-warning text-dark">Mode Démo</span> s’affiche en haut de la page.</li>
                <li>Pour quitter le mode démo, déconnectez-vous et connectez-vous avec un compte réel.</li>
            </ul>
        </div>

    <?php elseif ($page === 'history'): ?>
        <div class="alert alert-info">
            <h4>Historique des prévisions</h4>
            <ul>
                <li>Filtrez par produit ou par mois pour retrouver une prévision passée.</li>
                <li>Exportez l’historique en CSV pour analyse externe.</li>
                <li>Utilisez le bouton "Retour à la prédiction IA" pour revenir à la page principale.</li>
            </ul>
        </div>

    <?php else: ?>
        <div class="alert alert-secondary">
            <h4>Fonctionnalité non documentée</h4>
            <p>La page demandée n'a pas de guide associé. Si vous souhaitez une documentation dédiée pour cette page, indiquez le nom de la page (paramètre <code>?page=...</code>) et je générerai le contenu.</p>
        </div>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>
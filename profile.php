<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit;
}

include 'includes/header.php';
include 'includes/db.php';

// helper adresse centralisé (utilisé pour fallback / format)
include_once __DIR__ . '/includes/address.php';

$user_id = $_SESSION['user_id'] ?? 0;

/*
 * Récupération des informations utilisateur
 */
try {
    $query = $pdo->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
    $query->execute([$user_id]);
    $user = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $user = false;
}

$user_name  = $user['name'] ?? ($_SESSION['user_name'] ?? 'Utilisateur');
$user_email = $user['email'] ?? ($_SESSION['user_email'] ?? '');
$created_at = $user['created_at'] ?? null;

/**
 * Retourne les initiales pour l'avatar placeholder
 */
function initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $initials .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $initials ?: 'U';
}

/*
 * Petites métriques / données (gardées côté serveur si besoin ailleurs)
 * Nous gardons ces calculs en arrière-plan mais n'affichons pas un bloc résumé volumineux,
 * afin de préserver la verticalité de la page.
 */
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $totalOrders = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $totalOrders = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$user_id]);
    $pendingOrders = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $pendingOrders = 0;
}

try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_price),0) FROM orders WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $totalSpent = (float)$stmt->fetchColumn();
} catch (Exception $e) {
    $totalSpent = 0.0;
}

try {
    $stmt = $pdo->prepare("SELECT id, total_price, order_date FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 1");
    $stmt->execute([$user_id]);
    $lastOrder = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $lastOrder = null;
}

/* Adresse affichée : priorité -> adresse profil (users) si renseignée, sinon dernière invoice */
$displayAddress = null;
try {
    // tenter adresse profil via helper
    if ($user_id > 0) {
        $profileAddr = getUserAddress($pdo, (int)$user_id);
        if (!empty($profileAddr) && !empty(trim((string)$profileAddr['billing_address'] ?? ''))) {
            $displayAddress = [
                'billing_address' => $profileAddr['billing_address'] ?? '',
                'city' => $profileAddr['city'] ?? '',
                'postal_code' => $profileAddr['postal_code'] ?? ''
            ];
        } else {
            // fallback : dernière adresse de facture
            $invoiceAddr = getLatestInvoiceAddress($pdo, (int)$user_id);
            if (!empty($invoiceAddr)) {
                $displayAddress = $invoiceAddr;
            } else {
                $displayAddress = null;
            }
        }
    } else {
        $displayAddress = null;
    }
} catch (Exception $e) {
    $displayAddress = null;
}

/* Charger la feuille de styles dédiée */
echo '<link rel="stylesheet" href="assets/css/user/profile.css">' ;
?>
<script>
/* Mark page for targeted CSS overrides as early as possible */
try { document.body.classList.add('profile-page'); } catch(e){}
</script>

<main class="container profile-wrap" role="main" aria-labelledby="profileTitle">
    <section class="panel-card compact" aria-describedby="profileDesc" role="region" id="profilePanel">
        <div class="profile-header" id="profileHeader">
            <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars(initials($user_name)); ?></div>
            <div class="profile-meta">
                <h1 id="profileTitle" class="h4 mb-0">Profil de <?php echo htmlspecialchars($user_name); ?></h1>
                <div id="profileDesc" class="muted small-muted">Membre depuis : <?php echo $created_at ? htmlspecialchars((new DateTime($created_at))->format('d/m/Y')) : '—'; ?></div>
            </div>
            <div class="header-actions" aria-hidden="false">
                <a href="update_profile.php" class="btn btn-sm btn-outline-primary" title="Modifier le profil" aria-label="Modifier le profil">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                </a>
                <a href="update_password.php" class="btn btn-sm btn-outline-secondary" title="Changer le mot de passe" aria-label="Changer le mot de passe">
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                </a>
            </div>
        </div>

        <?php if (!empty($_SESSION['profile_update_success'])): ?>
            <div class="alert alert-success compact-alert" role="status">
                <?php echo htmlspecialchars($_SESSION['profile_update_success']); unset($_SESSION['profile_update_success']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['demo_error'])): ?>
            <div class="alert alert-warning compact-alert" role="alert">
                <?php echo htmlspecialchars($_SESSION['demo_error']); unset($_SESSION['demo_error']); ?>
            </div>
        <?php endif; ?>

        <div class="grid compact-grid" aria-live="polite">
            <div class="left-col">
                <section class="info-card condensed" role="region" aria-label="Informations utilisateur">
                    <h5 class="mb-2">Informations</h5>
                    <div class="info-row"><span class="label">Email</span><span class="value"><?php echo htmlspecialchars($user_email ?: '—'); ?></span></div>
                    <div class="info-row"><span class="label">Date de création</span><span class="value"><?php echo $created_at ? htmlspecialchars((new DateTime($created_at))->format('d/m/Y H:i')) : '—'; ?></span></div>
                </section>

                <section class="info-card condensed mt-2 compact-orders-card" role="region" aria-label="Commandes récentes">
                    <h5 class="mb-2">Mes commandes récentes</h5>
                    <?php
                        try {
                            // limité à 3 pour compacité
                            $s = $pdo->prepare("SELECT id, total_price, status, order_date FROM orders WHERE user_id = ? ORDER BY order_date DESC LIMIT 3");
                            $s->execute([$user_id]);
                            $recent = $s->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $recent = [];
                        }
                    ?>
                    <?php if (!empty($recent)): ?>
                        <ul class="list-unstyled mb-0 compact-orders">
                            <?php foreach ($recent as $o): ?>
                                <li class="order-item">
                                    <a href="order_details.php?id=<?php echo (int)$o['id']; ?>" class="order-link">Commande #<?php echo (int)$o['id']; ?></a>
                                    <div class="small-muted order-meta"><?php echo htmlspecialchars((new DateTime($o['order_date']))->format('d/m/Y')); ?> — <?php echo number_format((float)$o['total_price'],2,',',' ') ; ?> €</div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="mt-2 compact-seeall"><a href="orders_invoices.php" class="btn btn-sm btn-outline-primary">Voir toutes mes commandes</a></div>
                    <?php else: ?>
                        <div class="text-muted">Aucune commande récente.</div>
                    <?php endif; ?>
                </section>
            </div>

            <aside class="right-col" role="region" aria-label="Actions rapides & outils">
                <div class="form-card compact text-center" aria-hidden="false">
                    <div class="mb-1 small-muted">Actions rapides</div>

                    <div class="d-grid gap-2 mb-2">
                        <a href="update_profile.php" class="btn btn-primary btn-sm btn-block" aria-label="Modifier le profil">
                            <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Modifier le profil
                        </a>

                        <a href="update_password.php" class="btn btn-outline-secondary btn-sm btn-block" aria-label="Changer le mot de passe">
                            <i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Changer le mot de passe
                        </a>
                    </div>

                    <div class="mt-1 small-muted">Les modifications sensibles peuvent être restreintes en mode démo.</div>

                    <hr class="my-2" />

                    <form id="deleteAccountForm" action="delete_account.php" method="post" onsubmit="return confirmDeleteAccount();" class="mb-0">
                        <button type="submit" class="btn btn-danger btn-sm btn-block" aria-label="Supprimer le compte">
                            <i class="bi bi-trash me-1" aria-hidden="true"></i>Supprimer le compte
                        </button>
                    </form>
                </div>

                <!-- Adresse de facturation -->
                <div class="info-card condensed mt-3 address-card" role="region" aria-label="Adresse de facturation">
                    <h6 class="mb-2">Adresse de facturation</h6>
                    <?php if (!empty($displayAddress) && (!empty($displayAddress['billing_address']) || !empty($displayAddress['city']) || !empty($displayAddress['postal_code']))): ?>
                        <div class="small-muted mb-1"><?php echo formatAddressForDisplay($displayAddress); ?></div>
                        <div class="mt-2">
                            <a href="update_profile.php" class="btn btn-sm btn-outline-primary btn-block">Gérer mes adresses</a>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Aucune adresse enregistrée.</div>
                        <div class="mt-2"><a href="update_profile.php" class="btn btn-sm btn-outline-primary btn-block">Ajouter une adresse</a></div>
                    <?php endif; ?>
                </div>

                <!-- Support rapide -->
                <div class="info-card condensed mt-3 support-card" role="region" aria-label="Support">
                    <h6 class="mb-2">Support</h6>
                    <div class="small-muted">Besoin d'aide ?</div>
                    <ul class="small text-muted mb-2" style="padding-left:18px;margin-top:6px;">
                        <li><a href="orders_invoices.php">Historique & factures</a></li>
                        <li><a href="contact.php">Contacter le support</a></li>
                        <li><a href="faq.php">FAQ</a></li>
                    </ul>
                    <div><a href="contact.php" class="btn btn-sm btn-primary btn-block" aria-label="Contacter le support">Contacter</a></div>
                </div>
            </aside>
        </div>
    </section>
</main>

<script>
/* JS léger pour accès clavier / confirmations et fitting dans la fenêtre */
(function () {
    window.confirmDeleteAccount = function() {
        return confirm('Êtes-vous sûr de vouloir supprimer votre compte ? Cette action est irréversible.');
    };

    // raccourci clavier : 'a' focus primary
    document.addEventListener('keydown', function(e){
        if (e.key === 'a' && !/input|textarea/i.test(document.activeElement.tagName)) {
            var btn = document.querySelector('.form-card .btn-primary');
            if (btn) btn.focus();
        }
    });

    // rendre les icônes de header accessibles
    document.querySelectorAll('.header-actions .btn').forEach(function(b){
        b.setAttribute('tabindex', '0');
    });

    // Fit-to-viewport : calcule un scale si le panel dépasse la zone visible
    function fitProfilePanel() {
        var panel = document.getElementById('profilePanel');
        if (!panel) return;

        var htmlEl = document.documentElement;
        var bodyEl = document.body;

        // header/footer détectés (fallback valeurs)
        var headerEl = document.querySelector('header.site-header') || document.querySelector('.site-header');
        var footerEl = document.querySelector('footer') || document.querySelector('.site-footer');

        var headerH = headerEl ? headerEl.getBoundingClientRect().height : 72;
        var footerH = footerEl ? footerEl.getBoundingClientRect().height : 40;

        var available = window.innerHeight - headerH - footerH - 20; // marge réduite
        // reset transform to measure natural size
        panel.style.transform = '';
        panel.style.transformOrigin = 'top center';
        panel.style.maxHeight = '';
        panel.style.overflowY = 'visible';
        panel.style.marginTop = '';

        // measure natural height (including margins)
        var rect = panel.getBoundingClientRect();
        var naturalH = rect.height;

        if (naturalH > available) {
            var scale = available / naturalH;
            // don't scale below a readable limit
            var minScale = 0.75;
            if (scale < minScale) scale = minScale;

            panel.style.transform = 'scale(' + scale + ')';
            panel.style.transformOrigin = 'top center';

            // center horizontally and provide top spacing
            var parent = panel.parentElement;
            if (parent) {
                parent.style.display = 'flex';
                parent.style.justifyContent = 'center';
                parent.style.alignItems = 'flex-start';
                parent.style.paddingTop = '10px';
                parent.style.height = (available) + 'px';
                parent.style.overflow = 'hidden';
            }
            panel.style.maxWidth = '100%';

            // hide document scrollbars while scaled to avoid double scroll
            if (htmlEl) htmlEl.style.overflow = 'hidden';
            if (bodyEl) bodyEl.style.overflow = 'hidden';
        } else {
            // ensure normal layout
            var par = panel.parentElement;
            if (par) {
                par.style.display = '';
                par.style.justifyContent = '';
                par.style.alignItems = '';
                par.style.height = '';
                par.style.paddingTop = '';
                par.style.overflow = '';
            }
            panel.style.transform = '';
            panel.style.maxHeight = '';
            panel.style.overflowY = '';

            // restore document scrolling
            if (htmlEl) htmlEl.style.overflow = '';
            if (bodyEl) bodyEl.style.overflow = '';
        }
    }

    // Debounced resize
    var t;
    function deb(fn, ms){ clearTimeout(t); t = setTimeout(fn, ms || 120); }
    window.addEventListener('resize', function(){ deb(fitProfilePanel, 120); }, { passive: true });
    document.addEventListener('DOMContentLoaded', function(){ deb(fitProfilePanel, 60); });
    // call immediately in case DOM already loaded
    setTimeout(fitProfilePanel, 80);
})();
</script>

<?php include 'includes/footer.php'; ?>
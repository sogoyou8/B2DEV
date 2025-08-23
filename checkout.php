<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * Démarrer le buffering immédiatement et sans condition
 * pour garantir que les header() effectuées plus bas ne
 * déclenchent pas "headers already sent" si includes/header.php
 * émet du HTML.
 */
if (!ob_get_level()) {
    ob_start();
    register_shutdown_function(function () {
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
    });
}

/*
 * Inclure la connexion DB avant le traitement POST (nécessaire pour les requêtes)
 * et avant l'inclusion du header qui émet du HTML.
 */
include 'includes/db.php';

/*
 * Vérification connexion utilisateur (tolérant : certaines pages utilisent 'logged_in' ou 'user_id')
 * Cette redirection doit être faite avant l'inclusion de includes/header.php pour éviter les erreurs.
 */
if ((empty($_SESSION['logged_in']) || !$_SESSION['logged_in']) && empty($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);

/*
 * Si possible, s'assurer qu'il existe une colonne persistante pour la préférence de sauvegarde
 * (save_address_default). Si elle n'existe pas, tenter de l'ajouter (silent fail si pas possible).
 */
try {
    $col = $pdo->query("SHOW COLUMNS FROM `users` LIKE 'save_address_default'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        // essayer d'ajouter la colonne pour persister la préférence
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `save_address_default` TINYINT(1) NOT NULL DEFAULT 0");
    }
} catch (Exception $_) {
    // si l'ALTER TABLE échoue (permissions, colonne inexistante), on continue sans erreur fatale
}

/*
 * Pré-remplir les champs d'adresse :
 * 1) depuis users (si colonnes présentes)
 * 2) fallback : dernière invoice liée à une commande
 * 3) fallback : valeurs vides
 */
$billing_address_default = '';
$city_default = '';
$postal_code_default = '';
$save_address_checked = false; // si true => checkbox cochée par défaut

if ($user_id > 0) {
    try {
        // tenter de récupérer billing_address et la préférence save_address_default si disponibles
        $q = $pdo->prepare("SELECT billing_address, city, postal_code, IFNULL(save_address_default,0) AS save_address_default FROM users WHERE id = ? LIMIT 1");
        $q->execute([$user_id]);
        $u = $q->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $billing_address_default = $u['billing_address'] ?? '';
            $city_default = $u['city'] ?? '';
            $postal_code_default = $u['postal_code'] ?? '';
            $save_address_checked = !empty($u['save_address_default']) ? true : false;
        }
    } catch (Exception $_) {
        $billing_address_default = $city_default = $postal_code_default = '';
        $save_address_checked = false;
    }

    if ($billing_address_default === '' && $city_default === '' && $postal_code_default === '') {
        try {
            // fallback : dernière invoice liée à une commande
            $q2 = $pdo->prepare("SELECT i.billing_address, i.city, i.postal_code
                                 FROM invoice i
                                 JOIN orders o ON o.id = i.order_id
                                 WHERE o.user_id = ?
                                 ORDER BY i.transaction_date DESC
                                 LIMIT 1");
            $q2->execute([$user_id]);
            $inv = $q2->fetch(PDO::FETCH_ASSOC);
            if ($inv) {
                $billing_address_default = $inv['billing_address'] ?? '';
                $city_default = $inv['city'] ?? '';
                $postal_code_default = $inv['postal_code'] ?? '';
                // si on a trouvé une invoice et qu'il n'y avait pas de save flag en user, on ne coche pas automatiquement
                // sauf si la session indique précédemment la préférence
            }
        } catch (Exception $_) {
            // ignore
        }
    }
}

/*
 * Récupérer les articles du panier (avant affichage / traitement)
 */
try {
    $stmt = $pdo->prepare("SELECT items.*, cart.quantity FROM items JOIN cart ON items.id = cart.item_id WHERE cart.user_id = ?");
    $stmt->execute([$user_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $items = [];
    $_SESSION['error'] = "Impossible de récupérer le panier : " . $e->getMessage();
}

$total = 0.0;
foreach ($items as $item) {
    $total += floatval($item['price']) * intval($item['quantity']);
}

/*
 * Si la page a précédemment stocké des anciennes valeurs (PRG), les récupérer
 */
$old_input = $_SESSION['old_input'] ?? [];
if (!empty($_SESSION['old_input'])) {
    // on copie pour utilisation puis on unset la session (préférable)
    unset($_SESSION['old_input']);
}

/*
 * Traitement du formulaire de checkout
 * IMPORTANT : ce traitement est fait AVANT l'inclusion du header pour permettre
 * d'utiliser header("Location: ...") sans erreur "headers already sent".
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Récupération sécurisée des champs POST (évite "Undefined array key")
    $billing_address = trim((string)($_POST['billing_address'] ?? $old_input['billing_address'] ?? ''));
    $city = trim((string)($_POST['city'] ?? $old_input['city'] ?? ''));
    $postal_code = trim((string)($_POST['postal_code'] ?? $old_input['postal_code'] ?? ''));
    // Détecter si la case a été cochée dans ce POST
    $save_address = isset($_POST['save_address']) && ($_POST['save_address'] == '1' || $_POST['save_address'] === 'on') ? 1 : 0;

    // Validation minimaliste côté serveur
    $errors = [];
    if ($billing_address === '') $errors[] = "Adresse de facturation requise.";
    if ($city === '') $errors[] = "Ville requise.";
    if ($postal_code === '') $errors[] = "Code postal requis.";

    if (empty($items)) {
        $errors[] = "Votre panier est vide.";
    }

    // Mettre à jour la préférence en session uniquement si l'utilisateur a explicitement demandé de sauvegarder
    if ($save_address) {
        $_SESSION['save_address_default'] = 1;
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Insérer la commande
            $ins = $pdo->prepare("INSERT INTO orders (user_id, total_price, status, order_date) VALUES (?, ?, 'pending', NOW())");
            $ins->execute([$user_id, round($total, 2)]);
            $order_id = $pdo->lastInsertId();

            // Enregistrer les détails de la commande et décrémenter le stock
            $detailStmt = $pdo->prepare("INSERT INTO order_details (order_id, item_id, quantity, price) VALUES (?, ?, ?, ?)");
            $updateStockStmt = $pdo->prepare("UPDATE items SET stock = stock - ? WHERE id = ?");
            $selectProdStmt = $pdo->prepare("SELECT name, stock, stock_alert_threshold FROM items WHERE id = ?");

            foreach ($items as $item) {
                $itemId = (int)$item['id'];
                $qty = (int)$item['quantity'];
                $price = floatval($item['price']);

                $detailStmt->execute([$order_id, $itemId, $qty, $price]);
                $updateStockStmt->execute([$qty, $itemId]);

                // Vérifier seuil et créer notification si nécessaire
                $selectProdStmt->execute([$itemId]);
                $prod = $selectProdStmt->fetch(PDO::FETCH_ASSOC);
                if ($prod && isset($prod['stock']) && isset($prod['stock_alert_threshold'])) {
                    if (intval($prod['stock']) <= intval($prod['stock_alert_threshold'])) {
                        try {
                            $msg = "Le produit '{$prod['name']}' est en stock faible ({$prod['stock']} restant, seuil {$prod['stock_alert_threshold']}).";
                            $notifStmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, ?)");
                            $notifStmt->execute(['important', $msg, intval($prod['stock']) == 0 ? 1 : 0]);
                        } catch (Exception $_) {
                            // ignore non-fatal notification errors
                        }
                    }
                }
            }

            // Générer la facture (avec champs sécurisés)
            $billing_address_db = $billing_address;
            $city_db = $city;
            $postal_code_db = $postal_code;
            $inv = $pdo->prepare("INSERT INTO invoice (order_id, amount, billing_address, city, postal_code) VALUES (?, ?, ?, ?, ?)");
            $inv->execute([$order_id, round($total, 2), $billing_address_db, $city_db, $postal_code_db]);

            // Vider le panier après la commande
            $del = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
            $del->execute([$user_id]);

            // Enregistrer l'adresse et la préférence dans users si demandé (ou si colonnes existent)
            if ($user_id > 0 && $save_address) {
                try {
                    // Si l'utilisateur a choisi d'enregistrer l'adresse comme défaut => mettre à jour les colonnes
                    $upd = $pdo->prepare("UPDATE users SET billing_address = ?, city = ?, postal_code = ?, save_address_default = 1 WHERE id = ?");
                    $upd->execute([$billing_address_db, $city_db, $postal_code_db, $user_id]);
                } catch (Exception $_) {
                    // la table users peut ne pas avoir ces colonnes -> ignorer silencieusement
                }
            }
            // NOTE: on NE remet PAS save_address_default = 0 automatiquement lorsqu'il n'est pas coché.
            // Cela évite d'effacer la préférence existante si l'utilisateur oublie de cocher la case.
            // Pour retirer la préférence, fournir une action explicite (ex: page profil) est préférable.

            $pdo->commit();

            // Rediriger vers la page de confirmation (PRG)
            header("Location: confirmation.php");
            exit;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors du traitement de la commande : " . $e->getMessage();
            // conserver les valeurs soumises pour ré-affichage
            $_SESSION['old_input'] = [
                'billing_address' => $billing_address,
                'city' => $city,
                'postal_code' => $postal_code
            ];
            // rester sur la page pour affichage de l'erreur
        }
    } else {
        // erreurs de validation -> stocker pour affichage et conserver les champs
        $_SESSION['errors'] = $errors;
        $_SESSION['old_input'] = [
            'billing_address' => $billing_address,
            'city' => $city,
            'postal_code' => $postal_code
        ];
        // on affiche immédiatement les erreurs (pas de redirection)
    }
}

/*
 * À présent, inclure le header (après tout traitement POST/redirect possible).
 * includes/header.php émet du HTML, mais toutes les redirections et envois d'entêtes
 * ont déjà été effectués.
 */
include 'includes/header.php';

/*
 * Préparer les valeurs à afficher dans le formulaire (priorité : POST > old_input > defaults)
 */
$form_values = [];
$form_values['billing_address'] = $_POST['billing_address'] ?? ($old_input['billing_address'] ?? $billing_address_default ?? '');
$form_values['city'] = $_POST['city'] ?? ($old_input['city'] ?? $city_default ?? '');
$form_values['postal_code'] = $_POST['postal_code'] ?? ($old_input['postal_code'] ?? $postal_code_default ?? '');

// Déterminer si la checkbox doit être cochée (priorité):
// 1) si l'utilisateur a récemment demandé la sauvegarde dans la session ($_SESSION['save_address_default'])
// 2) sinon si l'utilisateur a la colonne save_address_default = 1 (détectée plus haut)
// 3) sinon false
$save_checked = false;
if (!empty($_SESSION['save_address_default'])) {
    $save_checked = true;
} elseif (!empty($save_address_checked)) {
    $save_checked = true;
} else {
    $save_checked = false;
}
?>
<style>
/* Visual improvements for checkout */
.checkout-wrapper { max-width: 1200px; margin: 28px auto; display: grid; grid-template-columns: 1fr 360px; gap: 28px; align-items:start; padding: 0 16px; }
.checkout-panel, .summary-panel { background: #ffffff; padding: 22px; border-radius: 12px; box-shadow: 0 8px 22px rgba(15,23,42,0.05); }
.checkout-panel h2 { margin-top: 0; font-size: 1.8rem; }
.alert-server { background: #fdecea; color:#7c1d1d; border-left:4px solid #f8c4c0; padding: 14px; border-radius:8px; }
.field-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; align-items:center; }
.field-row { margin-bottom: 12px; }
.field-row label { display:block; font-weight:600; margin-bottom:6px; color:#1f2937; }
.field-row input[type="text"], .field-row input[type="email"], .field-row input[type="number"] {
    width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; font-size:0.95rem;
    box-sizing:border-box;
}
.form-check { margin-top:8px; display:flex; align-items:center; gap:8px; }
.form-actions { display:flex; gap:12px; margin-top:16px; flex-wrap:wrap; }
.btn { display:inline-block; padding:10px 18px; border-radius:8px; cursor:pointer; font-weight:600; border:1px solid transparent; }
.btn-primary { background:#2563eb; color:#fff; }
.btn-ghost { background:transparent; color:#2563eb; border:1px solid #cfe0ff; }
.btn-danger { background:#ef4444; color:#fff; }
.summary-panel h3 { margin-top:0; font-size:1.1rem; }
.summary-line { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px dashed #eef2f7; }
.small-muted { color:#6b7280; font-size:0.9rem; }
.badge-default { display:inline-block; background:#10b981; color:#fff; padding:4px 8px; border-radius:999px; font-size:0.85rem; margin-left:8px; }
@media (max-width: 900px) {
    .checkout-wrapper { grid-template-columns: 1fr; }
    .summary-panel { order: 2; }
}
</style>

<main class="checkout-wrapper" role="main" aria-labelledby="checkoutTitle">
    <section class="checkout-panel" aria-labelledby="checkoutTitle">
        <h2 id="checkoutTitle">Passer à la caisse</h2>

        <?php if (!empty($_SESSION['errors'])): ?>
            <div id="serverErrors" class="alert-server" role="alert">
                <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($_SESSION['errors'] as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errors']); ?>
            <div style="height:12px;"></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert-server" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <div style="height:12px;"></div>
        <?php endif; ?>

        <?php if (!empty($items)): ?>
            <div class="small-muted" style="margin-bottom:12px;">Récapitulatif rapide des articles dans votre panier</div>
            <ul style="list-style:none;padding:0;margin:0 0 18px 0;">
                <?php foreach ($items as $item): ?>
                    <li style="padding:10px 0;border-bottom:1px solid #f1f5f9;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <div>
                                <div style="font-weight:700;"><?php echo htmlspecialchars($item['name']); ?></div>
                                <div class="small-muted">Quantité : <?php echo htmlspecialchars($item['quantity']); ?></div>
                            </div>
                            <div style="text-align:right;">
                                <div style="font-weight:700;"><?php echo htmlspecialchars(number_format((float)$item['price'] * (int)$item['quantity'], 2)); ?> €</div>
                                <div class="small-muted">Prix unitaire : <?php echo htmlspecialchars(number_format((float)$item['price'], 2)); ?> €</div>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div style="margin:14px 0 20px;">
                <div style="font-weight:700;font-size:1.05rem;">Total du panier : <?php echo htmlspecialchars(number_format($total, 2)); ?> €</div>
            </div>

            <form id="checkoutForm" action="checkout.php" method="post" novalidate>
                <div class="field-row">
                    <label for="billing_address">Adresse de facturation</label>
                    <input type="text" name="billing_address" id="billing_address" value="<?php echo htmlspecialchars($form_values['billing_address']); ?>" required>
                </div>

                <div class="field-grid">
                    <div class="field-row" style="grid-column:1/2;">
                        <label for="city">Ville</label>
                        <input type="text" name="city" id="city" value="<?php echo htmlspecialchars($form_values['city']); ?>" required>
                    </div>
                    <div class="field-row" style="grid-column:2/3;">
                        <label for="postal_code">Code postal</label>
                        <input type="text" name="postal_code" id="postal_code" pattern="^[0-9A-Za-z \-]{2,10}$" value="<?php echo htmlspecialchars($form_values['postal_code']); ?>" required>
                        <div class="small-muted" style="margin-top:6px;">Format accepté : chiffres/lettres, 2-10 caractères.</div>
                    </div>
                </div>

                <div class="form-check" style="margin-top:12px;">
                    <input type="checkbox" name="save_address" id="save_address" value="1" <?php echo $save_checked ? 'checked' : ''; ?>>
                    <label for="save_address">Enregistrer comme adresse par défaut</label>
                    <?php if ($save_checked): ?>
                        <span class="badge-default" aria-hidden="true">Enregistrée</span>
                    <?php endif; ?>
                </div>

                <div class="form-actions" role="group" aria-label="Actions de validation">
                    <button type="submit" class="btn btn-primary">Finaliser l'achat</button>
                    <button type="button" id="prefillBtn" class="btn btn-ghost" aria-controls="billing_address">Remplir depuis mon profil</button>
                    <button type="button" id="cancelBtn" class="btn btn-danger">Annuler et revenir au panier</button>
                </div>
            </form>

        <?php else: ?>
            <p>Votre panier est vide.</p>
            <div class="mt-3">
                <a href="products.php" class="btn btn-primary">Continuer vos achats</a>
            </div>
        <?php endif; ?>
    </section>

    <aside class="summary-panel" aria-labelledby="summaryTitle">
        <h3 id="summaryTitle">Résumé de la commande</h3>
        <div class="summary-line">
            <div class="small-muted">Articles</div>
            <div class="small-muted"><?php echo (int)count($items); ?></div>
        </div>
        <div class="summary-line">
            <div class="small-muted">Sous‑total</div>
            <div class="small-muted"><?php echo htmlspecialchars(number_format($total, 2)); ?> €</div>
        </div>
        <div class="summary-line">
            <div class="small-muted">Livraison</div>
            <div class="small-muted">Calculée à l'étape suivante</div>
        </div>
        <div style="padding-top:10px;font-weight:700;">Total estimé : <?php echo htmlspecialchars(number_format($total, 2)); ?> €</div>

        <div style="margin-top:14px;" class="small-muted">
            Vos informations enregistrées :<br>
            <strong><?php echo htmlspecialchars($billing_address_default ?: '—'); ?></strong><br>
            <?php if ($billing_address_default || $city_default || $postal_code_default): ?>
                <div class="small-muted"><?php echo htmlspecialchars(trim($city_default . ' ' . $postal_code_default)); ?></div>
            <?php endif; ?>
            <?php if ($save_checked): ?>
                <div style="margin-top:8px;"><span class="badge-default">Adresse par défaut active</span></div>
            <?php else: ?>
                <div style="margin-top:8px;" class="small-muted">Aucune adresse par défaut enregistrée.</div>
            <?php endif; ?>
        </div>
    </aside>
</main>

<script>
(function(){
    var prefillData = {
        billing_address: <?php echo json_encode($billing_address_default, JSON_UNESCAPED_UNICODE); ?>,
        city: <?php echo json_encode($city_default, JSON_UNESCAPED_UNICODE); ?>,
        postal_code: <?php echo json_encode($postal_code_default, JSON_UNESCAPED_UNICODE); ?>
    };

    var prefillBtn = document.getElementById('prefillBtn');
    var cancelBtn = document.getElementById('cancelBtn');
    var form = document.getElementById('checkoutForm');
    var billing = document.getElementById('billing_address');
    var city = document.getElementById('city');
    var postal = document.getElementById('postal_code');

    prefillBtn && prefillBtn.addEventListener('click', function(){
        if (!prefillData) return;
        if (prefillData.billing_address) billing.value = prefillData.billing_address;
        if (prefillData.city) city.value = prefillData.city;
        if (prefillData.postal_code) postal.value = prefillData.postal_code;
        // focus the first field for convenience
        billing.focus();
    });

    cancelBtn && cancelBtn.addEventListener('click', function(){
        if (confirm('Voulez-vous vraiment annuler et revenir au panier ? Les champs non enregistrés seront perdus.')) {
            window.location.href = 'cart.php';
        }
    });

    // Client-side light validation to avoid roundtrip when trivial
    form && form.addEventListener('submit', function(e){
        var errors = [];
        if (!billing.value.trim()) errors.push('Adresse de facturation requise.');
        if (!city.value.trim()) errors.push('Ville requise.');
        var postalVal = postal.value.trim();
        var postalPattern = /^[0-9A-Za-z \-]{2,10}$/;
        if (!postalVal) errors.push('Code postal requis.');
        else if (!postalPattern.test(postalVal)) errors.push('Format du code postal invalide.');

        if (errors.length) {
            e.preventDefault();
            // show a simple alert-in-form (scroll to top of panel)
            var existing = document.getElementById('clientErrors');
            if (existing) existing.remove();
            var container = document.createElement('div');
            container.id = 'clientErrors';
            container.className = 'alert-server';
            var ul = document.createElement('ul');
            ul.style.margin = '0';
            ul.style.paddingLeft = '18px';
            errors.forEach(function(err){
                var li = document.createElement('li');
                li.textContent = err;
                ul.appendChild(li);
            });
            container.appendChild(ul);
            var panel = document.querySelector('.checkout-panel');
            panel.insertBefore(container, panel.firstChild);
            window.scrollTo({ top: panel.getBoundingClientRect().top + window.scrollY - 20, behavior: 'smooth' });
        }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
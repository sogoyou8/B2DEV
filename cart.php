<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Démarrer le buffering pour éviter "headers already sent" si includes/header.php émet du HTML
if (!ob_get_level()) {
    ob_start();
}
// Garantit la vidange du buffer à la fin du script
register_shutdown_function(function () {
    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
});

include 'includes/db.php';

/**
 * Helper: format price en français
 */
function format_price($value) {
    return number_format((float)$value, 2, ',', ' ') . ' €';
}

/**
 * Récupère les lignes du panier (session ou DB)
 * Retourne tableau d'items avec au moins : id, name, description, price, stock, quantity
 */
function get_cart_items($pdo) {
    $items = [];
    $total = 0.0;
    $user_id = $_SESSION['user_id'] ?? null;

    if (!empty($user_id)) {
        try {
            $stmt = $pdo->prepare("SELECT items.*, cart.quantity FROM items JOIN cart ON items.id = cart.item_id WHERE cart.user_id = ?");
            $stmt->execute([(int)$user_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $r['quantity'] = (int)$r['quantity'];
                $items[] = $r;
                $total += ((float)$r['price'] * $r['quantity']);
            }
        } catch (Exception $e) {
            $items = [];
        }
    } else {
        if (empty($_SESSION['temp_cart']) || !is_array($_SESSION['temp_cart'])) {
            $_SESSION['temp_cart'] = [];
        }
        $temp = $_SESSION['temp_cart'];
        if (!empty($temp)) {
            $ids = array_map('intval', array_keys($temp));
            $ids = array_values(array_filter($ids, fn($v) => $v > 0));
            if (!empty($ids)) {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                try {
                    $stmt = $pdo->prepare("SELECT * FROM items WHERE id IN ($placeholders)");
                    $stmt->execute($ids);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $r) {
                        $id = (int)$r['id'];
                        $qty = max(0, (int)($temp[$id] ?? 0));
                        if ($qty <= 0) continue;
                        if (isset($r['is_active']) && intval($r['is_active']) === 0) continue;
                        $r['quantity'] = $qty;
                        $items[] = $r;
                        $total += ((float)$r['price'] * $qty);
                    }
                } catch (Exception $e) {
                    $items = [];
                }
            }
        }
    }

    return ['items' => $items, 'total' => $total];
}

/**
 * Apply POST actions (add/update/remove/clear) — retourne tableau résultat (utilisé aussi pour AJAX)
 * $isAjax flag permet de ne pas faire de redirect mais retourner un résultat.
 */
function handle_cart_action($pdo, $isAjax = false) {
    $action = '';
    if (isset($_POST['add'])) $action = 'add';
    if (isset($_POST['update'])) $action = 'update';
    if (isset($_POST['remove'])) $action = 'remove';
    if (isset($_POST['clear'])) $action = 'clear';

    $user_id = $_SESSION['user_id'] ?? null;
    $response = ['success' => false, 'message' => '', 'data' => []];

    if ($action === 'add') {
        $item_id = (int)($_POST['id'] ?? 0);
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        if ($item_id > 0) {
            try {
                $checkStmt = $pdo->prepare("SELECT stock, IFNULL(is_active,1) AS is_active, price FROM items WHERE id = ? LIMIT 1");
                $checkStmt->execute([$item_id]);
                $itemRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
            } catch (Exception $e) {
                $itemRow = false;
            }

            if (!$itemRow || intval($itemRow['is_active']) === 0) {
                $response['message'] = "Produit introuvable ou désactivé.";
                return $response;
            }

            $stock = max(0, (int)$itemRow['stock']);

            // quantité existante
            $existingQty = 0;
            if ($user_id) {
                try {
                    $q = $pdo->prepare("SELECT quantity FROM cart WHERE user_id = ? AND item_id = ? LIMIT 1");
                    $q->execute([$user_id, $item_id]);
                    $exist = $q->fetch(PDO::FETCH_ASSOC);
                    if ($exist) $existingQty = (int)$exist['quantity'];
                } catch (Exception $e) {
                    $existingQty = 0;
                }
            } else {
                $existingQty = isset($_SESSION['temp_cart'][$item_id]) ? (int)$_SESSION['temp_cart'][$item_id] : 0;
            }

            if ($existingQty + $quantity > $stock) {
                $response['message'] = "Quantité demandée non disponible (stock actuel : {$stock}).";
                return $response;
            }

            if ($user_id) {
                try {
                    $query = $pdo->prepare("INSERT INTO cart (user_id, item_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)");
                    $query->execute([$user_id, $item_id, $quantity]);
                    $response['success'] = true;
                } catch (Exception $e) {
                    $response['message'] = "Impossible d'ajouter le produit au panier pour le moment.";
                }
            } else {
                if (!isset($_SESSION['temp_cart'][$item_id])) {
                    $_SESSION['temp_cart'][$item_id] = $quantity;
                } else {
                    $_SESSION['temp_cart'][$item_id] = $_SESSION['temp_cart'][$item_id] + $quantity;
                }
                $response['success'] = true;
            }
        }
    } elseif ($action === 'update') {
        $item_id = (int)($_POST['id'] ?? 0);
        $quantity = max(0, (int)($_POST['quantity'] ?? 0)); // 0 -> remove
        if ($item_id > 0) {
            if ($quantity > 0) {
                try {
                    $checkStmt = $pdo->prepare("SELECT stock, IFNULL(is_active,1) AS is_active FROM items WHERE id = ? LIMIT 1");
                    $checkStmt->execute([$item_id]);
                    $itemRow = $checkStmt->fetch(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $itemRow = false;
                }
                if (!$itemRow || intval($itemRow['is_active']) === 0) {
                    $response['message'] = "Produit introuvable ou désactivé.";
                    return $response;
                }
                $stock = max(0, (int)$itemRow['stock']);
                if ($quantity > $stock) {
                    $response['message'] = "Quantité demandée non disponible (stock actuel : {$stock}).";
                    return $response;
                }
            }

            if ($user_id) {
                try {
                    if ($quantity > 0) {
                        $query = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND item_id = ?");
                        $query->execute([$quantity, $user_id, $item_id]);
                    } else {
                        $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND item_id = ?");
                        $query->execute([$user_id, $item_id]);
                    }
                    $response['success'] = true;
                } catch (Exception $e) {
                    $response['message'] = "Impossible de mettre à jour le panier pour le moment.";
                }
            } else {
                if ($quantity > 0) {
                    $_SESSION['temp_cart'][$item_id] = $quantity;
                } else {
                    unset($_SESSION['temp_cart'][$item_id]);
                }
                $response['success'] = true;
            }
        }
    } elseif ($action === 'remove') {
        $item_id = (int)($_POST['id'] ?? 0);
        if ($item_id > 0) {
            if ($user_id) {
                try {
                    $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND item_id = ?");
                    $query->execute([$user_id, $item_id]);
                    $response['success'] = true;
                } catch (Exception $e) {
                    $response['message'] = "Impossible de supprimer la ligne du panier.";
                }
            } else {
                unset($_SESSION['temp_cart'][$item_id]);
                $response['success'] = true;
            }
        }
    } elseif ($action === 'clear') {
        if ($user_id) {
            try {
                $query = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
                $query->execute([$user_id]);
                $response['success'] = true;
            } catch (Exception $e) {
                $response['message'] = "Impossible de vider le panier.";
            }
        } else {
            $_SESSION['temp_cart'] = [];
            $response['success'] = true;
        }
    }

    // recalculer état du panier pour réponse
    $snapshot = get_cart_items($pdo);
    $response['data']['items'] = [];
    foreach ($snapshot['items'] as $it) {
        $response['data']['items'][] = [
            'id' => (int)$it['id'],
            'name' => $it['name'],
            'price' => (float)$it['price'],
            'quantity' => (int)$it['quantity'],
            'line_total' => round((float)$it['price'] * (int)$it['quantity'], 2)
        ];
    }
    $response['data']['total'] = round($snapshot['total'], 2);

    return $response;
}

// Détecter si la requête est AJAX (fetch)
$isAjax = false;
$requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
if (strtolower($requestedWith) === 'xmlhttprequest' || (isset($_POST['ajax']) && $_POST['ajax'] == '1')) {
    $isAjax = true;
}

// Si POST et AJAX -> traiter et répondre JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAjax) {
    $result = handle_cart_action($pdo, true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
    exit;
}

// Si POST non-AJAX -> utiliser comportement existant (redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isAjax) {
    $result = handle_cart_action($pdo, false);
    if (!empty($result['message'])) {
        $_SESSION['error'] = $result['message'];
    } elseif (!empty($result['success'])) {
        $_SESSION['success'] = "Panier mis à jour.";
    }
    header("Location: cart.php");
    exit;
}

// Inclure header et styles (affichage)
include 'includes/header.php';
echo '<link rel="stylesheet" href="assets/css/user/products.css">';
echo '<link rel="stylesheet" href="assets/css/user/cart.css">';

// Préparer placeholder SVG
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="600" height="400"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="18">Aucune image</text></svg>';
$placeholderDataUri = 'data:image/svg+xml;utf8,' . rawurlencode($svg);

// Précharger favoris utilisateur pour afficher état coeur
$userFavorites = [];
if (!empty($_SESSION['user_id'])) {
    try {
        $favStmt = $pdo->prepare("SELECT item_id FROM favorites WHERE user_id = ?");
        $favStmt->execute([$_SESSION['user_id']]);
        $rows = $favStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $userFavorites[] = (int)$r['item_id'];
        }
    } catch (Exception $e) {
        $userFavorites = [];
    }
}

// Obtenir état courant du panier pour affichage
$snapshot = get_cart_items($pdo);
$items = $snapshot['items'];
$total = $snapshot['total'];
?>
<main class="container py-4">
    <section class="cart-section bg-light p-4 rounded shadow-sm mx-auto">
        <h2 class="h3 mb-4 font-weight-bold">Panier</h2>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div id="cart-list">
            <?php if (!empty($items)): ?>
                <?php foreach ($items as $item): ?>
                    <?php
                        // Récupérer images (toutes les images pour le carrousel)
                        try {
                            $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position");
                            $imgStmt->execute([(int)$item['id']]);
                            $images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (Exception $e) {
                            $images = [];
                        }

                        $pid = (int)$item['id'];
                        $pname = htmlspecialchars($item['name']);
                        $pdesc = htmlspecialchars($item['description']);
                        $pprice = (float)$item['price'];
                        $pqty = (int)$item['quantity'];
                        $pstock = isset($item['stock']) ? (int)$item['stock'] : null;
                        $isFav = in_array($pid, $userFavorites, true);

                        // URL cible pour la navigation comme dans products.php
                        $detailUrl = "product_detail.php?id=" . $pid;
                    ?>
                    <div class="cart-item-row product-card" id="cart-item-<?php echo $pid; ?>" data-item-id="<?php echo $pid; ?>" data-href="<?php echo $detailUrl; ?>" tabindex="0" role="link" aria-label="Voir <?php echo $pname; ?>">
                        <div class="thumb">
                            <?php if (!empty($images)): ?>
                                <div id="carouselCart<?php echo $pid; ?>" class="carousel slide" data-ride="carousel" data-interval="false">
                                    <div class="carousel-inner">
                                        <?php foreach ($images as $index => $image): ?>
                                            <?php $imgSrc = $image && !empty($image['image']) ? 'assets/images/' . htmlspecialchars($image['image']) : $placeholderDataUri; ?>
                                            <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                                                <img src="<?php echo $imgSrc; ?>" alt="<?php echo $pname; ?>" class="d-block w-100" onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php if (count($images) > 1): ?>
                                        <a class="carousel-control-prev" href="#carouselCart<?php echo $pid; ?>" role="button" data-slide="prev">
                                            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Précédent</span>
                                        </a>
                                        <a class="carousel-control-next" href="#carouselCart<?php echo $pid; ?>" role="button" data-slide="next">
                                            <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                            <span class="sr-only">Suivant</span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <img src="<?php echo $placeholderDataUri; ?>" alt="<?php echo $pname; ?>" onerror="this.onerror=null;this.src='<?php echo $placeholderDataUri; ?>'">
                            <?php endif; ?>
                        </div>

                        <div class="info">
                            <div class="d-flex justify-content-between">
                                <div class="product-title">
                                    <h5 class="mb-1"><?php echo $pname; ?></h5>
                                    <p class="text-muted small mb-1"><?php echo $pdesc ?: 'Aucune description'; ?></p>
                                </div>
                                <?php if ($pstock !== null && $pstock <= 0): ?>
                                    <div class="text-danger small">Rupture</div>
                                <?php endif; ?>
                            </div>

                            <div class="product-meta mt-2">
                                <div><strong>Prix unitaire :</strong> <?php echo format_price($pprice); ?></div>
                                <div class="mt-1"><strong>Total ligne :</strong> <span class="line-total"><?php echo format_price($pprice * $pqty); ?></span></div>
                            </div>
                        </div>

                        <div class="controls">
                            <div class="qty-controls" data-id="<?php echo $pid; ?>">
                                <button class="btn btn-light btn-sm qty-decrease" type="button" title="Réduire">−</button>
                                <input type="number" class="qty-input form-control form-control-sm" min="1" <?php if ($pstock !== null) echo 'max="'. $pstock .'"'; ?> value="<?php echo $pqty; ?>" style="width:70px;text-align:center;">
                                <button class="btn btn-light btn-sm qty-increase" type="button" title="Augmenter">+</button>
                            </div>

                            <div class="mt-2 d-flex gap-2 justify-content-end align-items-center">
                                <!-- Bouton supprimer amélioré -->
                                <button class="btn btn-danger btn-sm btn-remove d-flex align-items-center" data-id="<?php echo $pid; ?>" title="Supprimer du panier" aria-label="Supprimer du panier">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path>
                                        <path d="M10 11v6"></path>
                                        <path d="M14 11v6"></path>
                                        <path d="M9 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                    <span>Supprimer</span>
                                </button>

                                <!-- Favori à côté du supprimer (même design que favorites/new_products) -->
                                <form action="favorites.php" method="post" class="m-0 p-0 ultra-form" style="margin-left:6px;">
                                    <input type="hidden" name="id" value="<?php echo $pid; ?>">
                                    <?php if ($isFav): ?>
                                        <button type="submit" name="remove" class="ultra-btn ultra-fav ultra-fav-active" title="Retirer des favoris" aria-label="Retirer des favoris">
                                            <div class="ultra-inner" aria-hidden="true">
                                                <svg class="ultra-icon ultra-heart" width="16" height="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M20.8 6.6c-1.6-1.8-4.2-1.9-5.9-0.4l-0.9 0.8-0.9-0.8c-1.7-1.5-4.3-1.4-5.9 0.4-1.7 1.9-1.6 5 0.3 6.8l6.4 5.2 6.4-5.2c1.9-1.6 2-4.9 0.1-6.8z" fill="currentColor"/>
                                                </svg>
                                            </div>
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="add" class="ultra-btn ultra-fav" title="Ajouter aux favoris" aria-label="Ajouter aux favoris">
                                            <div class="ultra-inner" aria-hidden="true">
                                                <svg class="ultra-icon ultra-heart" width="16" height="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path d="M20.8 6.6c-1.6-1.8-4.2-1.9-5.9-0.4l-0.9 0.8-0.9-0.8c-1.7-1.5-4.3-1.4-5.9 0.4-1.7 1.9-1.6 5 0.3 6.8l6.4 5.2 6.4-5.2c1.9-1.6 2-4.9 0.1-6.8z" stroke="currentColor" stroke-width="1.6" fill="none"/>
                                                </svg>
                                            </div>
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            <?php else: ?>
                <div class="alert alert-info">Votre panier est vide.</div>
            <?php endif; ?>
        </div>

        <!-- Résumé sticky -->
        <div id="cart-summary" class="cart-summary mt-4">
            <div>
                <div>Sous-total : <span id="cart-subtotal"><?php echo format_price($total); ?></span></div>
                <div id="cart-shipping" class="text-muted small">Livraison estimée : <span id="shipping-val">Calcul en caisse</span></div>
            </div>
            <div class="text-right">
                <div class="h5 mb-1">Total : <span id="cart-total"><?php echo format_price($total); ?></span></div>
                <div class="d-flex gap-2 mt-2">
                    <button id="btn-clear-cart" class="btn btn-danger btn-sm">Vider le panier</button>
                    <a href="checkout.php" class="btn btn-primary btn-sm">Passer à la caisse</a>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
/*
  AJAX interactions pour cart.php
  - Utilise fetch() pour envoyer les actions add/update/remove/clear
  - Met à jour le DOM en place sans reload
  - Auto-update : les changements de quantité déclenchent automatiquement la mise à jour (debounced)
  - Les éléments cart-item-row sont cliquables pour aller sur product_detail (comme products.php)
*/
(function () {
    const cartList = document.getElementById('cart-list');
    const summarySubtotal = document.getElementById('cart-subtotal');
    const summaryTotal = document.getElementById('cart-total');
    const btnClear = document.getElementById('btn-clear-cart');

    function buildFormData(actionName, payload) {
        const fd = new FormData();
        fd.append(actionName, '1');
        fd.append('ajax', '1');
        for (const k in payload) {
            if (!Object.prototype.hasOwnProperty.call(payload, k)) continue;
            fd.append(k, payload[k]);
        }
        return fd;
    }

    function sendRequest(actionName, payload) {
        return fetch('cart.php', {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: buildFormData(actionName, payload)
        }).then(r => r.json());
    }

    function updateSummary(total) {
        const formatted = (new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })).format(total) + ' €';
        summarySubtotal.textContent = formatted;
        summaryTotal.textContent = formatted;
    }

    function findItemRow(id) {
        return document.querySelector('#cart-item-' + id);
    }

    // Debounce helper
    function debounce(fn, wait) {
        let t;
        return function () {
            const ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(() => fn.apply(ctx, args), wait);
        };
    }

    // Auto-update on quantity change (debounced)
    const triggerUpdate = debounce(function(id, qty, triggerBtn) {
        // disable input while updating
        const row = findItemRow(id);
        if (!row) return;
        const input = row.querySelector('.qty-input');
        if (input) input.disabled = true;
        sendRequest('update', { id: id, quantity: qty }).then(handleResponse).finally(() => {
            if (input) input.disabled = false;
            if (triggerBtn) triggerBtn.disabled = false;
        });
    }, 600);

    // Delegate clicks: increase / decrease / remove
    cartList.addEventListener('click', function (e) {
        const dec = e.target.closest('.qty-decrease');
        const inc = e.target.closest('.qty-increase');
        const removeBtn = e.target.closest('.btn-remove');
        // note: add-one removed per spec

        if (dec || inc) {
            const wrapper = (dec || inc).closest('.qty-controls');
            const input = wrapper.querySelector('.qty-input');
            let val = parseInt(input.value, 10) || 1;
            if (dec) val = Math.max(1, val - 1);
            if (inc) {
                const max = parseInt(input.getAttribute('max') || '9999', 10);
                val = Math.min(max, val + 1);
            }
            input.value = val;
            // auto-update after change
            const id = wrapper.closest('.cart-item-row').getAttribute('data-item-id');
            triggerUpdate(id, val);
            return;
        }

        if (removeBtn) {
            const id = removeBtn.getAttribute('data-id');
            if (!confirm('Supprimer cet article du panier ?')) return;
            removeBtn.disabled = true;
            sendRequest('remove', { id: id }).then(handleResponse).finally(() => removeBtn.disabled = false);
            return;
        }
    });

    // Listen change on inputs (manual typing) -> trigger update debounced
    cartList.addEventListener('input', function(e) {
        const el = e.target;
        if (el && el.classList.contains('qty-input')) {
            const row = el.closest('.cart-item-row');
            const id = row ? row.getAttribute('data-item-id') : null;
            let qty = parseInt(el.value, 10) || 1;
            const max = parseInt(el.getAttribute('max') || '9999', 10);
            if (qty < 1) qty = 1;
            if (qty > max) qty = max;
            el.value = qty;
            if (id) triggerUpdate(id, qty);
        }
    });

    // Clear cart
    btnClear.addEventListener('click', function () {
        if (!confirm('Vider complètement le panier ?')) return;
        btnClear.disabled = true;
        sendRequest('clear', {}).then(handleResponse).finally(() => btnClear.disabled = false);
    });

    // Handler réponse JSON
    function handleResponse(json) {
        if (!json) return;
        if (!json.success) {
            if (json.message) {
                showToast(json.message, 'danger');
            } else {
                showToast('Une erreur est survenue', 'danger');
            }
            // si data présent, still try to reconcile
        } else {
            showToast('Panier mis à jour', 'success');
        }

        // Reconstruire / ajuster les lignes en fonction de json.data.items
        if (json.data && Array.isArray(json.data.items)) {
            const currentIds = Array.from(document.querySelectorAll('.cart-item-row')).map(el => parseInt(el.getAttribute('data-item-id'), 10));
            const serverIds = json.data.items.map(it => it.id);

            // Mettre à jour ou supprimer les éléments existants
            json.data.items.forEach(it => {
                const row = findItemRow(it.id);
                if (row) {
                    // mettre à jour quantité input et line total
                    const input = row.querySelector('.qty-input');
                    if (input) input.value = it.quantity;
                    const lineTotalEl = row.querySelector('.line-total');
                    if (lineTotalEl) {
                        lineTotalEl.textContent = (new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })).format(it.line_total) + ' €';
                    }
                } else {
                    // nouvel item : recharger la page completement (simple fallback)
                    window.location.reload();
                }
            });

            // Supprimer les lignes qui ne sont plus côté serveur
            currentIds.forEach(id => {
                if (serverIds.indexOf(id) === -1) {
                    const row = findItemRow(id);
                    if (row) row.remove();
                }
            });

            // Mettre à jour le résumé
            if (typeof json.data.total !== 'undefined') {
                updateSummary(json.data.total);
            }

            // Si plus d'items, afficher message vide ou vider l'UI
            if (json.data.items.length === 0) {
                cartList.innerHTML = '<div class="alert alert-info">Votre panier est vide.</div>';
            }
        }
    }

    // petit util toast
    function showToast(message, type) {
        // simple ephemeral message top-right
        const toast = document.createElement('div');
        toast.className = 'ajax-toast alert alert-' + (type || 'info');
        toast.style.position = 'fixed';
        toast.style.right = '20px';
        toast.style.top = '20px';
        toast.style.zIndex = 20000;
        toast.style.boxShadow = '0 6px 20px rgba(0,0,0,0.12)';
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.transition = 'opacity 0.35s ease';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 400);
        }, 1600);
    }

    // Accessibility: allow Enter on qty input to trigger immediate update
    cartList.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            const el = e.target;
            if (el && el.classList.contains('qty-input')) {
                const row = el.closest('.cart-item-row');
                const id = row ? row.getAttribute('data-item-id') : null;
                if (id) {
                    const qty = parseInt(el.value, 10) || 1;
                    triggerUpdate(id, qty);
                }
            }
        }
    });

    // Rendre la carte cliquable sans bloquer les boutons/inputs/links (même logique que products.php/new_products.php).
    function isInteractive(el) {
        return el && (el.closest && (el.closest('button, a, form, input, select, textarea') !== null) || el.tagName === 'BUTTON' || el.tagName === 'A');
    }

    document.querySelectorAll('.product-card').forEach(function(card){
        var href = card.getAttribute('data-href');
        if (!href) return;

        card.addEventListener('click', function(e){
            var target = e.target;
            if (isInteractive(target)) return;
            if (target.closest && target.closest('button, a, form, input, select, textarea')) return;
            window.location.href = href;
        });

        card.addEventListener('keydown', function(e){
            if (e.key === 'Enter' || e.key === ' ') {
                var active = document.activeElement;
                if (active && active !== card && active.closest && active.closest('button, a, form, input, select, textarea')) {
                    return;
                }
                e.preventDefault();
                window.location.href = href;
            }
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
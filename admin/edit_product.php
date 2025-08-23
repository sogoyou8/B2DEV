<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'admin_demo_guard.php';
include '../includes/db.php';
include 'includes/header.php';

// Récupérer l'ID du produit
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = "ID produit invalide.";
    header("Location: list_products.php");
    exit;
}

// Charger le produit
try {
    $q = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $q->execute([$id]);
    $product = $q->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
    header("Location: list_products.php");
    exit;
}

if (!$product) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (type, message, is_persistent) VALUES (?, ?, 1)");
        $stmt->execute([
            'error',
            "Échec modification produit : ID $id introuvable (admin ID " . ($_SESSION['admin_id'] ?? 'unknown') . ")"
        ]);
    } catch (Exception $e) {
        // ignore notification failure
    }
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Récupérer images associées (prévisualisation)
try {
    $imgStmt = $pdo->prepare("SELECT id, image, caption, position FROM product_images WHERE product_id = ? ORDER BY position ASC");
    $imgStmt->execute([$id]);
    $product_images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $product_images = [];
}

/**
 * Résout une source d'image web utilisable par les pages admin (retourne chemin relatif ou data URI).
 */
function resolveImageSrcAdmin(string $imageName = ''): string {
    $assetsFsDir = realpath(__DIR__ . '/../assets/images');
    $candidates = [];
    if ($assetsFsDir !== false) {
        if ($imageName !== '') {
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . 'products' . DIRECTORY_SEPARATOR . $imageName;
            $candidates[] = $assetsFsDir . DIRECTORY_SEPARATOR . basename($imageName);
        }
        $defaultFs = $assetsFsDir . DIRECTORY_SEPARATOR . 'default.png';
    } else {
        $candidates[] = __DIR__ . "/../assets/images/" . $imageName;
        $candidates[] = __DIR__ . "/../assets/images/products/" . $imageName;
        $defaultFs = __DIR__ . "/../assets/images/default.png";
    }

    foreach ($candidates as $p) {
        if (!$p) continue;
        if (@file_exists($p)) {
            return '../assets/images/' . rawurlencode(basename($p));
        }
    }

    if (@file_exists($defaultFs)) {
        return '../assets/images/default.png';
    }

    // fallback SVG
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="16">No image</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}

// Traitement du formulaire de mise à jour
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_product'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: edit_product.php?id=$id");
        exit;
    }

    // Collecte et sanitation
    $name = isset($_POST['name']) ? trim((string)$_POST['name']) : '';
    $description = isset($_POST['description']) ? trim((string)$_POST['description']) : '';
    $price = isset($_POST['price']) ? $_POST['price'] : '';
    $stock = isset($_POST['stock']) ? $_POST['stock'] : '';
    $category = isset($_POST['category']) ? trim((string)$_POST['category']) : null;
    $stock_alert_threshold = isset($_POST['stock_alert_threshold']) && $_POST['stock_alert_threshold'] !== '' ? intval($_POST['stock_alert_threshold']) : null;

    $errors = [];

    // Validations serveur basiques (comme dans add_product)
    if ($name === '' || mb_strlen($name) < 1) {
        $errors[] = "Le nom est requis.";
    }
    if ($description === '') {
        $errors[] = "La description est requise.";
    }
    if ($price === '' || !is_numeric($price) || floatval($price) < 0) {
        $errors[] = "Le prix est invalide.";
    }
    if ($stock === '' || !is_numeric($stock) || intval($stock) < 0) {
        $errors[] = "Le stock est invalide.";
    }
    if ($stock_alert_threshold !== null && $stock_alert_threshold < 0) {
        $stock_alert_threshold = 0;
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("UPDATE items SET name = ?, description = ?, price = ?, stock = ?, category = ?, stock_alert_threshold = ?, updated_at = NOW() WHERE id = ?");
            $ok = $stmt->execute([
                $name,
                $description,
                round(floatval($price), 2),
                intval($stock),
                $category === '' ? null : $category,
                $stock_alert_threshold === null ? intval($product['stock_alert_threshold'] ?? 0) : intval($stock_alert_threshold),
                $id
            ]);

            if ($ok) {
                // Log notification non-persistante pour journalisation
                try {
                    $stmtN = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                    $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                    $stmtN->execute([
                        'admin_action',
                        "Produit '" . addslashes($name) . "' modifié par {$adminName}"
                    ]);
                } catch (Exception $e) {
                    // ignore logging failure
                }

                $_SESSION['success'] = "Produit modifié avec succès.";
                header("Location: list_products.php");
                exit;
            } else {
                $errors[] = "Erreur lors de la mise à jour du produit.";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur base de données : " . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['errors'] = $errors;
        // re-fetch product to repopulate values on the form (do not redirect away)
        try {
            $q = $pdo->prepare("SELECT * FROM items WHERE id = ?");
            $q->execute([$id]);
            $product = $q->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            // ignore
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Admin - Modifier un produit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <style>
        :root{
            --card-radius:12px;
            --muted:#6c757d;
            --bg-gradient-1:#f8fbff;
            --bg-gradient-2:#eef7ff;
            --accent:#0d6efd;
            --accent-2:#6610f2;
        }
        body.admin-page {
            background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2));
        }
        .panel-card {
            border-radius: var(--card-radius);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
            box-shadow: 0 12px 36px rgba(3,37,76,0.06);
            padding: 1.25rem;
        }
        .page-title { display:flex; gap:1rem; align-items:center; }
        .page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
        .help-note { color:var(--muted); font-size:.95rem; }
        .btn-round { border-radius:8px; }
        .preview-main { border-radius:10px; background:#fff; padding:1rem; box-shadow:0 6px 18px rgba(3,37,76,0.03); min-height:320px; display:flex; align-items:center; justify-content:center; }
        .preview-main img { max-width:100%; max-height:420px; object-fit:cover; border-radius:8px; }
        .preview-thumbs { display:flex; gap:12px; margin-top:.75rem; flex-wrap:wrap; }
        .preview-thumb { width:84px; height:84px; object-fit:cover; border-radius:8px; cursor:pointer; border:1px solid rgba(0,0,0,0.04); box-shadow:0 8px 20px rgba(3,37,76,0.06); }
        .existing-images img { width:110px; height:80px; object-fit:cover; border-radius:8px; }
    </style>
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Modifier le produit</h2>
                    <div class="small text-muted ms-2">La prévisualisation correspond à la fiche publique — les modifications se refléteront ici.</div>
                </div>
                <div class="small text-muted mt-1">Produit ID: <?php echo (int)$product['id']; ?></div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_products.php" class="btn btn-outline-secondary btn-round">Retour à la liste</a>
                <a href="manage_product_images.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-round">Gérer les images</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['errors'])): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($_SESSION['errors'] as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="preview-main">
                    <?php
                        // Show primary image if exists else default
                        $primary = $product_images[0]['image'] ?? '';
                        $primarySrc = resolveImageSrcAdmin($primary);
                    ?>
                    <img id="previewMain" src="<?php echo htmlspecialchars($primarySrc); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>">
                </div>

                <?php if (!empty($product_images)): ?>
                    <div class="preview-thumbs mt-3">
                        <?php foreach ($product_images as $pi): ?>
                            <img src="<?php echo htmlspecialchars(resolveImageSrcAdmin($pi['image'])); ?>" alt="<?php echo htmlspecialchars($pi['caption'] ?? ''); ?>" class="preview-thumb" data-src="<?php echo htmlspecialchars(resolveImageSrcAdmin($pi['image'])); ?>">
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="mt-3 small text-muted">
                    <p class="mb-1">Conseils :</p>
                    <ul>
                        <li>Utilisez <strong>Gérer les images</strong> pour ajouter, réorganiser ou modifier les légendes.</li>
                        <li>La première image (position 0) sera utilisée comme image principale.</li>
                    </ul>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <form action="edit_product.php?id=<?php echo $id; ?>" method="post" class="card p-3 shadow-sm" novalidate>
                    <input type="hidden" name="update_product" value="1">
                    <div class="mb-3">
                        <label for="name" class="form-label">Nom :</label>
                        <input type="text" name="name" id="name" class="form-control" required minlength="1" maxlength="200" value="<?php echo htmlspecialchars($product['name'] ?? ''); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description :</label>
                        <textarea name="description" id="description" class="form-control" rows="4" required><?php echo htmlspecialchars($product['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="row g-2">
                        <div class="col-6">
                            <label for="price" class="form-label">Prix :</label>
                            <input type="number" name="price" id="price" step="0.01" class="form-control" required value="<?php echo htmlspecialchars(number_format((float)$product['price'], 2, '.', '')); ?>">
                        </div>
                        <div class="col-6">
                            <label for="stock" class="form-label">Stock :</label>
                            <input type="number" name="stock" id="stock" class="form-control" required value="<?php echo htmlspecialchars((int)$product['stock']); ?>">
                        </div>
                    </div>

                    <div class="row g-2 mt-2">
                        <div class="col-7">
                            <label for="category" class="form-label">Catégorie :</label>
                            <input type="text" name="category" id="category" class="form-control" value="<?php echo htmlspecialchars($product['category'] ?? ''); ?>">
                        </div>
                        <div class="col-5">
                            <label for="stock_alert_threshold" class="form-label">Seuil alerte :</label>
                            <input type="number" name="stock_alert_threshold" id="stock_alert_threshold" class="form-control" min="0" value="<?php echo htmlspecialchars((int)($product['stock_alert_threshold'] ?? 0)); ?>">
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-4">
                        <a href="list_products.php" class="btn btn-outline-secondary">Annuler</a>
                        <button type="submit" class="btn btn-success">Enregistrer les modifications</button>
                    </div>
                </form>

                <div class="card mt-3 p-3 shadow-sm">
                    <h6 class="mb-2">Images existantes</h6>
                    <?php if (!empty($product_images)): ?>
                        <div class="existing-images d-flex gap-2 flex-wrap">
                            <?php foreach ($product_images as $pi): ?>
                                <div>
                                    <img src="<?php echo htmlspecialchars(resolveImageSrcAdmin($pi['image'])); ?>" alt="<?php echo htmlspecialchars($pi['caption'] ?? ''); ?>" class="img-thumbnail">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-muted">Aucune image disponible.</div>
                    <?php endif; ?>
                    <div class="mt-3 small text-muted">Dernière modification : <?php echo htmlspecialchars($product['updated_at'] ?? $product['created_at'] ?? '—'); ?></div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function(){
    // Add small interactive behaviour: click thumbnails to change main preview
    document.querySelectorAll('.preview-thumb').forEach(function(t){
        t.addEventListener('click', function(){
            var src = this.getAttribute('data-src');
            var main = document.getElementById('previewMain');
            if (main && src) {
                main.src = src;
            }
        });
    });

    // Basic client-side validation for positive price/stock
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e){
            var priceEl = document.getElementById('price');
            var stockEl = document.getElementById('stock');
            var price = parseFloat((priceEl && priceEl.value) || 0);
            var stock = parseInt((stockEl && stockEl.value) || 0, 10);
            if (price < 0) { alert('Le prix doit être positif ou nul.'); e.preventDefault(); return false; }
            if (stock < 0) { alert('Le stock doit être positif ou nul.'); e.preventDefault(); return false; }
        });
    }
})();
</script>

<?php include 'includes/footer.php'; ?>
</body>
</html>
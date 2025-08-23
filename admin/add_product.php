<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include 'admin_demo_guard.php';
include '../includes/db.php';
include 'includes/header.php';

$errors = [];
$success = null;

// Resize helper (kept as in original, reused in manage_product_images.php too)
function resizeImage($source, $destination, $max_width = 1400, $max_height = 1400) {
    if (!file_exists($source)) return false;
    $info = @getimagesize($source);
    if (!$info) return false;
    list($width, $height, $type) = $info;
    if ($width <= 0 || $height <= 0) return false;
    $ratio = min($max_width / $width, $max_height / $height, 1);
    $new_width = intval($width * $ratio);
    $new_height = intval($height * $ratio);

    switch ($type) {
        case IMAGETYPE_JPEG:
            if (!function_exists('imagecreatefromjpeg')) return false;
            $src_img = @imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            if (!function_exists('imagecreatefrompng')) return false;
            $src_img = @imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            if (!function_exists('imagecreatefromgif')) return false;
            $src_img = @imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    if (!$src_img) return false;

    $dst_img = imagecreatetruecolor($new_width, $new_height);
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($dst_img, false);
        imagesavealpha($dst_img, true);
        $transparent = imagecolorallocatealpha($dst_img, 255, 255, 255, 127);
        imagefilledrectangle($dst_img, 0, 0, $new_width, $new_height, $transparent);
    } else {
        $white = imagecolorallocate($dst_img, 255, 255, 255);
        imagefilledrectangle($dst_img, 0, 0, $new_width, $new_height, $white);
    }
    imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($dst_img, $destination, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($dst_img, $destination, 6);
            break;
        case IMAGETYPE_GIF:
            imagegif($dst_img, $destination);
            break;
    }
    @imagedestroy($src_img);
    @imagedestroy($dst_img);
    return file_exists($destination);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!guardDemoAdmin()) {
        $errors[] = "Action désactivée en mode démo.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = $_POST['price'] ?? '';
        $stock = $_POST['stock'] ?? '';
        $category = trim($_POST['category'] ?? '');
        $stock_alert_threshold = $_POST['stock_alert_threshold'] ?? 5;
        $images = $_FILES['images'] ?? null;

        // Validation
        if ($name === '') $errors[] = "Le nom est requis.";
        if ($description === '') $errors[] = "La description est requise.";
        if ($price === '' || !is_numeric($price) || floatval($price) < 0) $errors[] = "Le prix est invalide.";
        if ($stock === '' || !is_numeric($stock) || intval($stock) < 0) $errors[] = "Le stock est invalide.";
        if ($stock_alert_threshold === '' || !is_numeric($stock_alert_threshold) || intval($stock_alert_threshold) < 0) $stock_alert_threshold = 5;

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("INSERT INTO items (name, description, price, stock, category, stock_alert_threshold, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
                $stmt->execute([
                    $name,
                    $description,
                    round(floatval($price), 2),
                    intval($stock),
                    $category === '' ? null : $category,
                    intval($stock_alert_threshold)
                ]);
                $product_id = $pdo->lastInsertId();

                // Handle images if present
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                $saved_any = false;
                if ($images && isset($images['name']) && count($images['name']) > 0) {
                    $target_dir = __DIR__ . "/../assets/images/";
                    if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);

                    for ($i = 0; $i < count($images['name']); $i++) {
                        if (empty($images['name'][$i])) continue;
                        $tmp = $images['tmp_name'][$i] ?? null;
                        $origName = basename($images['name'][$i]);
                        $type = $images['type'][$i] ?? '';
                        $errorCode = $images['error'][$i] ?? UPLOAD_ERR_OK;

                        if ($errorCode !== UPLOAD_ERR_OK) continue;
                        if (!in_array($type, $allowed_types)) continue;

                        $ext = pathinfo($origName, PATHINFO_EXTENSION);
                        $unique = uniqid('p' . $product_id . '_') . '.' . $ext;
                        $destPath = $target_dir . $unique;

                        // Try to resize, fallback to move_uploaded_file
                        if ($tmp && is_uploaded_file($tmp)) {
                            if (resizeImage($tmp, $destPath)) {
                                $query = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
                                // position: append at end, use zero for now; admin can reorder later
                                $query->execute([$product_id, $unique, 0]);
                                $saved_any = true;
                            } else {
                                // fallback to move
                                if (@move_uploaded_file($tmp, $destPath)) {
                                    $query = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
                                    $query->execute([$product_id, $unique, 0]);
                                    $saved_any = true;
                                }
                            }
                        }
                    }
                }

                $pdo->commit();
                $_SESSION['success'] = "Produit ajouté avec succès." . ($saved_any ? " Images enregistrées." : " Aucun fichier image ajouté.");
                header("Location: list_products.php");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("add_product error: " . $e->getMessage());
                $errors[] = "Erreur serveur : " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Admin - Ajouter un produit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Shared admin styling (harmonisation avec list_products / list_orders / list_users) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
    <style>
        :root{
            --card-radius:12px;
            --muted:#6c757d;
            --bg-gradient-1:#f8fbff;
            --bg-gradient-2:#eef7ff;
            --accent:#0d6efd;
            --accent-2:#6610f2;
        }
        body.admin-page { background: linear-gradient(180deg, var(--bg-gradient-1), var(--bg-gradient-2)); }
        .panel-card {
            border-radius: var(--card-radius);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
            box-shadow: 0 12px 36px rgba(3,37,76,0.06);
            padding: 1.25rem;
        }
        .page-title h2 {
            margin:0;
            font-weight:700;
            color:var(--accent-2);
            background: linear-gradient(90deg, var(--accent), var(--accent-2));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn-round { border-radius:8px; }
        .form-card { border-radius:12px; }
        .thumb { width:56px; height:56px; object-fit:cover; border-radius:8px; box-shadow:0 8px 20px rgba(3,37,76,0.04); }
        .preview-carousel img { max-height:420px; object-fit:cover; border-radius:8px; }
        .preview-thumb { width:84px; height:84px; object-fit:cover; border-radius:8px; cursor:pointer; box-shadow:0 8px 20px rgba(3,37,76,0.04); }
        .help-note { color:var(--muted); font-size:.95rem; }
        .badge-stock { font-size:.9rem; padding:.35em .6em; border-radius:8px; }
    </style>
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Ajouter un produit</h2>
                </div>
                <div class="small text-muted">Créez un produit et ajoutez des images. La prévisualisation se met à jour côté client.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_products.php" class="btn btn-outline-secondary btn-round">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="row gx-4">
            <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h5 class="mb-3">Prévisualisation produit</h5>

                        <div class="row gx-3">
                            <div class="col-8">
                                <div id="previewMain" class="preview-carousel mb-3" aria-hidden="false">
                                    <!-- image inserted by JS -->
                                </div>

                                <div class="mb-2">
                                    <h5 id="previewTitle" class="mb-1">Nom du produit</h5>
                                    <div class="text-muted small" id="previewCategory">Catégorie</div>
                                </div>
                                <p id="previewDescription" class="text-muted">Description du produit</p>

                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div>
                                        <div class="h5 mb-0" id="previewPrice">0.00 €</div>
                                    </div>
                                    <div>
                                        <span id="previewStock" class="badge badge-stock bg-secondary">0</span>
                                    </div>
                                </div>
                            </div>

                            <div class="col-4">
                                <div id="previewThumbs" aria-label="Miniatures">
                                    <!-- thumbnails inserted by JS -->
                                </div>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h6 class="mb-2">Conseils</h6>
                        <ul class="mb-0">
                            <li>Les images sont redimensionnées automatiquement côté serveur.</li>
                            <li>Utilisez la page "Gérer les images" pour réorganiser ou éditer les légendes après création.</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <form action="add_product.php" method="post" enctype="multipart/form-data" id="addProductForm" class="card form-card shadow-sm" novalidate>
                    <div class="card-body">
                        <div class="mb-3">
                            <label for="name" class="form-label">Nom du produit</label>
                            <input id="name" name="name" class="form-control" required minlength="2" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="category" class="form-label">Catégorie</label>
                            <input id="category" name="category" class="form-control" value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="4" required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                        </div>

                        <div class="row g-2">
                            <div class="col-6">
                                <label for="price" class="form-label">Prix</label>
                                <input type="number" name="price" id="price" class="form-control" step="0.01" required value="<?php echo isset($_POST['price']) ? htmlspecialchars($_POST['price']) : '0.00'; ?>">
                            </div>
                            <div class="col-6">
                                <label for="stock" class="form-label">Stock</label>
                                <input type="number" name="stock" id="stock" class="form-control" required value="<?php echo isset($_POST['stock']) ? htmlspecialchars($_POST['stock']) : '0'; ?>">
                            </div>
                        </div>

                        <div class="mb-3 mt-2">
                            <label for="stock_alert_threshold" class="form-label">Seuil alerte</label>
                            <input type="number" name="stock_alert_threshold" id="stock_alert_threshold" class="form-control" min="0" value="<?php echo isset($_POST['stock_alert_threshold']) ? htmlspecialchars($_POST['stock_alert_threshold']) : '5'; ?>">
                        </div>

                        <div class="mb-3">
                            <label for="images" class="form-label">Ajouter des images</label>
                            <input type="file" name="images[]" id="images" class="form-control" accept="image/jpeg, image/png, image/gif" multiple>
                            <div class="form-text">Formats acceptés : JPEG, PNG, GIF. Les images seront redimensionnées.</div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <a href="list_products.php" class="btn btn-outline-secondary">Annuler</a>
                            <button type="submit" class="btn btn-primary">Ajouter</button>
                        </div>
                    </div>
                    <div class="card-footer small text-muted">
                        La prévisualisation reproduit la fiche publique — la validation finale est effectuée côté serveur.
                    </div>
                </form>
            </div>
        </div>
    </section>
</main>

<!-- Client preview & validation scripts (adapted from original, preserved logic) -->
<script>
(function () {
    // update preview helpers
    function setText(id, value, fallback) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = value ? value : (fallback || '');
    }

    function setPrice(v) {
        var p = parseFloat(v || 0).toFixed(2);
        setText('previewPrice', p + ' €');
    }

    function setStock(v) {
        var n = parseInt(v || 0, 10);
        var el = document.getElementById('previewStock');
        if (!el) return;
        el.textContent = isNaN(n) ? '0' : n;
        el.className = 'badge badge-stock ' + (n > 10 ? 'bg-success text-white' : (n > 0 ? 'bg-warning text-dark' : 'bg-danger text-white'));
    }

    // images preview: populate previewMain and previewThumbs
    function clearPreviewImages() {
        var main = document.getElementById('previewMain');
        if (main) main.innerHTML = '';
        var thumbs = document.getElementById('previewThumbs');
        if (thumbs) thumbs.innerHTML = '';
    }

    function addImageToPreview(src, index, active) {
        var main = document.getElementById('previewMain');
        if (!main) return;
        var img = document.createElement('img');
        img.src = src;
        img.alt = 'preview-' + index;
        img.style.width = '100%';
        img.style.borderRadius = '8px';
        main.appendChild(img);

        var thumbs = document.getElementById('previewThumbs');
        if (!thumbs) return;
        var t = document.createElement('img');
        t.src = src;
        t.alt = 'thumb-' + index;
        t.className = 'preview-thumb mb-2';
        t.addEventListener('click', function () {
            // scroll main into view (single image area)
            try {
                var mainEl = document.getElementById('previewMain');
                if (mainEl && mainEl.scrollIntoView) mainEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) { /* ignore */ }
        });
        thumbs.appendChild(t);
    }

    function handleFiles(files) {
        clearPreviewImages();
        if (!files || files.length === 0) {
            addImageToPreview('../assets/images/default.png', 0, true);
            return;
        }
        var count = 0;
        Array.prototype.forEach.call(files, function (file, i) {
            if (!file.type.match('image.*')) return;
            var reader = new FileReader();
            reader.onload = function (e) {
                addImageToPreview(e.target.result, count, count === 0);
                count++;
            };
            reader.readAsDataURL(file);
        });
    }

    // wire inputs
    var nameEl = document.getElementById('name');
    var descEl = document.getElementById('description');
    var priceEl = document.getElementById('price');
    var stockEl = document.getElementById('stock');
    var categoryEl = document.getElementById('category');
    var imagesEl = document.getElementById('images');

    if (nameEl) nameEl.addEventListener('input', function () { setText('previewTitle', this.value || 'Nom du produit'); });
    if (descEl) descEl.addEventListener('input', function () { setText('previewDescription', this.value || 'Description du produit'); });
    if (priceEl) priceEl.addEventListener('input', function () { setPrice(this.value); });
    if (stockEl) stockEl.addEventListener('input', function () { setStock(this.value); });
    if (categoryEl) categoryEl.addEventListener('input', function () { setText('previewCategory', this.value || ''); });

    if (imagesEl) {
        imagesEl.addEventListener('change', function () {
            handleFiles(this.files);
        });
    }

    // initialize preview from existing values
    setText('previewTitle', nameEl ? nameEl.value : 'Nom du produit', 'Nom du produit');
    setText('previewDescription', descEl ? descEl.value : 'Description du produit', 'Description du produit');
    setPrice(priceEl ? priceEl.value : 0);
    setStock(stockEl ? stockEl.value : 0);
    setText('previewCategory', categoryEl ? categoryEl.value : '');

    // set default image if no selection
    handleFiles(null);

    // client validation for images
    function validateImagesClient() {
        var el = document.getElementById('images');
        if (!el || !el.files) return true;
        var allowed = ['image/jpeg','image/png','image/gif'];
        for (var i=0;i<el.files.length;i++){
            if (allowed.indexOf(el.files[i].type) === -1) {
                return false;
            }
        }
        return true;
    }

    var form = document.getElementById('addProductForm');
    if (form) {
        form.addEventListener('submit', function (e) {
            if (!validateImagesClient()) {
                e.preventDefault();
                alert("Seuls les fichiers d'image (JPEG, PNG, GIF) sont autorisés.");
                return false;
            }
            // basic HTML5 check
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                alert('Veuillez corriger les erreurs du formulaire.');
                return false;
            }
            return true;
        }, false);
    }
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
</html>
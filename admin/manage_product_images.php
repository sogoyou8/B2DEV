<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once 'admin_demo_guard.php';
include_once '../includes/db.php';
include_once 'includes/header.php';

// Récupérer l'ID du produit
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Vérifier que le produit existe
try {
    $query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $query->execute([$product_id]);
    $product = $query->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $product = false;
    $_SESSION['error'] = "Erreur base de données : " . $e->getMessage();
}

if (!$product) {
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Récupérer les images du produit
try {
    $imgStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY position ASC");
    $imgStmt->execute([$product_id]);
    $product_images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $product_images = [];
}

// Helper : resize image (réutilisé dans add_product/manage images)
function resizeImage($source, $destination, $max_width = 1200, $max_height = 1200) {
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

// Traitement POST : suppression images
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_images_submit'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $delete_images = $_POST['delete_images'] ?? [];
    if (empty($delete_images) || !is_array($delete_images)) {
        $_SESSION['error'] = "Aucune image sélectionnée pour suppression.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $deleted_any = false;
    $failed_unlinks = [];
    foreach ($delete_images as $image_id_raw) {
        $image_id = intval($image_id_raw);
        if ($image_id <= 0) continue;
        $q = $pdo->prepare("SELECT image FROM product_images WHERE id = ?");
        $q->execute([$image_id]);
        $image = $q->fetch(PDO::FETCH_ASSOC);
        if ($image && !empty($image['image'])) {
            $paths_to_try = [
                __DIR__ . "/../assets/images/" . $image['image'],
                __DIR__ . "/../assets/images/products/" . $image['image'],
                __DIR__ . "/../assets/images/" . basename($image['image'])
            ];
            $unlinked = false;
            foreach ($paths_to_try as $file_path) {
                if (file_exists($file_path)) {
                    if (is_writable($file_path) || @chmod($file_path, 0644)) {
                        if (@unlink($file_path)) {
                            $unlinked = true;
                            break;
                        } else {
                            $failed_unlinks[] = $file_path;
                        }
                    } else {
                        $failed_unlinks[] = $file_path;
                    }
                }
            }
            // Supprimer l'enregistrement en base même si fichier absent
            $del = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
            $del->execute([$image_id]);
            $deleted_any = true;
        }
    }
    if ($deleted_any) {
        if (!empty($failed_unlinks)) {
            $_SESSION['success'] = "Images supprimées en base. Certains fichiers n'ont pas pu être supprimés (vérifier permissions) : " . implode(', ', array_slice($failed_unlinks, 0, 5)) . (count($failed_unlinks) > 5 ? '...' : '');
        } else {
            $_SESSION['success'] = "Images supprimées avec succès.";
        }
    } else {
        $_SESSION['error'] = "Aucune image supprimée (vérifier les permissions ou le chemin des fichiers).";
    }
    header("Location: manage_product_images.php?id=$product_id");
    exit;
}

// Traitement POST : ajout images
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_images_submit'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $images = $_FILES['images'] ?? null;
    if (!$images) {
        $_SESSION['error'] = "Aucun fichier reçu.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
    $current_count = count($product_images);
    $saved_any = false;

    $target_dir = __DIR__ . "/../assets/images/";
    if (!is_dir($target_dir)) @mkdir($target_dir, 0755, true);

    for ($i = 0; $i < count($images['name']); $i++) {
        if (empty($images['name'][$i])) continue;
        $image_name = basename($images['name'][$i]);
        $image_type = $images['type'][$i] ?? '';
        $errorCode = $images['error'][$i] ?? UPLOAD_ERR_OK;
        $tmp = $images['tmp_name'][$i] ?? null;

        if (!in_array($image_type, $allowed_types)) continue;
        if ($errorCode !== UPLOAD_ERR_OK) continue;
        if (!$tmp || !is_uploaded_file($tmp)) continue;

        $ext = pathinfo($image_name, PATHINFO_EXTENSION);
        $unique_name = uniqid('p' . $product_id . '_') . '.' . $ext;
        $dest = $target_dir . $unique_name;

        if (resizeImage($tmp, $dest)) {
            $ins = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
            $ins->execute([$product_id, $unique_name, $current_count + $i]);
            $saved_any = true;
        } else {
            // fallback to move
            if (@move_uploaded_file($tmp, $dest)) {
                $ins = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
                $ins->execute([$product_id, $unique_name, $current_count + $i]);
                $saved_any = true;
            }
        }
    }

    if ($saved_any) {
        $_SESSION['success'] = "Images ajoutées et optimisées avec succès.";
    } else {
        $_SESSION['error'] = "Aucune image ajoutée (format invalide ou erreur d'upload).";
    }
    header("Location: manage_product_images.php?id=$product_id");
    exit;
}

// Traitement POST : réorganisation & légendes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_order'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    if (!empty($_POST['image_order'])) {
        $order = array_filter(explode(',', $_POST['image_order']), 'strlen');
        foreach ($order as $position => $image_id) {
            $stmt = $pdo->prepare("UPDATE product_images SET position = ? WHERE id = ?");
            $stmt->execute([intval($position), intval($image_id)]);
        }
    }
    if (!empty($_POST['captions']) && is_array($_POST['captions'])) {
        foreach ($_POST['captions'] as $image_id => $caption) {
            $stmt = $pdo->prepare("UPDATE product_images SET caption = ? WHERE id = ?");
            $stmt->execute([trim((string)$caption), intval($image_id)]);
        }
    }
    $_SESSION['success'] = "Ordre et légendes enregistrés.";
    header("Location: manage_product_images.php?id=$product_id");
    exit;
}

// refresh product images after possible changes
try {
    $imgStmt = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY position ASC");
    $imgStmt->execute([$product_id]);
    $product_images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}

// Helper pour résoudre src image admin
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

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200"><rect width="100%" height="100%" fill="#f8f9fa"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" fill="#6c757d" font-family="Arial, sans-serif" font-size="16">No image</text></svg>';
    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gérer les images du produit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            -webkit-font-smoothing:antialiased;
            -moz-osx-font-smoothing:grayscale;
        }
        .panel-card {
            border-radius: var(--card-radius);
            background: linear-gradient(180deg, rgba(255,255,255,0.98), #fff);
            box-shadow: 0 12px 36px rgba(3,37,76,0.06);
            padding: 1.25rem;
        }
        .page-title { display:flex; gap:1rem; align-items:center; }
        .page-title h2 { margin:0; font-weight:700; color:var(--accent-2); background: linear-gradient(90deg,var(--accent),var(--accent-2)); -webkit-background-clip:text; background-clip: text; -webkit-text-fill-color:transparent; }
        .controls { display:flex; gap:.5rem; align-items:center; }
        .btn-round { border-radius:8px; }
        .thumb { width:120px; height:80px; object-fit:cover; border-radius:8px; box-shadow:0 8px 20px rgba(3,37,76,0.04); }
        table { width:100%; border-collapse:collapse; }
        table td, table th { padding:8px; vertical-align:middle; }
        .preview { display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap; }
        .preview img { max-width:220px; border-radius:8px; }
        .sortable-dragging { outline:2px dashed rgba(13,110,253,0.4); opacity:.9; }
        .small-muted { color:var(--muted); font-size:.95rem; }
    </style>
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Gérer les images du produit</h2>
                    <div class="small text-muted ms-2">Produit : <?php echo htmlspecialchars($product['name'] ?? '—'); ?> — ID: <?php echo (int)$product_id; ?></div>
                </div>
            </div>
            <div class="controls">
                <a href="edit_product.php?id=<?php echo $product_id; ?>" class="btn btn-outline-primary btn-sm btn-round">← Retour au produit</a>
                <a href="list_products.php" class="btn btn-outline-secondary btn-sm btn-round">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm mb-3 p-3">
                    <h5 class="mb-3">Aperçu principal</h5>
                    <div class="preview">
                        <?php
                            $primary = $product_images[0]['image'] ?? '';
                            $primarySrc = resolveImageSrcAdmin($primary);
                        ?>
                        <img id="mainPreview" src="<?php echo htmlspecialchars($primarySrc); ?>" alt="Aperçu principal">
                        <?php if (!empty($product_images)): ?>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <?php foreach ($product_images as $pi): ?>
                                    <img src="<?php echo htmlspecialchars(resolveImageSrcAdmin($pi['image'])); ?>" alt="<?php echo htmlspecialchars($pi['caption'] ?? ''); ?>" class="thumb preview-thumb" data-src="<?php echo htmlspecialchars(resolveImageSrcAdmin($pi['image'])); ?>">
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="small-muted">Aucune image ajoutée pour ce produit.</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card shadow-sm p-3">
                    <h6 class="mb-2">Conseils</h6>
                    <ul class="mb-0">
                        <li>Ajoutez des images en haute qualité ; elles seront redimensionnées côté serveur.</li>
                        <li>Glissez pour réorganiser les miniatures dans la table ci-dessous. La position 0 sera l'image principale.</li>
                    </ul>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card shadow-sm p-3 mb-3">
                    <h6 class="mb-2">Ajouter des images</h6>
                    <form action="manage_product_images.php?id=<?php echo $product_id; ?>" method="post" enctype="multipart/form-data" id="addImagesForm">
                        <input type="file" name="images[]" id="images" accept="image/jpeg, image/png, image/gif" multiple>
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" name="add_images_submit" class="btn btn-primary btn-sm">Ajouter</button>
                        </div>
                        <div class="mt-2 small text-muted">Formats acceptés : JPEG, PNG, GIF. Les images seront redimensionnées.</div>
                    </form>
                </div>

                <div class="card shadow-sm p-3">
                    <h6 class="mb-2">Actions existantes</h6>
                    <form id="orderForm" action="manage_product_images.php?id=<?php echo $product_id; ?>" method="post">
                        <table id="sortable" cellpadding="6" cellspacing="0" border="0">
                            <thead>
                                <tr>
                                    <th style="width:40px;">Suppr.</th>
                                    <th>Miniature</th>
                                    <th>Légende / Tag SEO</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($product_images): ?>
                                    <?php foreach ($product_images as $img): ?>
                                        <?php
                                            $imageId = (int)$img['id'];
                                            $imgName = $img['image'] ?? '';
                                            $imgSrc = resolveImageSrcAdmin($imgName);
                                        ?>
                                        <tr data-id="<?php echo $imageId; ?>">
                                            <td style="text-align:center;">
                                                <input type="checkbox" name="delete_images[]" value="<?php echo $imageId; ?>" id="del-<?php echo $imageId; ?>">
                                            </td>
                                            <td style="text-align:center;">
                                                <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="" width="120" style="border-radius:6px;">
                                            </td>
                                            <td>
                                                <input type="text" name="captions[<?php echo $imageId; ?>]" class="form-control" value="<?php echo htmlspecialchars($img['caption'] ?? ''); ?>" placeholder="Légende / Tag SEO">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3">Aucune image disponible.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <input type="hidden" name="image_order" id="image_order" value="">
                        <div class="mt-3 d-flex gap-2">
                            <button type="submit" name="save_order" class="btn btn-success btn-sm">Enregistrer l'ordre & légendes</button>
                            <button type="submit" name="delete_images_submit" class="btn btn-danger btn-sm" onclick="return confirm('Confirmer la suppression des images sélectionnées ?');">Supprimer la sélection</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
(function () {
    // thumbnail click switches main preview
    document.querySelectorAll('.preview-thumb').forEach(function(t){
        t.addEventListener('click', function(){
            var src = this.getAttribute('data-src');
            var main = document.getElementById('mainPreview');
            if (main && src) main.src = src;
        });
    });

    // client validation for uploaded images
    var addForm = document.getElementById('addImagesForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e){
            var el = document.getElementById('images');
            if (!el || !el.files) return;
            var allowed = ['image/jpeg','image/png','image/gif'];
            for (var i=0;i<el.files.length;i++){
                if (allowed.indexOf(el.files[i].type) === -1) {
                    alert('Seuls les fichiers d\'image (JPEG, PNG, GIF) sont autorisés.');
                    e.preventDefault();
                    return false;
                }
            }
        }, false);
    }

    // Sortable handling (load jQuery UI if necessary)
    function loadScript(url) {
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = url;
            s.async = false;
            s.onload = function () { resolve(url); };
            s.onerror = function () { reject(new Error('Failed to load ' + url)); };
            document.head.appendChild(s);
        });
    }

    function ensureLibs() {
        if (window.jQuery && typeof jQuery.fn.sortable === 'function') return Promise.resolve();
        return loadScript('https://code.jquery.com/jquery-3.6.0.min.js')
            .then(function () { if (!window.jQuery) window.jQuery = window.$; })
            .then(function () { return loadScript('https://code.jquery.com/ui/1.13.2/jquery-ui.min.js'); })
            .catch(function (err) { console.warn('Unable to load libs for sortable:', err); });
    }

    function initSortable() {
        try {
            if (typeof jQuery === 'undefined' || typeof jQuery.fn.sortable !== 'function') return;
            var $ = window.jQuery;
            try { $("#sortable tbody").sortable('destroy'); } catch(e){}
            $("#sortable tbody").sortable({
                placeholder: "sortable-dragging",
                items: "> tr",
                update: function () {
                    var order = $(this).children().map(function(){ return $(this).data('id'); }).get();
                    $("#image_order").val(order.join(','));
                },
                start: function (e, ui) { ui.item.addClass('sortable-dragging'); },
                stop: function (e, ui) { ui.item.removeClass('sortable-dragging'); }
            });
            var init = $("#sortable tbody").children().map(function(){ return $(this).data('id'); }).get();
            $("#image_order").val(init.join(','));
        } catch (e) {
            console.error('initSortable error:', e);
        }
    }

    function wireOrderFormSubmit() {
        var form = document.getElementById('orderForm');
        if (!form) return;
        form.addEventListener('submit', function () {
            try {
                if (window.jQuery && typeof jQuery.fn.sortable === 'function') {
                    var order = jQuery("#sortable tbody").children().map(function(){ return jQuery(this).data('id'); }).get();
                    document.getElementById('image_order').value = order.join(',');
                } else {
                    var rows = Array.prototype.slice.call(document.querySelectorAll('#sortable tbody tr'));
                    var order = rows.map(function(el){ return el.getAttribute('data-id'); });
                    document.getElementById('image_order').value = order.join(',');
                }
            } catch (e) { console.error('Erreur lors de préparation du formulaire:', e); }
        });
    }

    window.addEventListener('load', function () {
        ensureLibs().then(function () {
            initSortable();
            wireOrderFormSubmit();
        }).catch(function (err) { console.error('Initialisation échouée :', err); });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush(); ?>
</body>
</html>
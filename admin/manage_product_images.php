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
$product_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Vérifier que le produit existe
$query = $pdo->prepare("SELECT * FROM items WHERE id = ?");
$query->execute([$product_id]);
$product = $query->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    $_SESSION['error'] = "Produit introuvable.";
    header("Location: list_products.php");
    exit;
}

// Récupérer les images du produit
$query = $pdo->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY position");
$query->execute([$product_id]);
$product_images = $query->fetchAll(PDO::FETCH_ASSOC);

// Suppression d'images
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_images_submit'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $delete_images = $_POST['delete_images'] ?? [];
    if (empty($delete_images)) {
        $_SESSION['error'] = "Aucune image sélectionnée pour suppression.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    $deleted_any = false;
    $failed_unlinks = [];
    foreach ($delete_images as $image_id) {
        $query = $pdo->prepare("SELECT image FROM product_images WHERE id = ?");
        $query->execute([$image_id]);
        $image = $query->fetch(PDO::FETCH_ASSOC);
        if ($image && isset($image['image'])) {
            // Tentatives de chemins possibles
            $paths_to_try = [
                __DIR__ . "/../assets/images/" . $image['image'],
                __DIR__ . "/../assets/images/products/" . $image['image'],
                __DIR__ . "/../assets/images/" . basename($image['image'])
            ];
            $unlinked = false;
            foreach ($paths_to_try as $file_path) {
                if (file_exists($file_path)) {
                    // essayer unlink et enregistrer l'erreur si échoue
                    if (is_writable($file_path) || @chmod($file_path, 0644)) {
                        if (@unlink($file_path)) {
                            $unlinked = true;
                            break;
                        } else {
                            // ne break pas, continuer d'essayer d'autres chemins
                            $failed_unlinks[] = $file_path;
                        }
                    } else {
                        $failed_unlinks[] = $file_path;
                    }
                }
            }
            // Supprimer l'enregistrement en base même si fichier manquant (ou après tentative)
            $query = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
            $query->execute([$image_id]);
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

// Ajout d'images avec redimensionnement automatique
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

    function resizeImage($source, $destination, $max_width = 800, $max_height = 800) {
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

    $saved_any = false;
    for ($i = 0; $i < count($images['name']); $i++) {
        if ($images['name'][$i] == "") continue;
        $image_name = basename($images['name'][$i]);
        $image_type = $images['type'][$i];

        if (!in_array($image_type, $allowed_types)) continue;
        if ($images['error'][$i] !== UPLOAD_ERR_OK) continue;

        // Générer nom unique pour éviter collisions
        $ext = pathinfo($image_name, PATHINFO_EXTENSION);
        $unique_name = uniqid('p' . $product_id . '_') . '.' . $ext;
        $target_dir = __DIR__ . "/../assets/images/";
        if (!is_dir($target_dir)) {
            @mkdir($target_dir, 0755, true);
        }
        $target = $target_dir . $unique_name;

        if (resizeImage($images['tmp_name'][$i], $target)) {
            $query = $pdo->prepare("INSERT INTO product_images (product_id, image, position) VALUES (?, ?, ?)");
            $query->execute([$product_id, $unique_name, $current_count + $i]);
            $saved_any = true;
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

// Réorganisation et légendes/tags
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_order'])) {
    if (!guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: manage_product_images.php?id=$product_id");
        exit;
    }
    // Réorganisation
    if (!empty($_POST['image_order'])) {
        $order = array_filter(explode(',', $_POST['image_order']), 'strlen');
        foreach ($order as $position => $image_id) {
            $query = $pdo->prepare("UPDATE product_images SET position = ? WHERE id = ?");
            $query->execute([$position, intval($image_id)]);
        }
    }
    // Mise à jour des légendes/tags
    if (!empty($_POST['captions']) && is_array($_POST['captions'])) {
        foreach ($_POST['captions'] as $image_id => $caption) {
            $query = $pdo->prepare("UPDATE product_images SET caption = ? WHERE id = ?");
            $query->execute([trim($caption), intval($image_id)]);
        }
    }
    $_SESSION['success'] = "Ordre et légendes enregistrés.";
    header("Location: manage_product_images.php?id=$product_id");
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gérer les images du produit</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <style>
        .existing-images img {
            cursor: move;
            margin-right: 8px;
        }
        .caption-input {
            font-size: 0.9rem;
            margin-top: 4px;
            width: 100px;
        }
        .image-card {
            width: 120px;
        }
    </style>
    <link id="jquery-ui-css" rel="stylesheet" href="https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css">
    <!-- Note: includes/footer.php charge jQuery slim; la page ci-dessous détecte et remplace si nécessaire -->
</head>
<body>
<main class="container py-4">
    <section class="bg-light p-5 rounded shadow-sm">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h2 class="h3 mb-0 font-weight-bold">Gérer les images du produit</h2>
                <h4 class="mb-0 mt-2"><?php echo htmlspecialchars($product['name']); ?></h4>
            </div>
            <div class="d-flex gap-2">
                <!-- Bouton retour vers la page d'édition du produit -->
                <a href="edit_product.php?id=<?php echo $product_id; ?>" class="btn btn-outline-primary">
                    <i class="bi bi-arrow-left-circle"></i> Retour au produit
                </a>
                <!-- Bouton retour vers la liste des produits -->
                <a href="list_products.php" class="btn btn-outline-secondary">
                    <i class="bi bi-list"></i> Retour à la liste
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form action="manage_product_images.php?id=<?php echo $product_id; ?>" method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="images" class="form-label">Ajouter des images :</label>
                <input type="file" name="images[]" id="images" class="form-control" accept="image/jpeg, image/png, image/gif" multiple>
            </div>
            <button type="submit" name="add_images_submit" class="btn btn-primary mb-4">Ajouter</button>
        </form>

        <h4 class="mt-4">Images existantes</h4>

        <!-- Garder UN seul formulaire pour l'ordre, légendes et suppression (soumet différents boutons) -->
        <form id="orderForm" action="manage_product_images.php?id=<?php echo $product_id; ?>" method="post">
            <div class="existing-images d-flex flex-wrap" id="sortable">
                <?php if ($product_images): ?>
                    <?php foreach ($product_images as $image): ?>
                        <div class="me-2 mb-2 image-card" data-id="<?php echo $image['id']; ?>" id="img-<?php echo $image['id']; ?>">
                            <img src="<?php
                                $imgPath = '../assets/images/' . $image['image'];
                                if (!file_exists(__DIR__ . '/../assets/images/' . $image['image'])) {
                                    // essayer chemin alternatif
                                    $imgPath = '../assets/images/products/' . $image['image'];
                                }
                                echo htmlspecialchars($imgPath);
                            ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="img-thumbnail" width="100">
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="delete_images[]" value="<?php echo $image['id']; ?>" id="del-<?php echo $image['id']; ?>">
                                <label class="form-check-label" for="del-<?php echo $image['id']; ?>">Supprimer</label>
                            </div>
                            <input type="text" name="captions[<?php echo $image['id']; ?>]" value="<?php echo htmlspecialchars($image['caption'] ?? ''); ?>" class="form-control caption-input mt-1" placeholder="Légende/Tag SEO">
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Aucune image disponible.</p>
                <?php endif; ?>
            </div>
            <input type="hidden" name="image_order" id="image_order">
            <div class="mt-3">
                <button type="submit" class="btn btn-success" name="save_order">Enregistrer l’ordre & légendes</button>
                <!-- bouton suppression dans le même formulaire pour soumettre les checkboxes -->
                <button type="submit" class="btn btn-danger ms-2" name="delete_images_submit" onclick="return confirm('Confirmer la suppression des images sélectionnées ?');">Supprimer les images sélectionnées</button>
            </div>
        </form>
    </section>
</main>

<?php include 'includes/footer.php'; ?>

<!-- Charger jQuery/jQuery UI correctement même si footer a chargé jQuery slim -->
<script>
(function() {
    function loadScript(url, cb) {
        var s = document.createElement('script');
        s.src = url;
        s.async = false;
        s.onload = function() { cb(null, s); };
        s.onerror = function() { cb(new Error('Failed to load ' + url)); };
        document.head.appendChild(s);
    }

    function ensureFulljQueryAndUI(done) {
        try {
            var needFullJQ = false;
            if (typeof jQuery === 'undefined') {
                needFullJQ = true;
            } else {
                // jQuery slim removes effects/animate; jQuery UI needs jQuery.fx.step
                if (typeof jQuery.fx === 'undefined' || typeof jQuery.fx.step === 'undefined') {
                    needFullJQ = true;
                }
            }

            function loadUI(cb) {
                if (typeof jQuery === 'undefined' || typeof jQuery.fn.sortable === 'undefined') {
                    loadScript('https://code.jquery.com/ui/1.13.2/jquery-ui.min.js', function(err) {
                        if (err) return cb(err);
                        cb();
                    });
                } else {
                    cb();
                }
            }

            if (needFullJQ) {
                // Charger jQuery complet (3.6.0) et écraser window.jQuery / window.$
                loadScript('https://code.jquery.com/jquery-3.6.0.min.js', function(err) {
                    if (err) return done(err);
                    // s'assurer que global pointe vers la version complète
                    if (window.jQuery) {
                        window.$ = window.jQuery;
                    }
                    loadUI(done);
                });
            } else {
                // jQuery présent et complet, simplement charger UI si nécessaire
                loadUI(done);
            }
        } catch (e) {
            done(e);
        }
    }

    ensureFulljQueryAndUI(function(err) {
        if (err) {
            console.error('Impossible de charger jQuery/jQuery UI:', err);
            return;
        }
        (function($){
            $(function() {
                try {
                    // initialise sortable si disponible
                    if (typeof $.fn.sortable === 'function') {
                        $("#sortable").sortable({
                            update: function(event, ui) {
                                var order = $(this).sortable('toArray', { attribute: 'data-id' });
                                $("#image_order").val(order.join(','));
                            }
                        });
                        // mettre l'ordre initial si présent
                        var initOrder = $("#sortable").children().map(function(){ return $(this).data('id'); }).get();
                        $("#image_order").val(initOrder.join(','));
                    } else {
                        console.warn('jQuery UI sortable non disponible.');
                    }
                } catch (e) {
                    console.error('Erreur initialisation sortable:', e);
                }

                // Lors de la soumission, mettre à jour le champ image_order
                $("#orderForm").on('submit', function() {
                    if (typeof $.fn.sortable === 'function') {
                        var order = $("#sortable").sortable('toArray', { attribute: 'data-id' });
                        $("#image_order").val(order.join(','));
                    } else {
                        // fallback: collect order from DOM
                        var order = $("#sortable").children().map(function(){ return $(this).data('id'); }).get();
                        $("#image_order").val(order.join(','));
                    }
                });
            });
        })(jQuery);
    });
})();
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js"></script>
</body>
</html>
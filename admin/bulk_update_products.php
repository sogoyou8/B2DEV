<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once 'admin_demo_guard.php';
include_once '../includes/db.php';
include_once 'includes/header.php';

// Récupérer d'éventuelles anciennes valeurs / erreurs après POST
$old = $_SESSION['old'] ?? [];
$validationErrors = $_SESSION['errors'] ?? [];
// clear old errors/old after loading so they don't persist forever
unset($_SESSION['errors'], $_SESSION['old']);

// ============================
// Pagination / filtres (GET)
// ============================
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(200, intval($_GET['per_page'] ?? 50)));
$q = trim($_GET['q'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');

// Construire WHERE dynamique sécurisé
$whereClauses = [];
$whereParams = [];

if ($q !== '') {
    $whereClauses[] = "name LIKE ?";
    $whereParams[] = '%' . $q . '%';
}
if ($categoryFilter !== '') {
    $whereClauses[] = "category = ?";
    $whereParams[] = $categoryFilter;
}

$whereSql = '';
if (!empty($whereClauses)) {
    $whereSql = "WHERE " . implode(" AND ", $whereClauses);
}

// compter total
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM items $whereSql");
$countStmt->execute($whereParams);
$totalItems = intval($countStmt->fetchColumn());
$totalPages = max(1, (int)ceil($totalItems / $per_page));
$offset = ($page - 1) * $per_page;

// Récupération page courante
// Concaténer LIMIT/OFFSET en tant qu'entiers pour éviter les problèmes de typage des placeholders
$limitSql = ' LIMIT ' . intval($per_page) . ' OFFSET ' . intval($offset);
$query = $pdo->prepare("SELECT * FROM items $whereSql ORDER BY id" . $limitSql);
// n'utiliser que $whereParams (les valeurs LIMIT/OFFSET sont déjà injectées en int)
$query->execute($whereParams);
$products = $query->fetchAll(PDO::FETCH_ASSOC);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Supprimer sélection
    if (isset($_POST['delete_selected'])) {
        if (!guardDemoAdmin()) {
            $_SESSION['error'] = "Action désactivée en mode démo.";
            header("Location: bulk_update_products.php");
            exit;
        }
        $selected = $_POST['selected'] ?? [];
        if (empty($selected)) {
            $_SESSION['error'] = "Aucun produit sélectionné pour suppression.";
            header("Location: bulk_update_products.php");
            exit;
        }

        try {
            $pdo->beginTransaction();
            $failed_files = [];
            foreach ($selected as $id) {
                $id = intval($id);
                // Supprimer images associées (chemins possibles)
                $stmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ?");
                $stmt->execute([$id]);
                $imgs = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($imgs as $img) {
                    $paths = [
                        __DIR__ . "/../assets/images/" . $img['image'],
                        __DIR__ . "/../assets/images/products/" . $img['image'],
                        __DIR__ . "/../assets/images/" . basename($img['image'])
                    ];
                    foreach ($paths as $p) {
                        if (file_exists($p)) {
                            @unlink($p) || $failed_files[] = $p;
                        }
                    }
                }
                $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);
            }
            $pdo->commit();
            if (!empty($failed_files)) {
                $_SESSION['success'] = "Produits supprimés. Certains fichiers n'ont pas pu être supprimés : " . implode(', ', array_slice($failed_files, 0, 5)) . (count($failed_files) > 5 ? '...' : '');
            } else {
                $_SESSION['success'] = "Produits supprimés avec succès.";
            }

            // journalisation
            try {
                $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                $count = count($selected);
                $msg = "Suppression en masse : $count produit(s) supprimé(s) par $adminName";
                $stmtN = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 1)");
                $stmtN->execute(['admin_action', $msg]);
            } catch (Exception $e) {
                // ignore
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de la suppression : " . $e->getMessage();
        }
        header("Location: bulk_update_products.php");
        exit;
    }

    // Appliquer modifications sur sélection
    // Note: we accept either the standard apply_bulk submit button OR the JS-confirmed hidden flag apply_bulk_confirm=1
    if (isset($_POST['apply_bulk']) || (!empty($_POST['apply_bulk_confirm']) && $_POST['apply_bulk_confirm'] === '1')) {
        if (!guardDemoAdmin()) {
            $_SESSION['error'] = "Action désactivée en mode démo.";
            header("Location: bulk_update_products.php");
            exit;
        }
        $selected = $_POST['selected'] ?? [];
        if (empty($selected)) {
            // return error and preserve posted values
            $_SESSION['errors'] = ["Aucun produit sélectionné."];
            $_SESSION['old'] = [
                'selected' => $selected,
                'set_price' => $_POST['set_price'] ?? '',
                'set_price_percent' => $_POST['set_price_percent'] ?? '',
                'set_stock' => $_POST['set_stock'] ?? '',
                'set_stock_delta' => $_POST['set_stock_delta'] ?? '',
                'set_category' => $_POST['set_category'] ?? '',
                'set_threshold' => $_POST['set_threshold'] ?? ''
            ];
            header("Location: bulk_update_products.php");
            exit;
        }

        // Récupérer champs
        $set_price = (isset($_POST['set_price']) && $_POST['set_price'] !== '') ? trim($_POST['set_price']) : null;
        $set_price_percent = (isset($_POST['set_price_percent']) && $_POST['set_price_percent'] !== '') ? trim($_POST['set_price_percent']) : null;
        $set_stock = (isset($_POST['set_stock']) && $_POST['set_stock'] !== '') ? trim($_POST['set_stock']) : null;
        $set_stock_delta = (isset($_POST['set_stock_delta']) && $_POST['set_stock_delta'] !== '') ? trim($_POST['set_stock_delta']) : null;
        $set_category = (isset($_POST['set_category']) && $_POST['set_category'] !== '') ? trim($_POST['set_category']) : null;
        $set_threshold = (isset($_POST['set_threshold']) && $_POST['set_threshold'] !== '') ? trim($_POST['set_threshold']) : null;

        // Validation serveur : collecter toutes les erreurs
        $errors = [];

        if ($set_price !== null && $set_price_percent !== null) {
            $errors[] = "Vous ne pouvez pas renseigner à la fois 'Prix (absolu)' et 'Prix (% relatif)'. Choisissez une seule méthode.";
        }
        if ($set_stock !== null && $set_stock_delta !== null) {
            $errors[] = "Vous ne pouvez pas renseigner à la fois 'Stock (absolu)' et 'Stock (delta)'. Choisissez une seule méthode.";
        }

        // Validation type stricte basique
        if ($set_price !== null && $set_price !== '' && !is_numeric($set_price)) {
            $errors[] = "Le champ 'Prix (absolu)' doit être un nombre valide.";
        }
        if ($set_price_percent !== null && $set_price_percent !== '' && !is_numeric($set_price_percent)) {
            $errors[] = "Le champ 'Prix (% relatif)' doit être un nombre valide.";
        }
        if ($set_stock !== null && $set_stock !== '' && (!is_numeric($set_stock) || intval($set_stock) != $set_stock)) {
            $errors[] = "Le champ 'Stock (absolu)' doit être un entier.";
        }
        if ($set_stock_delta !== null && $set_stock_delta !== '' && (!is_numeric($set_stock_delta) || intval($set_stock_delta) != $set_stock_delta)) {
            $errors[] = "Le champ 'Stock (delta)' doit être un entier (peut être négatif).";
        }
        if ($set_threshold !== null && $set_threshold !== '' && (!is_numeric($set_threshold) || intval($set_threshold) != $set_threshold)) {
            $errors[] = "Le champ 'Seuil alerte stock' doit être un entier.";
        }
        if ($set_category !== null && mb_strlen($set_category) > 100) {
            $errors[] = "La catégorie est trop longue (max 100 caractères).";
        }

        if (!empty($errors)) {
            // store errors + old values and selected list to re-populate form
            $_SESSION['errors'] = $errors;
            $_SESSION['old'] = [
                'selected' => $selected,
                'set_price' => $_POST['set_price'] ?? '',
                'set_price_percent' => $_POST['set_price_percent'] ?? '',
                'set_stock' => $_POST['set_stock'] ?? '',
                'set_stock_delta' => $_POST['set_stock_delta'] ?? '',
                'set_category' => $_POST['set_category'] ?? '',
                'set_threshold' => $_POST['set_threshold'] ?? ''
            ];
            header("Location: bulk_update_products.php");
            exit;
        }

        // Normaliser liste d'IDs
        $ids = array_map('intval', array_values($selected));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Construire UPDATE set-based si possible (optimisé)
        $setParts = [];
        $values = [];

        if ($set_price !== null && $set_price !== '') {
            // prix absolu
            $setParts[] = "price = ?";
            $values[] = round(floatval($set_price), 2);
        } elseif ($set_price_percent !== null && $set_price_percent !== '') {
            // pourcentage relatif
            // utilisation d'une expression SQL utilisant la valeur existante
            $pct = floatval($set_price_percent);
            // price = ROUND(price * (1 + (? / 100)), 2)
            $setParts[] = "price = ROUND(price * (1 + (? / 100)), 2)";
            $values[] = $pct;
        }

        if ($set_stock !== null && $set_stock !== '') {
            $setParts[] = "stock = ?";
            $values[] = max(0, intval($set_stock));
        } elseif ($set_stock_delta !== null && $set_stock_delta !== '') {
            $delta = intval($set_stock_delta);
            // Utilise GREATEST pour éviter stock négatif
            $setParts[] = "stock = GREATEST(0, stock + ?)";
            $values[] = $delta;
        }

        if ($set_category !== null && $set_category !== '') {
            $setParts[] = "category = ?";
            $values[] = $set_category;
        }

        if ($set_threshold !== null && $set_threshold !== '') {
            $setParts[] = "stock_alert_threshold = ?";
            $values[] = intval($set_threshold);
        }

        try {
            $pdo->beginTransaction();
            $appliedCount = 0;

            if (!empty($setParts)) {
                // Exécuter un UPDATE unique pour tous les IDs sélectionnés
                $sql = "UPDATE items SET " . implode(', ', $setParts) . " WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $execParams = array_merge($values, $ids);
                $stmt->execute($execParams);
                $appliedCount = $stmt->rowCount();
            }

            // if no setParts (nothing to update), appliedCount stays 0
            $pdo->commit();

            // Message de succès + notification pour traçabilité
            $_SESSION['success'] = "Modifications appliquées aux produits sélectionnés. ($appliedCount modification(s) effectuée(s)).";
            try {
                $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                $msg = "Mise à jour en masse : $appliedCount modification(s) appliquée(s) par $adminName";
                $stmtN = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                $stmtN->execute(['admin_action', $msg]);
            } catch (Exception $e) {
                // ignore logging failure
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Erreur lors de la mise à jour : " . $e->getMessage();
        }
        header("Location: bulk_update_products.php");
        exit;
    }

    // Import CSV
    if (isset($_POST['import_csv'])) {
        if (!guardDemoAdmin()) {
            $_SESSION['error'] = "Action désactivée en mode démo.";
            header("Location: bulk_update_products.php");
            exit;
        }
        if (empty($_FILES['csv_file']['tmp_name'])) {
            $_SESSION['error'] = "Aucun fichier CSV uploadé.";
            header("Location: bulk_update_products.php");
            exit;
        }
        $tmp = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            $_SESSION['error'] = "Impossible d'ouvrir le fichier CSV.";
            header("Location: bulk_update_products.php");
            exit;
        }
        $header = fgetcsv($handle);
        $expected = ['id','price','stock','category'];
        // lower-case header
        $cols = array_map('strtolower', $header ?: []);
        $mapped = array_flip($cols);
        $updated = 0;
        $csvErrors = [];
        try {
            $pdo->beginTransaction();
            while (($row = fgetcsv($handle)) !== false) {
                $data = [];
                foreach ($expected as $col) {
                    $data[$col] = isset($mapped[$col]) ? $row[$mapped[$col]] : null;
                }
                $id = intval($data['id']);
                if (!$id) {
                    $csvErrors[] = "Ligne ignorée (ID invalide ou manquant).";
                    continue;
                }
                $sets = [];
                $vals = [];
                if ($data['price'] !== null && $data['price'] !== '') {
                    if (!is_numeric($data['price'])) {
                        $csvErrors[] = "ID $id : prix invalide.";
                    } else {
                        $sets[] = "price = ?";
                        $vals[] = round(floatval($data['price']), 2);
                    }
                }
                if ($data['stock'] !== null && $data['stock'] !== '') {
                    if (!is_numeric($data['stock']) || intval($data['stock']) != $data['stock']) {
                        $csvErrors[] = "ID $id : stock invalide.";
                    } else {
                        $sets[] = "stock = ?";
                        $vals[] = intval($data['stock']);
                    }
                }
                if ($data['category'] !== null && $data['category'] !== '') {
                    $sets[] = "category = ?";
                    $vals[] = $data['category'];
                }
                if (!empty($sets)) {
                    $vals[] = $id;
                    $pdo->prepare("UPDATE items SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                    $updated++;
                }
            }
            $pdo->commit();
            fclose($handle);
            if (!empty($csvErrors)) {
                $_SESSION['errors'] = $csvErrors;
            }
            $_SESSION['success'] = "Import CSV terminé. Lignes mises à jour : $updated";

            // Notification / journalisation
            try {
                $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                $msg = "Import CSV : $updated ligne(s) mise(s) à jour par $adminName";
                $stmtN = $pdo->prepare("INSERT INTO notifications (`type`, `message`, `is_persistent`) VALUES (?, ?, 0)");
                $stmtN->execute(['admin_action', $msg]);
            } catch (Exception $e) {
                // ignore
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            fclose($handle);
            $_SESSION['error'] = "Erreur import CSV : " . $e->getMessage();
        }
        header("Location: bulk_update_products.php");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Bulk Update Produits</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <style>
        .thumb { max-width:60px; max-height:60px; object-fit:cover; }
        .small-input { max-width:140px; display:inline-block; }
        .help-box { background:#f8f9fa; border:1px solid #e9ecef; padding:12px; border-radius:6px; }
        .field-help { font-size:0.9rem; color:#6c757d; display:block; margin-top:6px; }
        .preview-list { max-height: 300px; overflow:auto; }
        .pagination { margin-top:12px; }
    </style>
</head>
<body>
<main class="container py-4">
    <section class="bg-light p-4 rounded shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h4 mb-0">Mise à jour en masse des produits</h2>
            <div>
                <a href="list_products.php" class="btn btn-outline-secondary">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($validationErrors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($validationErrors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="get" class="row g-2 mb-3">
            <div class="col-auto">
                <input type="text" name="q" class="form-control" placeholder="Recherche nom..." value="<?php echo htmlspecialchars($q); ?>">
            </div>
            <div class="col-auto">
                <input type="text" name="category" class="form-control" placeholder="Catégorie..." value="<?php echo htmlspecialchars($categoryFilter); ?>">
            </div>
            <div class="col-auto">
                <select name="per_page" class="form-select">
                    <?php foreach ([10,25,50,100] as $pp): ?>
                        <option value="<?php echo $pp; ?>" <?php echo ($per_page == $pp) ? 'selected' : ''; ?>><?php echo $pp; ?> / page</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-outline-primary">Filtrer</button>
            </div>
            <div class="col-auto ms-auto">
                <!-- Bouton "Comment ça marche ?" similaire à la page de prédiction IA -->
                <a href="how_it_works.php?page=bulk_update_products" class="btn btn-outline-info">
                    <i class="bi bi-info-circle me-1"></i>Comment ça marche ?
                </a>
            </div>
        </form>

        <!-- Petit rappel en place (facultatif) — l'explication complète est accessible via le bouton ci-dessus -->
        <div class="help-box mb-3">
            <strong>Explications rapides</strong>
            <ul class="mb-0 mt-2">
                <li>Utilisez les filtres pour cibler les produits avant d'appliquer des modifications.</li>
                <li>Le bouton <em>Appliquer aux sélectionnés</em> ouvre un aperçu avant validation.</li>
                <li>Vous pouvez importer un CSV (colonnes : id,price,stock,category).</li>
                <li>Pour la documentation complète et les bonnes pratiques, cliquez sur "Comment ça marche ?".</li>
            </ul>
        </div>

        <form method="post" id="bulkForm">
            <input type="hidden" name="apply_bulk_confirm" id="apply_bulk_confirm" value="0">
            <div class="row g-2 align-items-center mb-3">
                <div class="col-auto">
                    <label class="form-label">Prix (absolu)</label>
                    <input type="number" step="0.01" name="set_price" id="set_price" class="form-control small-input" placeholder="19.99" value="<?php echo isset($old['set_price']) ? htmlspecialchars($old['set_price']) : ''; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Prix (% relatif)</label>
                    <input type="number" step="0.1" name="set_price_percent" id="set_price_percent" class="form-control small-input" placeholder="10 ou -5" value="<?php echo isset($old['set_price_percent']) ? htmlspecialchars($old['set_price_percent']) : ''; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Stock (absolu)</label>
                    <input type="number" name="set_stock" id="set_stock" class="form-control small-input" placeholder="10" value="<?php echo isset($old['set_stock']) ? htmlspecialchars($old['set_stock']) : ''; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Stock (delta)</label>
                    <input type="number" name="set_stock_delta" id="set_stock_delta" class="form-control small-input" placeholder="2 ou -1" value="<?php echo isset($old['set_stock_delta']) ? htmlspecialchars($old['set_stock_delta']) : ''; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Catégorie</label>
                    <input type="text" name="set_category" id="set_category" class="form-control small-input" placeholder="chaussure" value="<?php echo isset($old['set_category']) ? htmlspecialchars($old['set_category']) : ''; ?>">
                </div>
                <div class="col-auto">
                    <label class="form-label">Seuil alerte</label>
                    <input type="number" name="set_threshold" id="set_threshold" class="form-control small-input" placeholder="5" value="<?php echo isset($old['set_threshold']) ? htmlspecialchars($old['set_threshold']) : ''; ?>">
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="checkAll"></th>
                            <th>ID</th>
                            <th>Image</th>
                            <th>Nom</th>
                            <th>Prix</th>
                            <th>Stock</th>
                            <th>Catégorie</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $oldSelected = is_array($old['selected'] ?? null) ? array_map('intval', $old['selected']) : [];
                        foreach ($products as $p): ?>
                            <tr data-id="<?php echo (int)$p['id']; ?>" data-price="<?php echo htmlspecialchars($p['price']); ?>" data-stock="<?php echo (int)$p['stock']; ?>">
                                <td><input type="checkbox" name="selected[]" value="<?php echo $p['id']; ?>" class="row-check" <?php echo in_array((int)$p['id'], $oldSelected) ? 'checked' : ''; ?>></td>
                                <td><?php echo $p['id']; ?></td>
                                <td>
                                    <?php
                                    $img = null;
                                    $stmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                                    $stmt->execute([$p['id']]);
                                    $img = $stmt->fetchColumn();
                                    if ($img && file_exists(__DIR__ . "/../assets/images/" . $img)): ?>
                                        <img src="../assets/images/<?php echo htmlspecialchars($img); ?>" class="thumb" alt="">
                                    <?php elseif ($p['image'] && file_exists(__DIR__ . "/../assets/images/" . $p['image'])): ?>
                                        <img src="../assets/images/<?php echo htmlspecialchars($p['image']); ?>" class="thumb" alt="">
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($p['name']); ?></td>
                                <td><?php echo number_format($p['price'], 2); ?> €</td>
                                <td><?php echo intval($p['stock']); ?></td>
                                <td><?php echo htmlspecialchars($p['category']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-2">
                <button type="submit" name="apply_bulk" id="applyBtn" class="btn btn-success">Appliquer aux sélectionnés</button>
                <button type="submit" name="delete_selected" class="btn btn-danger ms-2" id="deleteBtn" onclick="return confirm('Confirmer suppression des produits sélectionnés ?')">Supprimer sélection</button>
            </div>
        </form>

        <!-- Pagination -->
        <nav aria-label="Pagination" class="pagination">
            <ul class="pagination">
                <?php
                $baseUrlParams = $_GET;
                for ($p = 1; $p <= $totalPages; $p++):
                    $baseUrlParams['page'] = $p;
                    $link = $_SERVER['PHP_SELF'] . '?' . http_build_query($baseUrlParams);
                ?>
                    <li class="page-item <?php echo $p == $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo $link; ?>"><?php echo $p; ?></a></li>
                <?php endfor; ?>
            </ul>
        </nav>

        <hr>

        <h5>Importer CSV (colonnes : id,price,stock,category)</h5>
        <form method="post" enctype="multipart/form-data" class="mt-2">
            <div class="mb-2">
                <input type="file" name="csv_file" accept=".csv" required>
            </div>
            <div>
                <button type="submit" name="import_csv" class="btn btn-primary">Importer CSV</button>
            </div>
        </form>
    </section>
</main>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-labelledby="previewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-eye"></i> Aperçu des modifications</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
      </div>
      <div class="modal-body">
        <p id="previewSummary" class="mb-2"></p>
        <div class="preview-list">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nom</th>
                        <th>Ancien prix</th>
                        <th>Nouveau prix</th>
                        <th>Ancien stock</th>
                        <th>Nouveau stock</th>
                    </tr>
                </thead>
                <tbody id="previewRows"></tbody>
            </table>
        </div>
        <div class="mt-2 text-muted small">Vérifiez l'aperçu avant de confirmer. Les champs vides ne seront pas modifiés.</div>
      </div>
      <div class="modal-footer">
        <button type="button" id="confirmApply" class="btn btn-success">Confirmer et appliquer</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
    // helper to load scripts dynamically (returns Promise)
    function loadScript(url) {
        return new Promise(function(resolve, reject){
            var s = document.createElement('script');
            s.src = url;
            s.async = true;
            s.onload = function(){ resolve(); };
            s.onerror = function(){ reject(new Error('Failed to load ' + url)); };
            document.head.appendChild(s);
        });
    }

    // Ensure bootstrap bundle is available before using bootstrap.Tooltip / Modal
    function ensureBootstrap() {
        if (typeof window.bootstrap !== 'undefined') {
            return Promise.resolve();
        }
        // try to load Bootstrap 5 bundle from CDN
        return loadScript('https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/js/bootstrap.bundle.min.js').catch(function(err){
            console.warn('Impossible de charger bootstrap bundle depuis CDN.', err);
            return Promise.resolve(); // continue, code will handle absence
        });
    }

    // Initialize page behavior
    function initBulkPage() {
        var form = document.getElementById('bulkForm');
        var inputsToWatch = ['set_price','set_price_percent','set_stock','set_stock_delta'];
        var confirmedApply = false; // flag to bypass modal on confirmed submit

        // track which submit button was clicked (for browsers that don't support event.submitter)
        var lastSubmitButton = null;
        document.addEventListener('click', function(e){
            var b = e.target;
            if (!b) return;
            if (b.closest && b.closest('form#bulkForm') && b.tagName === 'BUTTON') {
                lastSubmitButton = b;
            }
        });

        function clearAlert() {
            var existing = document.getElementById('bulkFormAlert');
            if (existing) existing.remove();
        }

        function showAlert(messages) {
            clearAlert();
            var alert = document.createElement('div');
            alert.id = 'bulkFormAlert';
            alert.className = 'alert alert-warning';
            alert.innerHTML = messages.map(function(m){ return '<div>'+m+'</div>'; }).join('');
            // insert alert directly above the form
            form.parentNode.insertBefore(alert, form);
            // scroll to alert for visibility
            window.scrollTo({ top: alert.getBoundingClientRect().top + window.scrollY - 20, behavior: 'smooth' });
        }

        // Initialize Bootstrap tooltips if available
        if (typeof window.bootstrap !== 'undefined') {
            try {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.forEach(function (el) {
                    new bootstrap.Tooltip(el);
                });
            } catch (e) {
                console.warn('Tooltips init failed', e);
            }
        } else {
            // If bootstrap not available, we'll ignore tooltips (no-op)
            console.warn('Bootstrap not present, skipping tooltip init');
        }

        // checkAll handler
        var checkAllEl = document.getElementById('checkAll');
        if (checkAllEl) {
            checkAllEl.addEventListener('change', function(e){
                document.querySelectorAll('.row-check').forEach(function(cb){ cb.checked = e.target.checked; });
            });
        }

        // Remove alert when user edits relevant fields
        inputsToWatch.forEach(function(id){
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', function(){ clearAlert(); });
        });

        // Build preview rows given selected checkboxes
        function buildPreview() {
            var selectedRows = Array.from(document.querySelectorAll('input.row-check:checked')).map(function(cb){
                var tr = cb.closest('tr');
                return tr;
            });

            if (selectedRows.length === 0) {
                return { count: 0, rows: [] };
            }

            var setPrice = (document.getElementById('set_price') || {value:''}).value.trim();
            var setPricePct = (document.getElementById('set_price_percent') || {value:''}).value.trim();
            var setStock = (document.getElementById('set_stock') || {value:''}).value.trim();
            var setStockDelta = (document.getElementById('set_stock_delta') || {value:''}).value.trim();

            var rows = selectedRows.map(function(tr){
                var id = tr.getAttribute('data-id');
                var name = tr.querySelector('td:nth-child(4)') ? tr.querySelector('td:nth-child(4)').innerText.trim() : '';
                var oldPrice = parseFloat(tr.getAttribute('data-price')) || 0;
                var oldStock = parseInt(tr.getAttribute('data-stock')) || 0;
                var newPrice = oldPrice;
                var newStock = oldStock;

                if (setPrice !== '') {
                    newPrice = parseFloat(setPrice) || newPrice;
                } else if (setPricePct !== '') {
                    var pct = parseFloat(setPricePct) || 0;
                    newPrice = +(oldPrice * (1 + (pct/100))).toFixed(2);
                }

                if (setStock !== '') {
                    newStock = Math.max(0, parseInt(setStock) || newStock);
                } else if (setStockDelta !== '') {
                    var delta = parseInt(setStockDelta) || 0;
                    newStock = Math.max(0, oldStock + delta);
                }

                return {
                    id: id,
                    name: name,
                    oldPrice: oldPrice,
                    newPrice: newPrice,
                    oldStock: oldStock,
                    newStock: newStock
                };
            });

            return { count: selectedRows.length, rows: rows };
        }

        // when submit, validate and show modal preview
        if (form) {
            form.addEventListener('submit', function(e){
                // If user already confirmed in modal, allow submission
                if (confirmedApply) return true;

                // determine which button triggered the submit
                var submitter = (typeof e.submitter !== 'undefined' && e.submitter) ? e.submiter : lastSubmitButton;
                var submitName = submitter && submitter.name ? submitter.name : null;

                // Only intercept the "apply_bulk" action to show preview.
                if (submitName !== 'apply_bulk') {
                    // allow default submit (delete / import etc.)
                    return true;
                }

                var setPrice = (document.getElementById('set_price') || {value:''}).value.trim();
                var setPricePct = (document.getElementById('set_price_percent') || {value:''}).value.trim();
                var setStock = (document.getElementById('set_stock') || {value:''}).value.trim();
                var setStockDelta = (document.getElementById('set_stock_delta') || {value:''}).value.trim();

                var errors = [];

                if (setPrice !== '' && setPricePct !== '') {
                    errors.push("Vous avez renseigné à la fois 'Prix (absolu)' et 'Prix (% relatif)'. Choisissez une seule méthode.");
                }

                if (setStock !== '' && setStockDelta !== '') {
                    errors.push("Vous avez renseigné à la fois 'Stock (absolu)' et 'Stock (delta)'. Choisissez une seule méthode.");
                }

                if (errors.length) {
                    e.preventDefault();
                    showAlert(errors);
                    return false;
                }

                // ensure at least one product selected
                var selected = document.querySelectorAll('input.row-check:checked');
                if (selected.length === 0) {
                    e.preventDefault();
                    showAlert(["Aucun produit sélectionné. Cochez au moins un produit pour appliquer les modifications."]);
                    return false;
                }

                // Build preview and show modal
                e.preventDefault();
                clearAlert();
                var preview = buildPreview();
                var previewRowsEl = document.getElementById('previewRows');
                var previewSummary = document.getElementById('previewSummary');
                previewRowsEl.innerHTML = '';
                previewSummary.innerText = preview.count + " produit(s) sélectionné(s) — aperçu des changements :";

                preview.rows.forEach(function(r){
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td>' + r.id + '</td>' +
                                   '<td>' + escapeHtml(r.name) + '</td>' +
                                   '<td>' + formatPrice(r.oldPrice) + '</td>' +
                                   '<td>' + formatPrice(r.newPrice) + '</td>' +
                                   '<td>' + r.oldStock + '</td>' +
                                   '<td>' + r.newStock + '</td>';
                    previewRowsEl.appendChild(tr);
                });

                // If bootstrap modal is available, use it; otherwise fallback to confirm()
                if (typeof window.bootstrap !== 'undefined' && typeof bootstrap.Modal === 'function') {
                    var previewModalEl = document.getElementById('previewModal');

                    // Allow closing via ESC/croix/backdrop and keep modal interactive
                    var previewModal = new bootstrap.Modal(previewModalEl, { keyboard: true, backdrop: true });

                    // Ensure any previous hidden flag is reset when modal opens
                    var flagElOnOpen = document.getElementById('apply_bulk_confirm');
                    if (flagElOnOpen) flagElOnOpen.value = '0';

                    // When modal is hidden (closed by croix/backdrop/esc), reset state
                    previewModalEl.addEventListener('hidden.bs.modal', function () {
                        var f = document.getElementById('apply_bulk_confirm');
                        if (f) f.value = '0';
                        confirmedApply = false;
                    }, { once: false });

                    // Make sure dismiss buttons also clear the hidden flag (defensive)
                    Array.from(previewModalEl.querySelectorAll('[data-bs-dismiss="modal"]')).forEach(function(btn){
                        btn.addEventListener('click', function(){
                            var f = document.getElementById('apply_bulk_confirm');
                            if (f) f.value = '0';
                        });
                    });

                    previewModal.show();

                    // Confirm button handler (clean previous handlers by replacing node)
                    var confirmBtn = document.getElementById('confirmApply');
                    var newConfirm = confirmBtn.cloneNode(true);
                    confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);
                    newConfirm.addEventListener('click', function(){
                        var f = document.getElementById('apply_bulk_confirm');
                        if (f) f.value = '1';
                        confirmedApply = true;
                        previewModal.hide();
                        // submit the form normally (will reach server-side)
                        form.submit();
                    }, { once: true });
                } else {
                    // fallback: use window.confirm
                    var ok = confirm(preview.count + " produits seront modifiés. Confirmer ?");
                    if (ok) {
                        var flag = document.getElementById('apply_bulk_confirm');
                        if (flag) flag.value = '1';
                        confirmedApply = true;
                        form.submit();
                    }
                }

                return false;
            });
        }

        function formatPrice(v) {
            return (Number(v).toFixed(2)) + ' €';
        }

        function escapeHtml(text) {
            if (!text) return '';
            return text.replace(/[&<>"'`=\/]/g, function (s) {
              return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','/':'&#x2F;','`':'&#x60;','=':'&#x3D;'}[s];
            });
        }
    }

    // On DOM ready, ensure bootstrap then init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){
            ensureBootstrap().then(initBulkPage);
        });
    } else {
        ensureBootstrap().then(initBulkPage);
    }
})();
</script>

<!-- Optionally load jQuery (if other admin pages require it) - only if absent -->
<script>
(function(){
    if (typeof window.jQuery === 'undefined') {
        var s = document.createElement('script');
        s.src = 'https://code.jquery.com/jquery-3.6.0.min.js';
        s.async = true;
        document.head.appendChild(s);
    }
})();
</script>

</body>
</html>
<?php include_once 'includes/footer.php';?>
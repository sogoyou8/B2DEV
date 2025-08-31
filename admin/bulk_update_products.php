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
require_once '../includes/classes/Product.php';
include_once 'includes/header.php';

// Récupérer d'éventuelles anciennes valeurs / erreurs après POST
$old = $_SESSION['old'] ?? [];
$validationErrors = $_SESSION['errors'] ?? [];
// clear old errors/old after loading so they don't persist forever
unset($_SESSION['errors'], $_SESSION['old']);

// Ensure $old is an array and normalize selected list to an array of ints
if (!is_array($old)) {
    $old = [];
}
$old_selected = [];
if (!empty($old['selected']) && is_array($old['selected'])) {
    // sanitize values to integers to avoid unexpected types
    $old_selected = array_map('intval', array_values($old['selected']));
}

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
$totalItems = $countStmt->fetchColumn();
$totalItems = $totalItems !== false ? intval($totalItems) : 0;
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
        if (empty($selected) || !is_array($selected)) {
            $_SESSION['error'] = "Aucun produit sélectionné pour suppression.";
            header("Location: bulk_update_products.php");
            exit;
        }

        try {
            $pdo->beginTransaction();
            $failed_files = [];
            $hardDeleted = 0;
            $softDeleted = 0;
            foreach ($selected as $id_raw) {
                $id = intval($id_raw);
                if ($id <= 0) continue;

                // Vérifier si le produit est référencé dans order_details
                $refStmt = $pdo->prepare("SELECT COUNT(*) FROM order_details WHERE item_id = ?");
                $refStmt->execute([$id]);
                $refCount = (int)$refStmt->fetchColumn();

                if ($refCount > 0) {
                    // Soft-delete : désactiver et conserver l'historique des commandes
                    $upd = $pdo->prepare("UPDATE items SET is_active = 0, deleted_at = NOW() WHERE id = ?");
                    $upd->execute([$id]);

                    // Nettoyer paniers/favoris pour éviter nouvel achat depuis le front
                    try {
                        $pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$id]);
                    } catch (Exception $_) {
                        // ignore non-fatal cleanup errors
                    }

                    $softDeleted++;
                } else {
                    // Aucun lien : suppression physique complète (images + enregistrements)
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
                                try {
                                    @unlink($p);
                                } catch (Exception $e) {
                                    $failed_files[] = $p;
                                }
                            }
                        }
                    }

                    $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM favorites WHERE item_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$id]);
                    $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$id]);

                    $hardDeleted++;
                }
            }
            $pdo->commit();

            // Construire message de succès clair
            $parts = [];
            if ($hardDeleted > 0) $parts[] = "$hardDeleted produit(s) supprimé(s)";
            if ($softDeleted > 0) $parts[] = "$softDeleted produit(s) désactivé(s) (soft-delete car liés à des commandes)";
            $msgSummary = !empty($parts) ? implode(' et ', $parts) . "." : "Aucune action effectuée.";

            if (!empty($failed_files)) {
                $_SESSION['success'] = $msgSummary . " Certains fichiers n'ont pas pu être supprimés : " . implode(', ', array_slice($failed_files, 0, 5)) . (count($failed_files) > 5 ? '...' : '');
            } else {
                $_SESSION['success'] = $msgSummary;
            }

            // journalisation
            try {
                $adminName = $_SESSION['admin_name'] ?? ($_SESSION['admin_id'] ?? 'admin');
                $countTotal = count($selected);
                $msg = "Suppression en masse : $countTotal produit(s) traité(s) par $adminName — $msgSummary";
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
        if (empty($selected) || !is_array($selected)) {
            // return error and preserve posted values
            $_SESSION['errors'] = ["Aucun produit sélectionné."];
            $_SESSION['old'] = [
                'selected' => $_POST['selected'] ?? [],
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
                'set_price' => $set_price,
                'set_price_percent' => $set_price_percent,
                'set_stock' => $set_stock,
                'set_stock_delta' => $set_stock_delta,
                'set_category' => $set_category,
                'set_threshold' => $set_threshold
            ];
            header("Location: bulk_update_products.php");
            exit;
        }

        // Normaliser liste d'IDs
        $ids = array_map('intval', array_values($selected));
        // remove invalid ids
        $ids = array_filter($ids, function($v){ return $v > 0; });
        if (empty($ids)) {
            $_SESSION['error'] = "Liste d'IDs invalide.";
            header("Location: bulk_update_products.php");
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Construire UPDATE set-based si possible (optimisé) — on exclut les changements de stock qui seront appliqués via Product::updateStock pour audit/notifications
        $setParts = [];
        $values = [];
        $applyStockAbsolute = false;
        $applyStockDelta = false;
        $stockDeltaInt = 0;

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
            // Nous n'ajoutons pas de setParts pour stock : on appliquera Product::updateStock par produit
            $applyStockAbsolute = true;
            $stockAbsoluteInt = max(0, intval($set_stock));
        } elseif ($set_stock_delta !== null && $set_stock_delta !== '') {
            $applyStockDelta = true;
            $stockDeltaInt = intval($set_stock_delta);
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
                $sql = "UPDATE items SET " . implode(', ', $setParts) . " WHERE id IN ($placeholders)";
                $stmt = $pdo->prepare($sql);
                $execVals = array_merge($values, $ids);
                $stmt->execute($execVals);
                // Count modifications for non-stock updates
                $appliedCount = $stmt->rowCount();
            } else {
                // nothing to update
                $appliedCount = 0;
            }

            // Gérer les changements de stock via Product::updateStock pour assurer logging/notifications cohérents
            if ($applyStockAbsolute || $applyStockDelta) {
                foreach ($ids as $prodId) {
                    if ($applyStockAbsolute) {
                        $newStock = $stockAbsoluteInt;
                    } else {
                        // lock current stock to compute new value
                        $curStmt = $pdo->prepare("SELECT stock FROM items WHERE id = ? FOR UPDATE");
                        $curStmt->execute([$prodId]);
                        $currStock = (int)$curStmt->fetchColumn();
                        $newStock = max(0, $currStock + $stockDeltaInt);
                    }

                    $product = new Product($pdo, $prodId);
                    $adminId = $_SESSION['admin_id'] ?? null;
                    $res = $product->updateStock($newStock, $adminId);
                    if (!$res) {
                        throw new Exception("Échec de la mise à jour du stock pour le produit ID {$prodId}.");
                    }
                    $appliedCount++;
                }
            }

            // If price changed by percentage, we may want to normalize to 2 decimals (already done in SQL with ROUND)
            // For stock changes, notifications/logs handled via Product::updateStock above.

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
            $adminId = $_SESSION['admin_id'] ?? null;

            while (($row = fgetcsv($handle)) !== false) {
                // Map columns to expected
                $mappedRow = [];
                foreach ($expected as $col) {
                    if (isset($mapped[$col]) && isset($row[$mapped[$col]])) {
                        $mappedRow[$col] = $row[$mapped[$col]];
                    } else {
                        $mappedRow[$col] = null;
                    }
                }
                $id = intval($mappedRow['id'] ?? 0);
                if ($id <= 0) {
                    $csvErrors[] = "Ignorée ligne : ID manquant ou invalide.";
                    continue;
                }

                // Prepare changes for non-stock fields (price, category)
                $sets = [];
                $vals = [];
                if ($mappedRow['price'] !== null && $mappedRow['price'] !== '') {
                    if (!is_numeric($mappedRow['price'])) {
                        $csvErrors[] = "ID $id : prix invalide.";
                    } else {
                        $sets[] = "price = ?";
                        $vals[] = round(floatval($mappedRow['price']), 2);
                    }
                }

                // Stock handled via Product::updateStock (audit/notifications)
                $stockToApply = null;
                if ($mappedRow['stock'] !== null && $mappedRow['stock'] !== '') {
                    if (!is_numeric($mappedRow['stock']) || intval($mappedRow['stock']) != $mappedRow['stock']) {
                        $csvErrors[] = "ID $id : stock invalide.";
                    } else {
                        $stockToApply = intval($mappedRow['stock']);
                    }
                }

                if ($mappedRow['category'] !== null && $mappedRow['category'] !== '') {
                    $sets[] = "category = ?";
                    $vals[] = $mappedRow['category'];
                }

                // Apply non-stock updates via UPDATE if any
                $rowUpdated = 0;
                if (!empty($sets)) {
                    $vals[] = $id;
                    $stmt = $pdo->prepare("UPDATE items SET " . implode(', ', $sets) . " WHERE id = ?");
                    $stmt->execute($vals);
                    $rowUpdated += $stmt->rowCount();
                }

                // Apply stock update via Product wrapper if requested
                if ($stockToApply !== null) {
                    $product = new Product($pdo, $id);
                    if (!$product->getId()) {
                        $csvErrors[] = "ID $id : produit introuvable pour mise à jour du stock.";
                    } else {
                        $res = $product->updateStock($stockToApply, $adminId);
                        if (!$res) {
                            throw new Exception("Échec de la mise à jour du stock via Product::updateStock pour ID {$id}.");
                        }
                        // Count stock update as one modification
                        $rowUpdated += 1;
                    }
                }

                $updated += $rowUpdated;
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
                // ignore logging failure
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
    <meta charset="utf-8">
    <title>Mise à jour en masse - Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="../assets/css/admin/bulk_update_products.css">
</head>
<body class="admin-page">
<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <div class="page-title">
                    <h2 class="h4 mb-0">Mise à jour en masse des produits</h2>
                    <div class="small text-muted">Appliquez des changements (prix, stock, catégorie, seuil) à plusieurs produits.</div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="list_products.php" class="btn btn-outline-secondary btn-sm btn-round">Retour à la liste</a>
            </div>
        </div>

        <?php if (!empty($validationErrors)): ?>
            <div class="alert alert-danger validation-errors">
                <ul class="mb-0">
                    <?php foreach ($validationErrors as $ve): ?>
                        <li><?php echo htmlspecialchars($ve); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <form method="get" class="mb-3 d-flex gap-2 align-items-center flex-wrap">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Recherche nom..." value="<?php echo htmlspecialchars($q); ?>" style="min-width:220px;">
            <input type="text" name="category" class="form-control form-control-sm" placeholder="Catégorie..." value="<?php echo htmlspecialchars($categoryFilter); ?>" style="min-width:160px;">
            <select name="per_page" class="form-select form-select-sm small-input">
                <?php foreach ([10,25,50,100] as $pp): ?>
                    <option value="<?php echo $pp; ?>" <?php echo ($per_page == $pp) ? 'selected' : ''; ?>><?php echo $pp; ?>/page</option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary">Filtrer</button>
            <div class="ms-auto text-muted small">Utilisez les filtres pour cibler les produits avant application.</div>
        </form>

        <div class="mb-3">
            <form method="post" id="bulkForm" enctype="multipart/form-data">
                <div class="mb-3">
                    <div class="alert alert-light">
                        <strong>Explications rapides</strong>
                        <ul class="mb-0">
                            <li>Utilisez les filtres pour cibler les produits avant d'appliquer des modifications.</li>
                            <li>Le bouton Appliquer aux sélectionnés ouvre une confirmation avant validation.</li>
                            <li>Vous pouvez importer un CSV (colonnes : id,price,stock,category).</li>
                        </ul>
                    </div>
                </div>

                <div class="row g-2 align-items-center">
                    <div class="col-auto small">Prix (absolu)</div>
                    <div class="col-auto"><input type="text" name="set_price" class="form-control form-control-sm" placeholder="Prix (absolu)" value="<?php echo htmlspecialchars($old['set_price'] ?? ''); ?>"></div>

                    <div class="col-auto small">Prix (% relatif)</div>
                    <div class="col-auto"><input type="text" name="set_price_percent" class="form-control form-control-sm" placeholder="Ex: 10 ou -5" value="<?php echo htmlspecialchars($old['set_price_percent'] ?? ''); ?>"></div>

                    <div class="col-auto small">Stock (absolu)</div>
                    <div class="col-auto"><input type="number" name="set_stock" class="form-control form-control-sm" placeholder="Stock (absolu)" value="<?php echo htmlspecialchars($old['set_stock'] ?? ''); ?>"></div>

                    <div class="col-auto small">Stock (delta)</div>
                    <div class="col-auto"><input type="number" name="set_stock_delta" class="form-control form-control-sm" placeholder="Stock (delta)" value="<?php echo htmlspecialchars($old['set_stock_delta'] ?? ''); ?>"></div>

                    <div class="col-auto small">Catégorie</div>
                    <div class="col-auto"><input type="text" name="set_category" class="form-control form-control-sm" placeholder="Catégorie" value="<?php echo htmlspecialchars($old['set_category'] ?? ''); ?>"></div>

                    <div class="col-auto small">Seuil alerte</div>
                    <div class="col-auto"><input type="number" name="set_threshold" class="form-control form-control-sm" placeholder="Seuil alerte" value="<?php echo htmlspecialchars($old['set_threshold'] ?? ''); ?>"></div>

                    <div class="col-auto ms-auto">
                        <button type="submit" name="apply_bulk" class="btn btn-primary btn-sm">Appliquer aux sélectionnés</button>
                    </div>
                </div>

                <div class="mt-3 table-responsive rounded shadow-sm">
                    <table class="table table-hover table-striped align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:40px;"><input type="checkbox" id="checkAll"></th>
                                <th style="width:80px;">ID</th>
                                <th>Image</th>
                                <th>Nom</th>
                                <th class="text-end">Prix</th>
                                <th>Stock</th>
                                <th>Catégorie</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Aucun produit trouvé.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $p): ?>
                                    <?php
                                        $pid = (int)$p['id'];
                                        $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                                        $imgStmt->execute([$pid]);
                                        $image = $imgStmt->fetch(PDO::FETCH_ASSOC);
                                        $imgFile = __DIR__ . "/../assets/images/" . ($image['image'] ?? '');
                                        $imgSrc = (!empty($image['image']) && file_exists($imgFile)) ? ('../assets/images/' . $image['image']) : '../assets/images/default.png';
                                    ?>
                                    <tr>
                                        <td><input type="checkbox" name="selected[]" value="<?php echo $pid; ?>" <?php echo in_array($pid, $old_selected) ? 'checked' : ''; ?>></td>
                                        <td class="fw-bold text-secondary"><?php echo $pid; ?></td>
                                        <td><img src="<?php echo htmlspecialchars($imgSrc); ?>" class="thumb" alt=""></td>
                                        <td><?php echo htmlspecialchars($p['name']); ?></td>
                                        <td class="text-end"><?php echo number_format((float)$p['price'], 2); ?> €</td>
                                        <td><?php echo (int)$p['stock']; ?></td>
                                        <td><?php echo htmlspecialchars($p['category'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div class="small text-muted">Sélectionnez des produits puis cliquez sur "Appliquer aux sélectionnés".</div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="delete_selected" class="btn btn-danger btn-sm" onclick="return confirm('Confirmer la suppression des produits sélectionnés ? Cette action est irréversible. Les produits liés à des commandes seront désactivés (soft-delete).')">Supprimer la sélection</button>
                    </div>
                </div>
            </form>
        </div>

        <hr>

        <div>
            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                <label class="mb-0">Fichier CSV: <input type="file" name="csv_file" accept=".csv" required></label>
                <button type="submit" name="import_csv" class="btn btn-sm btn-outline-primary">Importer CSV</button>
                <div class="ms-auto small text-muted">Format attendu : id,price,stock,category</div>
            </form>
        </div>
    </section>
</main>

<script>
document.getElementById('checkAll')?.addEventListener('change', function(e){
    document.querySelectorAll('input[name="selected[]"]').forEach(cb => cb.checked = e.target.checked);
});
</script>
<?php include_once 'includes/footer.php';
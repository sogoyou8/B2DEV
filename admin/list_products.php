<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
ob_start();
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header("Location: admin_login.php");
    exit;
}

include_once '../includes/db.php';
include_once 'admin_demo_guard.php';

// --- Actions (reactiver / désactiver) traitées AVANT l'inclusion du header pour permettre les redirections ---
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = (int)$_GET['id'];

    // protection mode démo
    if (!function_exists('guardDemoAdmin') || !guardDemoAdmin()) {
        $_SESSION['error'] = "Action désactivée en mode démo.";
        header("Location: list_products.php");
        exit;
    }

    try {
        if ($action === 'reactivate') {
            $stmt = $pdo->prepare("UPDATE items SET is_active = 1, deleted_at = NULL WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['success'] = "Produit réactivé avec succès.";
        } elseif ($action === 'deactivate') {
            $stmt = $pdo->prepare("UPDATE items SET is_active = 0, deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            // nettoyer le panier pour éviter nouvel achat depuis le front
            try {
                $pdo->prepare("DELETE FROM cart WHERE item_id = ?")->execute([$id]);
            } catch (Exception $_) { /* ignore */ }
            $_SESSION['success'] = "Produit désactivé avec succès.";
        } else {
            // action inconnue -> ignorer
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Erreur lors de l'action sur le produit : " . $e->getMessage();
    }

    header("Location: list_products.php");
    exit;
}

// Small helper for badge class by stock level (kept simple)
function stockBadgeClass(int $stock): string {
    if ($stock > 10) return 'bg-success text-white';
    if ($stock > 0) return 'bg-warning text-dark';
    return 'bg-danger text-white';
}

// Fetch products (same logic as before, ordered by id desc)
try {
    $stmt = $pdo->query("SELECT * FROM items ORDER BY id DESC");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $products = [];
    $_SESSION['error'] = "Erreur lors de la récupération des produits : " . $e->getMessage();
}

include_once 'includes/header.php';
?>
<script>
try { document.body.classList.add('admin-page'); } catch(e){}
</script>

<link rel="stylesheet" href="../assets/css/admin/list_products.css">

<main class="container py-4">
    <section class="panel-card mb-4">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div class="page-title">
                <h2 class="h4 mb-0">Gestion des produits</h2>
                <div class="small text-muted">Gestion centralisée et actions rapides pour votre catalogue</div>
            </div>
            <div class="controls">
                <a href="add_product.php" class="btn btn-primary btn-sm btn-round"><i class="bi bi-plus-circle me-1"></i> Ajouter un produit</a>
                <a href="bulk_update_products.php" class="btn btn-success btn-sm btn-round"><i class="bi bi-collection me-1"></i> Mise à jour en masse</a>
                <a href="list_products.php" class="btn btn-outline-secondary btn-sm btn-round">Actualiser</a>
            </div>
        </div>

        <?php if (!empty($_SESSION['error'])): ?>
            <div class="alert alert-danger text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="alert alert-success text-center shadow-sm mb-3"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm mb-3 p-3">
            <div class="d-flex gap-3 align-items-center w-100 flex-wrap">
                <div class="input-group search-box me-auto">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input id="searchInput" type="search" class="form-control" placeholder="Rechercher par nom, catégorie ou ID...">
                </div>

                <div>
                    <button id="toggleView" class="btn btn-outline-primary btn-sm btn-round" title="Basculer vue tableau / vignettes">
                        <i class="bi bi-grid-3x3-gap-fill"></i> Vignette
                    </button>
                </div>
            </div>
        </div>

        <!-- TABLE VIEW -->
        <div id="tableViewSection" class="table-responsive rounded shadow-sm mb-3">
            <table class="table table-hover table-striped align-middle mb-0" id="productsTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px;">ID</th>
                        <th>Nom</th>
                        <th>Description</th>
                        <th class="text-end">Prix</th>
                        <th style="width:100px;">Stock</th>
                        <th style="width:90px;">Image</th>
                        <th style="width:110px;">Statut</th>
                        <th style="width:320px;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">Aucun produit trouvé.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <?php
                                $pid = (int)$product['id'];
                                $pname = htmlspecialchars($product['name']);
                                $pdesc = htmlspecialchars($product['description'] ?? '');
                                $pprice = number_format((float)$product['price'], 2, '.', '');
                                $pstock = intval($product['stock']);
                                $pcategory = htmlspecialchars($product['category'] ?? '');
                                $is_active = isset($product['is_active']) ? (int)$product['is_active'] : 1;
                                $deleted_at = $product['deleted_at'] ?? null;

                                $searchText = strtolower($pid . ' ' . $pname . ' ' . $pcategory . ' ' . $pdesc);

                                // get first image (same per-product query as before)
                                $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                                $imgStmt->execute([$pid]);
                                $image = $imgStmt->fetch(PDO::FETCH_ASSOC);
                                $imgFile = __DIR__ . "/../assets/images/" . ($image['image'] ?? '');
                                $imgSrc = (!empty($image['image']) && file_exists($imgFile)) ? ('../assets/images/' . $image['image']) : '../assets/images/default.png';
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($searchText); ?>">
                                <td class="fw-bold text-secondary"><?php echo $pid; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo $pname; ?></div>
                                    <small class="text-muted"><?php echo $pcategory; ?></small>
                                </td>
                                <td><span class="text-muted small"><?php echo $pdesc ?: '—'; ?></span></td>
                                <td class="text-end"><strong><?php echo $pprice; ?> €</strong></td>
                                <td>
                                    <span class="badge <?php echo stockBadgeClass($pstock); ?>"><?php echo $pstock > 0 ? $pstock : 0; ?></span>
                                </td>
                                <td>
                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo $pname; ?>" class="thumb" />
                                </td>
                                <td>
                                    <?php if ($is_active): ?>
                                        <span class="badge bg-success">Actif</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Désactivé</span>
                                        <?php if ($deleted_at): ?>
                                            <div class="small text-muted mt-1">supprimé le <?php echo htmlspecialchars($deleted_at); ?></div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                                        <a href="edit_product.php?id=<?php echo $pid; ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil-square"></i> Modifier</a>
                                        <a href="manage_product_images.php?id=<?php echo $pid; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-images"></i> Images</a>
                                        <a href="sales_history.php?product_id=<?php echo $pid; ?>" class="btn btn-sm btn-info"><i class="bi bi-clock-history"></i> Historique</a>

                                        <?php if ($is_active): ?>
                                            <button type="button" onclick="confirmDeactivate(<?php echo $pid; ?>)" class="btn btn-sm btn-secondary"><i class="bi bi-eye-slash"></i> Désactiver</button>
                                            <button type="button" onclick="confirmDelete(<?php echo $pid; ?>)" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Supprimer</button>
                                        <?php else: ?>
                                            <button type="button" onclick="confirmReactivate(<?php echo $pid; ?>)" class="btn btn-sm btn-success"><i class="bi bi-arrow-counterclockwise"></i> Réactiver</button>
                                            <button type="button" onclick="confirmDelete(<?php echo $pid; ?>)" class="btn btn-sm btn-danger"><i class="bi bi-trash"></i> Supprimer</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- CARD / VIGNETTE VIEW (hidden by default) -->
        <div id="cardViewSection" hidden>
            <div class="row g-3">
                <?php if (!empty($products)): ?>
                    <?php foreach ($products as $product): ?>
                        <?php
                            $pid = (int)$product['id'];
                            $pname = htmlspecialchars($product['name']);
                            $pdesc = htmlspecialchars($product['description'] ?? '');
                            $pprice = number_format((float)$product['price'], 2, '.', '');
                            $pstock = intval($product['stock']);
                            $pcategory = htmlspecialchars($product['category'] ?? '');
                            $is_active = isset($product['is_active']) ? (int)$product['is_active'] : 1;

                            $imgStmt = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                            $imgStmt->execute([$pid]);
                            $image = $imgStmt->fetch(PDO::FETCH_ASSOC);
                            $imgFile = __DIR__ . "/../assets/images/" . ($image['image'] ?? '');
                            $imgSrc = (!empty($image['image']) && file_exists($imgFile)) ? ('../assets/images/' . $image['image']) : '../assets/images/default.png';

                            $searchText = strtolower($pid . ' ' . $pname . ' ' . $pcategory . ' ' . $pdesc);
                        ?>
                        <div class="col-12 col-md-6 col-lg-4" data-role="product-card" data-search="<?php echo htmlspecialchars($searchText); ?>">
                            <div class="product-card h-100 d-flex flex-column" aria-disabled="<?php echo $is_active ? 'false' : 'true'; ?>">
                                <div style="flex:0 0 auto;">
                                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo $pname; ?>" style="width:100%; height:180px; object-fit:cover; border-radius:8px; opacity: <?php echo $is_active ? '1' : '0.6'; ?>;">
                                </div>
                                <div style="flex:1; padding-top:.75rem;">
                                    <h5 class="mb-1"><?php echo $pname; ?></h5>
                                    <small class="text-muted"><?php echo $pdesc ?: 'Aucune description'; ?></small>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div>
                                        <div class="fw-bold"><?php echo $pprice; ?> €</div>
                                        <small class="text-muted"><?php echo $pcategory; ?></small>
                                    </div>
                                    <div class="text-end">
                                        <div><span class="badge <?php echo stockBadgeClass($pstock); ?>"><?php echo $pstock > 0 ? $pstock : 'Rupture'; ?></span></div>
                                    </div>
                                </div>
                                <div class="mt-3 d-flex gap-2">
                                    <a href="edit_product.php?id=<?php echo $pid; ?>" class="btn btn-sm btn-warning w-100">Modifier</a>
                                    <?php if ($is_active): ?>
                                        <button type="button" onclick="confirmDeactivate(<?php echo $pid; ?>)" class="btn btn-sm btn-secondary w-100">Désactiver</button>
                                    <?php else: ?>
                                        <button type="button" onclick="confirmReactivate(<?php echo $pid; ?>)" class="btn btn-sm btn-success w-100">Réactiver</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">Aucun produit à afficher.</div>
                <?php endif; ?>
            </div>
        </div>

    </section>
</main>

<script>
(function(){
    var input = document.getElementById('searchInput');
    var tableRows = Array.from(document.querySelectorAll('#productsTable tbody tr[data-search]'));
    var cards = Array.from(document.querySelectorAll('[data-role="product-card"]'));
    if (input) {
        input.addEventListener('input', function() {
            var q = this.value.trim().toLowerCase();
            tableRows.forEach(function(r){
                var txt = r.getAttribute('data-search') || '';
                r.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
            });
            cards.forEach(function(c){
                var txt = c.getAttribute('data-search') || '';
                c.style.display = (txt.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    const toggleBtn = document.getElementById('toggleView');
    const tableSection = document.getElementById('tableViewSection');
    const cardSection = document.getElementById('cardViewSection');
    if (toggleBtn && tableSection && cardSection) {
        toggleBtn.addEventListener('click', function() {
            const showingCards = !cardSection.hidden;
            if (showingCards) {
                cardSection.hidden = true;
                tableSection.hidden = false;
                this.innerHTML = '<i class="bi bi-grid-3x3-gap-fill"></i> Vignette';
            } else {
                cardSection.hidden = false;
                tableSection.hidden = true;
                this.innerHTML = '<i class="bi bi-list-ul"></i> Tableau';
            }
        });
    }
})();

function confirmDelete(productId) {
    if (!confirm('Êtes-vous sûr de vouloir SUPPRIMER ce produit ? Cette action est irréversible.')) return;
    // redirige vers le script delete_product.php qui gère soft-delete vs physical delete
    window.location.href = 'delete_product.php?id=' + encodeURIComponent(productId);
}

function confirmDeactivate(productId) {
    if (!confirm('Désactiver le produit empêchera sa vente mais conservera les commandes historiques. Continuer ?')) return;
    window.location.href = 'list_products.php?action=deactivate&id=' + encodeURIComponent(productId);
}

function confirmReactivate(productId) {
    if (!confirm('Réactiver ce produit le rendra à nouveau visible et achetable. Continuer ?')) return;
    window.location.href = 'list_products.php?action=reactivate&id=' + encodeURIComponent(productId);
}
</script>

<?php include 'includes/footer.php'; ?>
<?php ob_end_flush();
<?php
include 'includes/header.php';
include 'includes/db.php';

// Récupérez les produits les plus récents
$query = $pdo->query("SELECT * FROM items ORDER BY created_at DESC LIMIT 4");
$products = $query->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
html, body {
    height: 100%;
    margin: 0;
    padding: 0;
    overflow: hidden;
}
body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}
main {
    flex: 1 0 auto;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    min-height: 100vh;
    background: #fafbfc;
    margin-bottom: 32px; /* Ajoute un espace avant le footer */
}
.banner {
    flex: 0 0 10vh; /* Hauteur réduite */
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    background: #f8f9fa;
    width: 100%;
    margin-bottom: 0;
}
.banner h1 {
    font-size: 2.6rem; /* Taille réduite */
    font-weight: 700;
    margin-bottom: 0.1rem;
}
.banner p {
    font-size: 0.8rem; /* Taille réduite */
    color: #555;
    margin-bottom: 0;
}
.featured-products {
    flex: 1 0 auto;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: center;
    width: 100%;
    padding: 0;
    margin-bottom: 24px; /* Espacement supplémentaire sous le carrousel */
}
.featured-products .container {
    width: 100%;
    max-width: 900px;
    margin: 0 auto;
}
.featured-products h2 {
    font-size: 1.7rem;
    font-weight: 600;
    margin-bottom: 48px;
}
.carousel {
    background: #fff;
    border-radius: 18px;
    border: 2px solid #e0e0e0;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.12);
    padding: 1.5rem 0;
    margin-bottom: 2rem;
}
.carousel-inner {
    max-height: 40vh;
}
.carousel-item img.product-img {
    max-height: 40vh;
    object-fit: contain;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px rgba(0,0,0,0.08);
}
.carousel-caption {
    background: rgba(30,30,30,0.35);
    border-radius: 14px;
    padding: 1rem 1.5rem;
    color: #fff;
    left: 50%;
    transform: translateX(-50%);
    width: 45%; /* largeur réduite */
    bottom: 18px;
    box-shadow: 0 4px 16px rgba(0,0,0,0.10); /* ombre plus douce */
    backdrop-filter: blur(4px);
    border: 2px solid rgba(255,255,255,0.18); /* contour blanc subtil */
    transition: box-shadow 0.3s, background 0.3s;
    animation: fadeInUp 0.7s cubic-bezier(0.4,0,0.2,1);
}

@media (max-width: 768px) {
    .carousel-caption {
        width: 80%;
        font-size: 0.8rem;
        padding: 0.5rem;
    }
}

.carousel-caption h3,
.carousel-caption p {
    font-size: 1.08rem;
    margin-bottom: 0.25rem;
    text-shadow: 0 2px 8px rgba(0,0,0,0.25);
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px) translateX(-50%);}
    to   { opacity: 1; transform: translateY(0) translateX(-50%);}
}
@media (max-width: 768px) {
    .banner h1 { font-size: 1.2rem; }
    .banner p { font-size: 0.8rem; }
    .featured-products h2 { font-size: 1rem; }
    .carousel-inner { max-height: 22vh; }
    .carousel-item img.product-img { max-height: 16vh; }
    .carousel-caption { padding: 0.5rem; font-size: 0.8rem; }
}
footer {
    flex-shrink: 0;
}
</style>
<main>
    <section class="banner text-center py-3 bg-light">
        <h1 class="display-4">Bienvenue sur notre site e-commerce</h1>
        <p class="lead">Découvrez nos produits de qualité.</p>
    </section>
    <section class="featured-products py-3">
        <div class="container">
            <h2 class="text-center mb-2">Produits en avant</h2>
            <div id="carouselExampleIndicators" class="carousel slide mx-auto" data-ride="carousel" style="max-width: 800px;">
                <ol class="carousel-indicators">
                    <?php foreach ($products as $index => $product): ?>
                        <li data-target="#carouselExampleIndicators" data-slide-to="<?php echo $index; ?>" class="<?php echo $index === 0 ? 'active' : ''; ?>"></li>
                    <?php endforeach; ?>
                </ol>
                <div class="carousel-inner">
                    <?php foreach ($products as $index => $product): ?>
                        <?php
                        // Récupérer la première image du produit
                        $query = $pdo->prepare("SELECT image FROM product_images WHERE product_id = ? ORDER BY position LIMIT 1");
                        $query->execute([$product['id']]);
                        $image = $query->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="carousel-item <?php echo $index === 0 ? 'active' : ''; ?>">
                            <?php if ($image): ?>
                                <img src="assets/images/<?php echo htmlspecialchars($image['image']); ?>" class="d-block w-100 product-img" alt="<?php echo htmlspecialchars($product['name']); ?>">
                            <?php else: ?>
                                <img src="assets/images/default.png" class="d-block w-100 product-img" alt="Image par défaut">
                            <?php endif; ?>
                            <div class="carousel-caption d-none d-md-block p-3 rounded" style="background:rgba(0,0,0,0.0);">
                                <h3><?php echo htmlspecialchars($product['name']); ?></h3>
                                <p><?php echo htmlspecialchars($product['description']); ?></p>
                                <p><?php echo htmlspecialchars($product['price']); ?> €</p>
                                <a href="product_detail.php?id=<?php echo htmlspecialchars($product['id']); ?>" class="btn btn-primary">Voir le produit</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <a class="carousel-control-prev" href="#carouselExampleIndicators" role="button" data-slide="prev">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                    <span class="sr-only">Previous</span>
                </a>
                <a class="carousel-control-next" href="#carouselExampleIndicators" role="button" data-slide="next">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                    <span class="sr-only">Next</span>
                </a>
            </div>
        </div>
    </section>
</main>
<?php include 'includes/footer.php'; ?>
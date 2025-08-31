<?php
include 'includes/header.php';

// Feuille de styles spécifique à cette page
echo '<link rel="stylesheet" href="assets/css/user/about.css?v=4">';
?>
<script>
// Marqueur pour thème sombre cohérent
document.addEventListener('DOMContentLoaded', () => {
  document.body.classList.add('about-page');
});
</script>

<main class="about-main" role="main">
  <div class="about-container">
    <header class="about-header" aria-labelledby="about-title">
      <h1 id="about-title" class="hero-title">Qui sommes-nous ?</h1>
      <p class="hero-subtitle">Découvrez l'équipe derrière cette plateforme e-commerce.</p>
    </header>

    <div class="about-layout">
      <!-- Équipe -->
      <section class="about-card team-card-section" aria-labelledby="team-title">
        <h2 id="team-title" class="section-title">
          <i class="bi bi-people"></i>
          Notre Équipe
        </h2>

        <div class="team-grid">
          <article class="team-member">
            <div class="avatar" aria-hidden="true">YS</div>
            <div class="member-info">
              <h3 class="member-name">Yoann Sogoyou</h3>
              <p class="member-role">Développeur Full-Stack</p>
              <a class="member-email" href="mailto:yoann.sogoyou@ynov.com" title="Contacter Yoann">
                <i class="bi bi-envelope"></i>
                yoann.sogoyou@ynov.com
              </a>
              <!-- Portfolio ajouté -->
              <a class="member-portfolio" href="https://yoann-sogoyou.vercel.app/" target="_blank" rel="noopener noreferrer" title="Portfolio Yoann Sogoyou">
                <i class="bi bi-link-45deg"></i>
                Portfolio
              </a>
            </div>
          </article>

          <article class="team-member">
            <div class="avatar" aria-hidden="true">MP</div>
            <div class="member-info">
              <h3 class="member-name">Matthias Pollet</h3>
              <a class="member-email" href="mailto:matthias.pollet@ynov.com" title="Contacter Matthias">
                <i class="bi bi-envelope"></i>
                matthias.pollet@ynov.com
              </a>
            </div>
          </article>
        </div>
      </section>

      <!-- Mission -->
      <section class="about-card mission-section" aria-labelledby="mission-title">
        <h2 id="mission-title" class="section-title">
          <i class="bi bi-target"></i>
          Notre Mission
        </h2>
        
        <div class="mission-content">
          <p class="mission-text">
            Cette plateforme e-commerce a été développée dans le cadre de nos études pour démontrer 
            nos compétences en développement web moderne. Elle propose une expérience d'achat intuitive 
            avec des fonctionnalités avancées et un design soigné.
          </p>
          
          <p class="mission-text">
            Notre objectif est de créer une solution complète alliant performance technique et 
            expérience utilisateur optimale, en utilisant les meilleures pratiques du développement web.
          </p>
        </div>
      </section>
    </div>
  </div>
</main>
<?php include 'includes/footer.php'; ?>
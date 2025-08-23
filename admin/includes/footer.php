<?php ?>
</div>

<style>
.footer{
    padding: 6px 12px;        /* réduit l'espace vertical */
    font-size: 0.88rem;       /* texte légèrement plus petit */
    line-height: 1.1;
    background: #222;         /* conserve l'apparence sombre existante si besoin */
    color: #fff;
    text-align: center;
    box-shadow: none;
    border-top: 0;
}
.footer p {
    margin: 0;                /* supprimer margins par défaut */
    padding: 0;
}
body { margin-bottom: 0; }
</style>

<footer class="footer">
    <p>&copy; <?php echo date("Y"); ?> E-commerce Dynamique. Tous droits réservés.</p>
</footer>

<script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // JavaScript générique pour toutes les pages admin
    console.log('🔧 Footer admin chargé');
    
    // Animation générale pour les boutons
    const buttons = document.querySelectorAll('.btn');
    buttons.forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.02)';
        });
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
});
</script>
</body>
</html>
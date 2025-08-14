# TODO - Finalisations
## PHASE 1 - Notifications ✅
- [x] Structure pages admin
- [x] mark_notification_read.php
- [x] Tests scénarios

## PHASE 2 - API REST (En cours)
- [ ] API notifications
- [ ] API stock  
- [ ] JavaScript intégration

## PHASE 3 - POO
- [ ] Classes Product, Notification
- [ ] Refactoring architecture

## PHASE 4 - Documentation
- [ ] README.md
- [ ] Présentation finale








# B2DEV

- Améliore le design ou l’ergonomie si tu le souhaites.
- Mettre en avant la prédiction (algorithme avancé)

## USER

- Historique

## ADMIN

- Dans le dashboard ameliorer les boutons comme pour l'historique et voir la technologie utiliser pour peut etre ameliorer le reste du code avec.

- Filtrer par période : Ajoute un formulaire en haut de la page sales_history.php pour filtrer par dates.
- Export CSV : Ajoute un bouton pour exporter l’historique des ventes au format CSV.

- ## ADMIN - Notifications et Dashboard

- [ ] Vérifier que chaque action critique (stock à 0, sécurité, erreur grave) crée une notification persistante dans la table `notifications` (`is_persistent = 1`).
- [ ] Tester le bouton "Marquer comme lue" pour les notifications importantes (lien vers `mark_notification_read.php`).
- [ ] Vérifier que l’alerte de stock faible s’affiche bien pour tous les produits sous le seuil (dans `notifications.php`).
- [ ] Vérifier que l’alerte "commandes en attente" s’affiche si besoin.
- [ ] Utiliser systématiquement `$_SESSION['success']`, `$_SESSION['error']`, etc. pour tous les retours d’action admin (ajout, suppression, modification…).
- [ ] Utiliser `$_SESSION['toast'][]` pour les notifications temporaires (succès, erreur rapide…).
- [ ] Tester l’affichage des toasts (Toastr) sur toutes les pages admin.
- [ ] Inclure `notifications.php` juste après le header dans **toutes** les pages admin.
- [ ] Supprimer les alertes dupliquées dans les autres fichiers (ne garder que l’inclusion de `notifications.php`).
- [ ] Vérifier que toutes les alertes et feedbacks sont bien centralisés et visibles.
- [ ] Vérifier que la page `notifications.php` (ou `notifications_center.php`) liste bien toutes les notifications (lues et non lues).
- [ ] Ajouter si besoin un bouton "Tout marquer comme lu" ou des filtres (par type, date…).
- [ ] Générer une notification de sécurité en cas de tentative de connexion admin échouée ou action sensible.
- [ ] Tester l’affichage de ces alertes.
- [ ] Vérifier l’affichage responsive des alertes et toasts.
- [ ] Vérifier la présence de l’icône cloche dans le header admin avec le badge du nombre de notifications non lues.
- [ ] Tester tous les scénarios (stock, commandes, suppression, modification, sécurité…).
- [ ] Vérifier que toutes les notifications sont bien affichées, marquées comme lues, et supprimées des sessions si besoin.Lien depuis la fiche produit : Sur la page de détail d’un produit en admin, ajoute un bouton "Voir l’historique des ventes de ce produit".










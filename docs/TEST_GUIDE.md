# Guide de Test - Gestionnaire de Mots de Passe Sécurisé

## Tests Automatisés

### Test 1: Connexion à la base de données ✓
- Statut: RÉUSSI
- Résultat: Connexion PDO réussie, toutes les tables créées avec la bonne structure
- Tables: utilisateurs, entrees, journal_actions, tentatives_login
- Colonnes TOTP ajoutées: totp_secret, totp_active, totp_pending

---

## Tests Manuelles (Navigateur)

### Préparation
1. S'assurer qu'Apache et MySQL sont démarrés (services natifs)
2. Accéder à: `http://localhost/projet_ameliore/pages/register.php`

### Test 2: Inscription d'un nouvel utilisateur
1. Aller sur `http://localhost/projet_ameliore/pages/register.php`
2. Remplir le formulaire avec:
   - Email: `test@example.com`
   - Mot de passe: `MotDePasseTest123!`
   - Confirmation: `MotDePasseTest123!`
3. Cliquer sur "S'inscrire"
4. **Attendu**: Redirection vers le dashboard avec message de succès
5. **Vérifier**: L'utilisateur est créé dans la base de données

### Test 3: Connexion avec mot de passe correct
1. Aller sur `http://localhost/projet_ameliore/pages/login.php`
2. Entrer:
   - Email: `test@example.com`
   - Mot de passe: `MotDePasseTest123!`
3. Cliquer sur "Se connecter"
4. **Attendu**: Redirection vers le dashboard
5. **Vérifier**: La session est active, l'email est affiché

### Test 4: Connexion avec mot de passe incorrect
1. Se déconnecter d'abord
2. Aller sur `http://localhost/projet_ameliore/pages/login.php`
3. Entrer:
   - Email: `test@example.com`
   - Mot de passe: `MauvaisMotDePasse`
4. Cliquer sur "Se connecter"
5. **Attendu**: Message d'erreur "Identifiants invalides"
6. **Vérifier**: Toujours sur la page de connexion

### Test 5: Ajout d'une entrée dans le dashboard
1. Être connecté
2. Cliquer sur "Ajouter" dans la navigation
3. Remplir le formulaire:
   - Catégorie: "Réseaux sociaux"
   - Site: `facebook.com`
   - Identifiant: `testuser`
   - Mot de passe: `TestPassword123!`
4. Cliquer sur "Enregistrer"
5. **Attendu**: Redirection vers le dashboard, entrée affichée
6. **Vérifier**: L'entrée apparaît dans la liste

### Test 6: Modification d'une entrée existante
1. Sur le dashboard, cliquer sur "Modifier" sur une entrée
2. Changer le mot de passe: `NouveauMotDePasse456!`
3. Cliquer sur "Enregistrer"
4. **Attendu**: Redirection vers le dashboard
5. **Vérifier**: L'entrée est mise à jour

### Test 7: Suppression d'une entrée
1. Sur le dashboard, cliquer sur "Supprimer" sur une entrée
2. Confirmer la suppression
3. **Attendu**: Redirection vers le dashboard
4. **Vérifier**: L'entrée n'apparaît plus

### Test 8: Détection mot de passe faible (jauge)
1. Aller sur la page "Ajouter"
2. Dans le champ mot de passe, entrer: `abc`
3. **Attendu**: Jauge rouge affichée "Très faible"
4. Entrer: `MotDePasse`
5. **Attendu**: Jauge orange/jaune affichée "Moyen"
6. Entrer: `MotDePasseComplexe123!@#`
7. **Attendu**: Jauge verte affichée "Très fort"

### Test 9: Détection doublon mot de passe
1. Ajouter une entrée avec mot de passe: `MotDePasseUnique123!`
2. Ajouter une autre entrée avec le même mot de passe
3. **Attendu**: Avertissement "Ce mot de passe est déjà utilisé"

### Test 10: Export chiffré des entrées
1. Cliquer sur "Sauvegarde" dans la navigation
2. Cliquer sur "Télécharger la sauvegarde"
3. **Attendu**: Téléchargement d'un fichier JSON chiffré
4. **Vérifier**: Le fichier contient les métadonnées et le payload chiffré

### Test 11: Import chiffré des entrées
1. Sur la page Sauvegarde, utiliser le fichier exporté
2. Cliquer sur "Importer"
3. **Attendu**: Message de succès, entrées importées
4. **Vérifier**: Les entrées apparaissent dans le dashboard

### Test 12: Affichage du journal d'actions
1. Cliquer sur "Journal" dans la navigation
2. **Attendu**: Liste des actions récentes affichée
3. **Vérifier**: Les actions (inscription, connexion, ajout, etc.) sont journalisées

### Test 13: Activation TOTP
1. Cliquer sur "TOTP" dans la navigation
2. Scanner le QR code avec Google Authenticator
3. Entrer le code à 6 chiffres
4. Cliquer sur "Activer TOTP"
5. **Attendu**: Redirection vers le dashboard, TOTP activé

### Test 14: Connexion avec TOTP activé
1. Se déconnecter
2. Se connecter avec identifiants corrects
3. **Attendu**: Redirection vers la page de vérification TOTP
4. Entrer le code TOTP
5. **Attendu**: Connexion réussie vers le dashboard

### Test 15: Désactivation TOTP
1. Cliquer sur "TOTP" dans la navigation
2. Entrer le code TOTP actuel
3. Cliquer sur "Désactiver TOTP"
4. **Attendu**: Redirection vers le dashboard, TOTP désactivé

### Test 16: Changement mot de passe maître
1. Cliquer sur "Mot de passe maître" dans la navigation
2. Entrer l'ancien mot de passe
3. Entrer le nouveau mot de passe
4. Cliquer sur "Changer"
5. **Attendu**: Redirection vers le dashboard
6. **Vérifier**: Se reconnecter avec le nouveau mot de passe

### Test 17: Vérification headers de sécurité (CSP)
1. Ouvrir les outils de développement du navigateur (F12)
2. Aller dans l'onglet "Network"
3. Recharger une page
4. Cliquer sur la requête principale
5. Vérifier les Response Headers:
   - Content-Security-Policy ne contient pas 'unsafe-inline'
   - X-Frame-Options: DENY
   - X-Content-Type-Options: nosniff
   - Strict-Transport-Security

### Test 18: Extension Chrome - Détection champs
1. Charger l'extension dans Chrome (chrome://extensions)
2. Aller sur un site avec formulaire de connexion (ex: facebook.com)
3. Cliquer sur un champ mot de passe
4. **Attendu**: Bouton orange "MDP" apparaît
5. Cliquer sur le bouton
6. **Attendu**: Panneau de suggestion s'affiche

---

## Résultats des Tests

| Test | Statut | Notes |
|------|--------|-------|
| Test 1: DB Connection | ✓ RÉUSSI | Toutes les tables OK |
| Test 2: Inscription | ⏳ À faire | |
| Test 3: Connexion OK | ⏳ À faire | |
| Test 4: Connexion KO | ⏳ À faire | |
| Test 5: Ajout entrée | ⏳ À faire | |
| Test 6: Modification | ⏳ À faire | |
| Test 7: Suppression | ⏳ À faire | |
| Test 8: Jauge force | ⏳ À faire | |
| Test 9: Doublon MDP | ⏳ À faire | |
| Test 10: Export | ✓ RÉUSSI | Fonctionne correctement |
| Test 11: Import | ⏳ À faire | |
| Test 12: Journal | ⏳ À faire | |
| Test 13: TOTP activer | ⏳ À faire | |
| Test 14: TOTP login | ⏳ À faire | |
| Test 15: TOTP désactiver | ⏳ À faire | |
| Test 16: Change MDP | ⏳ À faire | |
| Test 17: Headers | ⏳ À faire | |
| Test 18: Extension | ⏳ À faire | |

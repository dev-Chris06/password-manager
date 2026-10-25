# Guide de test — Gestionnaire de mots de passe

Ce guide décrit les vérifications manuelles et automatisées. Aucun résultat n'est présumé réussi : consigne la date, la version de PHP et le résultat après chaque exécution.

## Environnement

- PHP 8.3 minimum, extensions requises indiquées dans [INSTALL.md](../INSTALL.md), et MySQL/MariaDB.
- Pour les essais navigateur, configure une base locale réservée aux tests et un fichier `.env` correspondant.
- Dans une base contenant déjà un compte, l'inscription supplémentaire est désactivée par défaut. Pour un essai manuel, utilise une base isolée ou active temporairement `ALLOW_ADDITIONAL_REGISTRATIONS=true` dans `.env`.
- L'application locale peut être lancée depuis la racine du dépôt avec :

```bash
php -S localhost:8000 -t public/ public/router.php
```

Ouvre ensuite `http://localhost:8000`.

## Tests automatisés PHPUnit

La suite se trouve dans `tests/` et comprend `CryptoTest.php`, `AuthIntegrationTest.php` et `RateLimitTest.php`. Elle peut être lancée avec :

```bash
php vendor/bin/phpunit --testdox
```

**Attention à la base de test :** les suites d'intégration suppriment, recréent puis suppriment la base MySQL nommée `password_manager_test`. Elles lisent les paramètres de connexion depuis `.env`. Ne lance-les que si cette base est dédiée aux tests et si sa suppression est acceptable.

## Vérifications manuelles dans le navigateur

### Comptes et session

1. Ouvrir `http://localhost:8000/pages/register.php` sur une base locale isolée.
2. Créer un compte de test avec un mot de passe maître d'au moins 12 caractères.
3. Vérifier la redirection vers le tableau de bord et la création du compte.
4. Se déconnecter, puis se connecter avec le bon mot de passe; vérifier l'accès au tableau de bord.
5. Recommencer avec un mauvais mot de passe; vérifier que l'accès est refusé et qu'un message générique s'affiche.
6. Vérifier les protections de session en se reconnectant et en contrôlant le renouvellement de l'identifiant de session.

### Entrées du coffre

7. Ajouter une entrée de test, vérifier qu'elle apparaît, puis la modifier et la supprimer.
8. Vérifier l'indicateur de robustesse du mot de passe sur quelques valeurs de test.
9. Ajouter un mot de passe déjà utilisé et vérifier l'avertissement de réutilisation ainsi que la demande de confirmation explicite.
10. Exporter le coffre après saisie du mot de passe maître actuel; vérifier que le fichier exporté est chiffré.
11. Importer ce fichier dans un environnement de test et vérifier les entrées réimportées.
12. Ouvrir le journal d'actions et vérifier qu'il ne contient pas de mot de passe en clair.
13. Changer le mot de passe maître et vérifier qu'une nouvelle connexion fonctionne et que les entrées restent déchiffrables.

### En-têtes HTTP

14. Dans les outils développeur du navigateur, vérifier sur une page PHP les en-têtes `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff` et `Referrer-Policy`. La CSP actuelle autorise `unsafe-inline` pour les styles, mais pas dans `script-src`; vérifier aussi la présence du nonce de script. Vérifier HSTS uniquement via HTTPS, car les navigateurs ignorent cette politique sur HTTP.

### Extension Chrome

15. Charger `extension-chrome/` en mode développeur, configurer l'URL locale du gestionnaire dans le popup et se connecter dans le même profil. Sur une page de test contenant un formulaire, ouvrir le popup, vérifier la détection du champ, choisir une entrée et confirmer que les champs sont remplis. Tester aussi la suggestion sur un formulaire d'inscription et l'enregistrement dans le coffre. Ces scénarios doivent être vérifiés dans Chrome; ils ne sont pas validés par une suite automatisée du dépôt.

## Journal des résultats

Inscrire les résultats après exécution réelle; laisser « À exécuter » tant qu'aucune vérification n'a été faite.

| Groupe | Résultat actuel | Date / environnement |
|---|---|---|
| PHPUnit | Réussi : 13 tests, 53 assertions | 2026-10-25 · PHP 8.5.12 |
| Tests manuels 1 à 13 | À exécuter | — |
| Vérification des en-têtes | À exécuter | — |
| Extension : remplissage/suggestion | À exécuter dans Chrome | — |

# Limitation des tentatives

Configuration : [`config/packages/rate_limiter.yaml`](../config/packages/rate_limiter.yaml) — logique : [`src/Security/LoginThrottler.php`](../src/Security/LoginThrottler.php).

## Principes

- **Fenêtres glissantes superposées** (une courte, une longue) au lieu de blocages à durée croissante : simples, standard (`symfony/rate-limiter`) et efficaces.
- **Aucune pause côté serveur** : un compte limité répond immédiatement `429` avec `Retry-After`. Des pauses (`sleep`) permettraient de saturer le serveur.
- **Seuls les échecs comptent** pour la connexion ; une réussite remet à zéro les limites de la paire IP + email et de l'appareil.
- **Stockage** : cache sur fichiers et verrous `flock` dans `var/`, compatibles avec un hébergement mutualisé.

## Limites

| Limiteur | Clé | Fenêtre courte | Fenêtre longue | Quand |
|---|---|---|---|---|
| Paire | IP + email | 5 / 15 min | 20 / 24 h | Connexion sans device cookie |
| IP | adresse IP | 30 / h | 100 / 24 h | Connexion sans device cookie |
| Email | email (haché) | 10 / h | 30 / 24 h | Connexion sans device cookie |
| Appareil | device cookie | 10 / 15 min | — | Connexion avec device cookie valide |
| Second facteur | compte | 5 / 15 min | — | Codes TOTP et codes de secours |
| Re-authentification | compte | 5 / 15 min | — | Activation 2FA, régénération des codes de secours, changement du mot de passe maître |
| Inscription | IP | 5 / h | — | Création du compte |
| Prelogin | IP | 60 / h | — | Paramètres de dérivation |

## Device cookie (OWASP)

Après une connexion **complète** (deux facteurs), le navigateur reçoit `__Host-pm_device` : un nonce aléatoire et son HMAC lié à l'email. Seul le SHA-256 du nonce est stocké.

Avec un device cookie valide, les limites par IP et par email ne s'appliquent pas, seule la limite par appareil compte. Un attaquant qui multiplie les échecs sur l'email du propriétaire depuis d'autres adresses ne peut donc pas le bloquer. Le device cookie **ne dispense jamais du second facteur**.

Les device cookies sont révoqués à chaque changement de mot de passe maître et oubliés après 180 jours d'inactivité (`app:purge`).

## Second facteur

Cinq codes faux ferment la connexion en cours : il faut renvoyer la preuve d'authentification (et donc refaire le calcul Argon2id) avant de réessayer. Le second facteur doit être fourni dans les 5 minutes qui suivent la preuve.

## Proxies

Derrière un proxy inverse, déclarer son adresse dans `TRUSTED_PROXIES`, sinon toutes les requêtes sembleraient venir de la même IP. Sans proxy, laisser vide : les en-têtes `X-Forwarded-For` sont ignorés.

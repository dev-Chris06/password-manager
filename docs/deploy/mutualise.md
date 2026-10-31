# Déploiement sur un hébergement mutualisé

Référence : **Alwaysdata** (PHP 8.3+, MariaDB ou MySQL, SSH, cron, HTTPS fourni). La démarche est la même chez tout hébergeur qui offre un accès SSH et permet de choisir la racine web.

> Aucun Node.js n'est nécessaire sur le serveur : l'archive produite par la CI contient déjà le JavaScript compilé et les dépendances PHP de production.

## Prérequis

| Élément | Exigence |
|---|---|
| PHP | **8.3.36+, 8.4.27+ ou 8.5.12+** ([ADR-017](../DECISIONS.md#adr-017--niveau-de-correctifs-php-imposé)), extensions `ctype`, `iconv`, `mbstring`, `openssl`, `pdo_mysql`, `sodium` |
| Base de données | MySQL 8.0 ou MariaDB 10.11+ / 11.4 |
| HTTPS | Obligatoire (le cookie de session `__Host-` l'exige) |
| Accès | SSH, tâche planifiée (cron) |
| Racine web | Doit pouvoir pointer sur le dossier `public/` |

## 1. Préparer le compte

1. Créer une **base de données** et un **utilisateur dédié** ayant tous les droits sur cette seule base.
2. Créer un **site** de type « PHP » avec la version de PHP la plus récente proposée, puis vérifier le correctif :

   ```bash
   php -v    # doit afficher au moins 8.3.36 / 8.4.27 / 8.5.12
   ```

   Certains hébergeurs appliquent les correctifs sans changer le numéro de version : en cas de doute, demander confirmation au support.
3. Activer le **certificat HTTPS** (Let's Encrypt) et la redirection HTTP → HTTPS.

## 2. Installer la version

Télécharger l'archive `password-manager-<version>.tar.gz` et son `.sha256` (artefact « release » de la CI GitHub), puis :

```bash
ssh compte@ssh-compte.alwaysdata.net
mkdir -p ~/password-manager/releases ~/password-manager/shared/var/log
cd ~/password-manager
sha256sum -c password-manager-<version>.tar.gz.sha256
```

Créer `~/password-manager/shared/.env.local` (permissions `600`) :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<résultat de : php -r 'echo bin2hex(random_bytes(32));'>
DATABASE_URL="mysql://UTILISATEUR:MOT_DE_PASSE@mysql-compte.alwaysdata.net:3306/BASE?serverVersion=10.11.0-MariaDB&charset=utf8mb4"
DEFAULT_URI=https://coffre.exemple.fr
TRUSTED_PROXIES=
EXTENSION_ORIGIN=chrome-extension://nfnbankmkanaglimeogpgljgpkpdbenl
```

```bash
chmod 600 shared/.env.local
APP_DIR=$HOME/password-manager bash deploy/deploy.sh password-manager-<version>.tar.gz
```

Le script `deploy/deploy.sh` (présent dans l'archive) :
1. vérifie la somme de contrôle ;
2. extrait la version dans `releases/<date>` et y relie `.env.local` et `var/` partagés ;
3. vide le cache et applique les migrations ;
4. lance `app:check-deployment`, qui **arrête le déploiement** si PHP n'est pas assez récent, si `session.upload_progress` est actif, si `APP_SECRET` est faible, si la base est injoignable ou si des migrations manquent ;
5. bascule le lien `current` vers la nouvelle version (sans coupure) et garde les cinq dernières.

> Sur un mutualisé, `app:check-deployment` lit la configuration PHP de la ligne de commande. Les réglages du serveur web sont fixés par `public/.user.ini` (`session.upload_progress.enabled = 0`, `expose_php = Off`), pris en compte par PHP-FPM.

## 3. Configurer le site

| Paramètre | Valeur |
|---|---|
| Racine web | `~/password-manager/current/public` |
| PHP | 8.3+ corrigé |
| HTTPS | Forcé |

`public/.htaccess` redirige tout vers `index.php` et refuse les fichiers cachés. Un `.htaccess` à la racine du projet refuse tout, au cas où la racine web serait mal réglée.

## 4. Tâche planifiée

Dans l'interface de l'hébergeur, une tâche quotidienne :

```bash
cd ~/password-manager/current && php bin/console app:purge --env=prod --no-interaction
```

## 5. Vérifications après installation

- [ ] `https://coffre.exemple.fr` affiche l'écran de création du coffre.
- [ ] `https://coffre.exemple.fr/.env.local` et `/vendor/autoload.php` répondent 403 ou 404.
- [ ] [securityheaders.com](https://securityheaders.com) et [Mozilla Observatory](https://developer.mozilla.org/en-US/observatory) donnent **A+**.
- [ ] Créer le compte, activer la double authentification, **imprimer le kit de secours**.
- [ ] Exporter une sauvegarde et la ranger hors du serveur.

## Mise à jour

Télécharger la nouvelle archive et relancer `deploy/deploy.sh` (depuis la nouvelle archive). Revenir en arrière : faire pointer `current` vers la version précédente de `releases/`.

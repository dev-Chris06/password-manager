# Déploiement sur un VPS (sans Docker)

Cible : **Debian 12 ou 13** avec Caddy (HTTPS automatique), PHP-FPM et MariaDB, préparés par le script `deploy/provision.sh`.

> Vous administrez la machine : mises à jour, pare-feu, sauvegardes et surveillance sont de votre responsabilité. Le script automatise la mise en place, pas la vigilance.

## Prérequis

- Un VPS Debian 12/13 avec un accès SSH par clé (désactiver la connexion par mot de passe).
- Un nom de domaine dont l'enregistrement DNS `A`/`AAAA` pointe vers le VPS.

## 1. Préparer la machine

Copier le dossier `deploy/` sur le serveur, **le relire**, puis :

```bash
sudo DOMAIN=coffre.exemple.fr PHP_VERSION=8.3 bash deploy/provision.sh
```

Le script est idempotent (il peut être relancé). Il installe et configure :

| Élément | Configuration |
|---|---|
| Mises à jour | `unattended-upgrades` (correctifs de sécurité automatiques) |
| PHP | Dépôt `packages.sury.org`, qui suit les publications de sécurité de PHP |
| Pare-feu | `nftables` : seuls SSH (22), HTTP (80) et HTTPS (443) sont ouverts |
| Utilisateur | `pm-app`, sans shell, propriétaire de `/srv/password-manager` |
| Base | MariaDB, base `pm_vault`, compte `pm_app` limité à cette base, mot de passe aléatoire |
| Secrets | `/srv/password-manager/shared/.env.local` (`600`), `APP_SECRET` aléatoire |
| PHP-FPM | Pool dédié (`deploy/php-fpm-pool.conf`) : socket privé, `open_basedir`, fonctions d'exécution désactivées, `session.upload_progress` désactivé |
| Caddy | `deploy/Caddyfile` : HTTPS Let's Encrypt, seul `index.php` est exécutable, assets statiques sous `/build` |
| Cron | Purge quotidienne (`app:purge`) et sauvegarde quotidienne de la base (14 jours) |

## 2. Déployer une version

Récupérer l'archive `password-manager-<version>.tar.gz` et son `.sha256` (artefact « release » de la CI), puis :

```bash
sudo -u pm-app bash deploy/deploy.sh /chemin/password-manager-<version>.tar.gz
sudo systemctl reload php8.3-fpm   # vide l'OPcache
```

`deploy.sh` vérifie la somme de contrôle, applique les migrations, lance `app:check-deployment` **avec le `php.ini` de PHP-FPM** et ne bascule la version qu'en cas de succès.

## 3. Vérifications

- [ ] `https://coffre.exemple.fr` affiche l'écran de création du coffre.
- [ ] `curl -I https://coffre.exemple.fr` montre `Strict-Transport-Security`, `Content-Security-Policy` et aucun en-tête `Server`/`X-Powered-By`.
- [ ] [securityheaders.com](https://securityheaders.com) et [Mozilla Observatory](https://developer.mozilla.org/en-US/observatory) : **A+**.
- [ ] `sudo nft list ruleset` : seuls 22, 80 et 443 sont ouverts.
- [ ] Créer le compte, activer la double authentification, imprimer le kit de secours.
- [ ] Exporter une sauvegarde chiffrée et la conserver hors du serveur.

## Sauvegardes

Deux niveaux complémentaires :

1. **Dump de la base** (cron, `/srv/password-manager/backups`) : contient uniquement des données chiffrées et les empreintes d'authentification. À copier régulièrement hors du VPS.
2. **Export du coffre** depuis l'application : fichier autonome, lisible sans serveur avec le mot de passe maître et la Secret Key.

## Derrière un proxy ou un CDN

Si un proxy termine le HTTPS, renseigner son adresse dans `TRUSTED_PROXIES` (`.env.local`). Sinon, laisser vide : les en-têtes `X-Forwarded-*` sont alors ignorés.

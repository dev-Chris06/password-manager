# Environnement de développement (sans Docker)

Le projet se développe directement sur le poste, sans virtualisation ([ADR-010](DECISIONS.md#adr-010--mutualisé-ou-vps-sans-docker)).

## Prérequis

| Outil | Version | Rôle |
|---|---|---|
| PHP | **8.3.36+, 8.4.27+ ou 8.5.12+** (correctifs de sécurité du 2026-10-21, voir [ADR-017](DECISIONS.md#adr-017--niveau-de-correctifs-php-imposé)), extensions `ctype`, `iconv`, `mbstring`, `openssl`, `pdo_mysql`, `sodium` | Serveur Symfony |
| Composer | 2.x | Dépendances PHP |
| MySQL | 8.0 (ou MariaDB 11.4) | Base de données (à partir de la phase 1) |
| Node.js | ≥ 20 (+ npm) | Compilation TypeScript et tests front — **jamais requis sur le serveur** |

Vérifier :

```bash
php -v && php -m | grep -Ei 'ctype|iconv|mbstring|openssl|pdo_mysql|sodium'
composer --version   # ou : php composer.phar --version
node --version && npm --version
php -d session.upload_progress.enabled=0 tools/check-php-platform.php   # version de PHP suffisamment corrigée
```

### Atténuation de CVE-2026-115560 sur le poste

Désactiver la progression d'envoi des sessions dans le `php.ini` utilisé (vérifier lequel avec `php --ini`) :

```ini
session.upload_progress.enabled = 0
```

Puis relancer `php tools/check-php-platform.php`, qui doit réussir sans l'option `-d`.

## Installation

```bash
git clone https://github.com/dev-Chris06/password-manager.git
cd password-manager
composer install
npm ci
npx playwright install chromium   # navigateur des tests de bout en bout
cp .env.local.dist .env.local     # facultatif en phase 0, puis renseigner les valeurs locales
npm run build
```

## Base de données locale

Créer un utilisateur MySQL dédié (en `root`, une seule fois) :

```sql
CREATE USER 'pm_app'@'localhost' IDENTIFIED BY 'un-mot-de-passe-long';
GRANT ALL PRIVILEGES ON `pm\_vault`.* TO 'pm_app'@'localhost';
GRANT ALL PRIVILEGES ON `pm\_vault\_test%`.* TO 'pm_app'@'localhost';
```

Puis renseigner `DATABASE_URL` dans `.env.local` (application), `.env.test.local` (tests PHP) et `.env.e2e.local` (tests Playwright) — trois fichiers ignorés par git, avec la même valeur :

```dotenv
DATABASE_URL="mysql://pm_app:un-mot-de-passe-long@localhost:3306/pm_vault?serverVersion=8.0.40&charset=utf8mb4"
```

L'environnement `test` utilise la base `pm_vault_test` ; l'environnement `e2e` utilise `pm_vault_test_e2e`, recréée automatiquement par `npm run test:e2e`. Créer les bases et appliquer les migrations :

```bash
php bin/console doctrine:database:create && php bin/console doctrine:migrations:migrate -n
php bin/console doctrine:database:create --env=test && php bin/console doctrine:migrations:migrate -n --env=test
```

## Configuration locale

- `.env` est **versionné** et ne contient que des valeurs par défaut sans secret.
- `.env.local` est **ignoré par git** et contient vos valeurs (secrets, base de données). Générer un secret :

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

## Lancer l'application

```bash
npm run watch                                   # recompile le TypeScript à chaque modification
php -d session.upload_progress.enabled=0 -S 127.0.0.1:8000 -t public   # serveur PHP intégré
```

Puis ouvrir <http://127.0.0.1:8000>.

## Vérifications

```bash
composer check    # PHP-CS-Fixer (vérification), PHPStan, PHPUnit
npm run check     # typecheck, ESLint, Vitest, build, garde-fou API interdites
npm run test:e2e  # Playwright (démarre le serveur PHP automatiquement)
```

Corriger automatiquement le style PHP : `composer fix`.

## Maintenance

```bash
php bin/console app:purge   # journal > 90 jours, sessions expirées, appareils inactifs > 180 jours (à lancer chaque jour par cron)
```

## Arborescence

```text
bin/ config/ public/ src/ templates/ tests/   ← Symfony
frontend/packages/crypto/                     ← module crypto partagé (phase 2)
frontend/web/                                 ← application du coffre → public/build/
extension/                                    ← extension Chrome (phase 7)
tests/e2e/                                    ← Playwright
tools/                                        ← scripts de build et de contrôle
docs/                                         ← plan, décisions, documentation
```

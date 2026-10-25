# Guide d'installation — Gestionnaire de mots de passe

Deux options sont proposées :

- **Option A — Dev / test rapide** : serveur intégré à PHP (45 secondes, zéro config Apache/Nginx)
- **Option B — Hébergement / mutualisé type Alwaysdata / Infomaniak / OVH** (FTP, racine web)

---

## Prérequis système

- PHP **8.3 ou supérieur** (PHP 8.3 est la version minimale déclarée par le projet)
- Extensions PHP **OBLIGATOIRES** :
  - `openssl` (chiffrement AES-GCM, PBKDF2, bcrypt natif fonctionne sans)
  - `pdo_mysql` (accès MySQL / MariaDB via PDO)
  - `mbstring` (coupes `mb_substr` sur données utilisateurs Unicode)
  - `session` (toujours présente, mais vérifier)
  - `json` (toujours présente, vérifier)
- MySQL ou **MariaDB** 10.6+ (compatibles `FROM_BASE64()` et `TIMESTAMPDIFF`)
- Pour les tests navigateur / extension : Chrome / Edge / Brave / Chromium ≥ Manifest V3 (2023+)

Vérifier ses extensions en ligne de commande :

```bash
php -m | grep -E "openssl|pdo_mysql|mbstring|session|json"
```

Tu dois voir les 5 lignes. Si une manque, active-la dans ton `php.ini`.

---

## Option A — Installation locale (serveur dev PHP, XAMPP autorisé)

### Étape A.1 : Récupérer le code

```bash
git clone <URL_DU_DEPOT> password-manager
cd password-manager
```

### Étape A.2 : Créer la base MySQL

Dans phpMyAdmin / Adminer / ou terminal :

```sql
CREATE DATABASE IF NOT EXISTS password_manager
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'password_manager'@'127.0.0.1'
  IDENTIFIED BY 'met-un-mot-de-passe-long-Ici!';

GRANT ALL PRIVILEGES ON password_manager.* TO 'password_manager'@'127.0.0.1';
FLUSH PRIVILEGES;
```

### Étape A.3 : Importer le schéma

En ligne de commande :

```bash
mysql -u password_manager -p password_manager < database.sql
```

OU dans phpMyAdmin → onglet **Importer** → choisir `database.sql` → Exécuter.

Vérifie que les 6 tables existent : `utilisateurs`, `entrees`, `tentatives_login`, `tentatives_login_detail`, `login_blocages`, `journal_actions`.

Le fichier actuel `database.sql` contient le schéma attendu par la version présente du code. Pour mettre à niveau une base existante créée avec un ancien schéma, sauvegarde-la puis applique, dans l'ordre, `migrations/003_login_blocages_v3.sql` et `migrations/004_add_global_ip_inscription.sql`. Ces scripts utilisent des procédures et des délimiteurs SQL : lance-les avec un client MySQL/MariaDB compatible. `SOURCE` fonctionne dans le client MySQL en ligne de commande, pas dans le formulaire SQL de phpMyAdmin.

### Étape A.4 : Configurer `.env`

```bash
cp .env.example .env
```

Ouvre `.env` et **au minimum** :

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=password_manager
DB_USER=password_manager
DB_PASS=met-un-mot-de-passe-long-Ici!
APP_URL=http://localhost:8000
ALLOW_ADDITIONAL_REGISTRATIONS=false
```

L'inscription du premier compte est ouverte tant que la base ne contient aucun utilisateur. Dès qu'un compte existe, les nouvelles inscriptions sont fermées par défaut. Pour les rouvrir temporairement, définis `ALLOW_ADDITIONAL_REGISTRATIONS=true` dans `.env`, puis remets `false` après usage et redémarre PHP.

### Étape A.5 : Lancer le serveur dev (sans Apache/Nginx !)

C'est un serveur PHP intégré léger, parfait pour tester :

```bash
# Depuis la racine du dépôt
php -S localhost:8000 -t public/ public/router.php

```

Ouvre `http://localhost:8000` dans ton navigateur. Tu dois voir la page de login.

### A.6 Premier test

- Clique **S'inscrire** → crée le compte unique (email valide de ton choix, MDP maître ≥ 12 caractères)
- Tu reçois une redirection → tu peux ajouter et gérer tes entrées.

---

## Option B — Hébergement mutualisé (PHP standard, Alwaysdata, Infomaniak...)

### Étape B.1 : Préparer la base

Dans l'admin de ton hébergeur, créé :

- 1 base MySQL (UTF8mb4)
- 1 utilisateur MySQL dédié avec droits `SELECT INSERT UPDATE DELETE CREATE INDEX REFERENCES DROP` (pour installer le schéma)

Récupère les 4 infos suivantes : hôte (`db.alwaysdata.net` ou IP), nom base, utilisateur, mot de passe.

### Étape B.2 : Importer le schéma

Via phpMyAdmin de ton hébergeur → **Importer** le fichier `database.sql`.
OU via un tunnel SSH :

```bash
mysql -h HOTE_BDD -u UTILISATEUR -p NOM_BASE < database.sql
```

### Étape B.3 : Uploader les fichiers par FTP / Git

La **racine web** de ton hébergeur est typiquement :

- Alwaysdata : `~/www`
- Infomaniak : `~/web`
- OVH : `~/www`

**La structure `public/` est utilisée par le projet actuel** :

- Uploade **tout le repo** dans un dossier de travail (ex. `~/password-manager-src`)
- Pointeur la racine web de l'hébergeur vers `~/password-manager-src/public` (demande au support d'ajouter un alias dans "Domaines > Chemin racine", ou utilise un `.htaccess` en sous-arbo).

**La racine du dépôt ne doit jamais être le DocumentRoot** :

- Configure le DocumentRoot sur `public/`, qui contient le routeur et le fichier `.htaccess`.
- Ne publie jamais le répertoire parent : il contient `.env`, les migrations, le schéma et les dépendances.

Pour Apache, le `.htaccess` fourni dans `public/` active le routeur. Pour Nginx, configure une règle équivalente vers `public/router.php`.

### Étape B.4 : Créer `.env` à côté de `index.php`

Même contenu que A.4 avec les infos de l'hébergeur.

### Étape B.5 — HTTPS (OBLIGATOIRE EN PRODUCTION)

Dans l'admin de ton hébergeur, activer Let's Encrypt (gratuit 90 j, renouvellement auto).

- Vérifie que ton site répond **en `https://` et force la redirection http → https**
- En HTTPS, le cookie session obtient automatiquement le drapeau `Secure` (code `is_https_request()`).

⚠️ Si tu ne mets pas HTTPS :

- Les cookies ne sont pas Secure = exposés sur le réseau WiFi public.
- Le MDP maître transite en clair → l'objectif de sécurité est contré.

---

## 🩺 Vérifications post-install (à faire une fois)

### Vérif 1 — Toutes extensions chargées

Créé un `info.php` temporaire dans la racine web :

```php
<?php phpinfo();
```

Charger `https://ton-site/info.php` → chercher :

- `openssl`, `pdo_mysql`, `mbstring` — tous doivent dire "enabled".
- **Supprime `info.php`** immédiatement après (fuite d'infos système).

### Vérif 2 — Rate-limiting + sessions

- 3 mauvais MDP au login → bloque 60 secondes → **marche ? OK**
- Supprime le cookie de session, reconnecte → cookie value change → `session_regenerate_id` OK

### Vérif 3 — Ajouter puis supprimer une entrée

- Table `entrees` → tu vois `iv` en base64 16 chars décodé = 12 octets, `auth_tag` non vide → AES-GCM actif.

### Vérif 4 — Export d'un coffre

- Onglet sauvegarde → exporter → **le code te re-demande le mot de passe actuel.** Télécharge le JSON.
- Teste import → tu retrouves les entrées.

---

## 🔄 Mettre à jour le code (plus tard)

```bash
git pull origin main        # ou feature/hardening-2026 pendant les travaux
```

---

## 🧯 Problèmes courants + solutions

| Symptôme                                                                            | Cause probable                                                                                                | Solution                                                                                                                                       |
| ----------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Page blanche, rien ne s'affiche                                                     | Erreur PHP masquée (display_errors=0 en production, voulu)                                                    | Dans `config/config.php`, temporairement passer `ini_set('display_errors', 1)` puis corriger la vraie erreur. N'oublie pas de le remettre à 0. |
| `could not find driver` depuis PDO                                                  | Extension `pdo_mysql` absente                                                                                 | Dans `php.ini` décommenter `extension=pdo_mysql`, redémarrer PHP / Apache.                                                                     |
| `Session: headers already sent`                                                     | Fichiers PHP / .env avec **BOM UTF-8** ou blanc avant `<?php`                                                 | Réenregistrer tes fichiers en `UTF-8 SANS BOM` (VS Code: en bas à droite clique UTF-8 → Save with Encoding → UTF-8).                           |
| Mot de passe hashé invalide / connexion impossible malgré bons identifiants         | Version PHP différente en CLI et Apache, ou `password_hash` + bcrypt avec un coût > 12 sur machine trop lente | Garde `BCRYPT_COST = 12` (par défaut). Vérifie phpinfo() dans Apache et `php -v` en CLI.                                                       |
| `failed to open stream: No such file or directory` en ligne 4-5 d'un fichier pages/ | Chemin `require_once __DIR__ . '/../includes/...` cassé si tu déplaces des fichiers                           | Applique les nouveaux chemins systématiquement.                                                                                                |
| L'extension MV3 ne voit pas la session                                              | Tu es en HTTP + navigateur refuse cookies SameSite=Strict en 3rd-party                                        | Passe HTTPS, ou localhost (127.0.0.1 est traité comme sécurisé par Chrome même en http://).                                                    |
| Le popup de l'extension ne détecte pas les champs                                    | L'onglet est une page protégée du navigateur, l'injection n'a pas abouti ou l'extension doit être rechargée   | Recharge la page et l'extension, vérifie les erreurs dans `chrome://extensions`, puis réessaie sur une page HTTP(S) ordinaire.                  |

---

## 💾 Faire un backup régulier (production)

```bash
# Backup BDD (à mettre dans cron quotidien 2x/jour)
mysqldump -u UTILISATEUR -p NOM_BASE > backup_$(date +%Y%m%d_%H%M).sql
gzip backup_*.sql
# Dépose les .gz hors du serveur (S3, autre hébergeur, clé USB...)

# Backup fichier APP (après chaque mise à jour de code)
tar czf app_$(date +%Y%m%d).tar.gz password-manager/ --exclude=.env
```

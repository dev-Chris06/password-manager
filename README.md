# Gestionnaire de mots de passe sécurisé

Application PHP procédurale permettant de gérer un coffre de mots de passe **chiffrés par utilisateur** avec 2FA (TOTP), rate-limiting, journal d'audit et extension Chrome MV3 pour autofill. Conçu comme projet de portfolio orienté bonnes pratiques OWASP.

## Vue d'architecture

```mermaid
flowchart LR
  U[Utilisateur] -->|Mot de passe maître| B[Navigateur]
  B -->|HTTPS / Session HttpOnly| P[PHP - index.php + pages + ajax]
  P -->|PDO prepare() + user_id ownership| D[(MySQL / MariaDB)]
  P -->|AES-256-GCM / PBKDF2 bcrypt| C[Crypto serveur]
  E[Extension Chrome MV3] -->|Fetch + AJAX token dédié| P

  style P fill:#e0f2fe,stroke:#075985
  style C fill:#dcfce7,stroke:#166534
```

## Prérequis

- PHP 8.2+ (ou 8.1 minimum) avec extensions : `openssl`, `pdo_mysql`, `mbstring`, `session`, `json`.
- MySQL 8+ ou MariaDB 10.6+.
- Navigateur moderne (Clipboard API + `crypto.getRandomValues`).
- Pour extension : Chromium / Chrome / Edge MV3 (version 2023+).

## Installation rapide

Voir [INSTALL.md](INSTALL.md) (2 options : serveur dev intégré ou hébergement mutualisé Alwaysdata / Infomaniak / OVH).

Pour un test ultra rapide :

```bash
cp .env.example .env              # édite DB_USER/DB_PASSWORD/DB_NAME après
mysql -u root -p < database.sql   # crée la base (adapter user si besoin)
php -S localhost:8000             # ouvre http://localhost:8000 dans Chrome
```

## Usage rapide

1. **Inscription** : email + mot de passe maître ≥ 12 caractères.
2. **(Optionnel mais recommandé)** Activer l'A2F TOTP dans le menu.
3. **Dashboard** : ajouter, modifier, supprimer, filtrer par catégorie.
4. **Copier** : bouton à côté de chaque identifiant / mot de passe → presse-papiers **auto-effacé au bout de 15 secondes**.
5. **Sauvegardes** : export chiffré du coffre + réimport avec re-demande du MDP maître.
6. **Extension** : charger le dossier `extension-chrome/` en mode développeur dans `chrome://extensions`.

## Sécurité

### Briques cryptographiques

| Brique | Implémentation | Fichier |
|---|---|---|
| Hash du MDP maître | `password_hash` + **bcrypt cost 12** | [config/config.php](config/config.php) · [includes/crypto.php](includes/crypto.php#L144-L147) |
| Dérivation clé AES | PBKDF2 **SHA-256, 100 000 itérations** (prévu : 600 000) | [includes/crypto.php#L23-L40](includes/crypto.php#L23-L40) |
| Chiffrement symétrique | **AES-256-GCM** IV 12 octets aléatoires / entrée | [includes/crypto.php#L47-L128](includes/crypto.php#L47-L128) |
| Source d'aléa CSPRNG serveur | `random_bytes()` (PHP) · `crypto.getRandomValues()` (JS) | Globale |

### Autres garanties

- Clé de chiffrement **jamais stockée en base** (session PHP uniquement, base64).
- Cookies de session : `HttpOnly`, `SameSite=Strict`, `Secure` **automatique en HTTPS**.
- Régénération d'ID de session après chaque login.
- CSRF token 256 bits sur **tous les formulaires POST + endpoints AJAX**.
- Toutes requêtes via `PDO::prepare` + paramètres liés (0 concat SQL).
- `WHERE user_id = ?` systématique sur CRUD (pas d'IDOR direct).
- 2FA TOTP avec fenêtre ±1, **anti-rejeu** (slot time BDD), 3 échecs → invalidation session pending.
- Rate limiting double (email + IP) : 3 échecs/60 s, 20 échecs IP/1h → blocage 1 h.
- Timeout sessions : inactivité 5 min + durée max 1 h.
- Re-demande du **mot de passe maître actuel** avant : export coffre, désactivation TOTP, (changement MDP déjà).
- Headers de sécurité révisés CSP nonces + `frame-ancestors 'none'` + `base-uri 'self'` + `form-action 'self'` + retrait `X-XSS-Protection`.
- Journal d'audit structuré : 502j, rétention configurable (IP, UA, détail sans donnée sensible).

## Récupération de compte (oubli du MDP maître)

> **Choix délibéré : pas de lien "Mot de passe oublié" par email.**
> Un reset par email casserait la promesse de confidentialité "zero-connaissance".

### Stratégie retenue (clés de récupération)

- À l'inscription ET à chaque changement de MDP maître : une **clé de récupération unique** `XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX` est affichée **une seule fois**.
- L'utilisateur **doit l'imprimer** ou la stocker dans un emplacement SÉPARÉ de son MDP maître.
- Pour récupérer : page de reset → saisie de la clé de récupération → nouveau MDP maître → invalidation automatique de l'ancienne clé.
- ❌ Perte simultanée du MDP maître ET de la clé de récupération → **perte définitive du coffre** (aucune backdoor).

Détails dans : [docs/MENACE.md § 5](docs/MENACE.md#5-stratégie-de-récupération-de-compte-prévue--documentation)

## Structure

```text
config/              # Paramètres PHP + env loader + PDO
includes/            # auth, crypto, entrees, totp, qrcode
pages/               # Vues HTML (login, dashboard, TOTP, backup…)
ajax/                # Endpoints JSON (déchiffrement, export/import, remplissage, extension)
assets/css/          # style.css
assets/js/           # dashboard, generateur, force_mdp, backup
docs/                # MENACE, TEST_GUIDE, RATE_LIMITING
extension-chrome/    # Manifest V3 + content/popup/background
index.php            # Point d'entrée + redirect login/dashboard
INSTALL.md           # Guide d'installation pas à pas
SECURITY.md          # Politique de signalement de vulnérabilités
database.sql         # Schéma initial de la BDD
README.md
```

## Documentation complémentaire

| Document | Lien |
|---|---|
| 📋 Modèle de menace (adversaires, garanties, limites, récupération) | [docs/MENACE.md](docs/MENACE.md) |
| 🧪 Guide de test manuel (18 scénarios) | [docs/TEST_GUIDE.md](docs/TEST_GUIDE.md) |
| ⏱️ Rate limiting (logique, seuils, purge) | [docs/RATE_LIMITING.md](docs/RATE_LIMITING.md) |
| 🪪 Extension Chrome / autofill | [extension-chrome/README.md](extension-chrome/README.md) |
| 🛠️ Installation + FAQ + troubleshooting | [INSTALL.md](INSTALL.md) |
| 🚨 Vulnérabilités / divulgation responsable | [SECURITY.md](SECURITY.md) |

## Grille OWASP ASVS — Niveau 1 (autoévaluation)

| Critère OWASP ASVS 4.0.3 N1 | Statut |
|---|---|
| V1.1.1 Framework sécuritaire + patches à jour | ✅ Procédural sans framework, briques PHP récentes |
| V1.2.1 Fichiers sensibles (.env, backups) hors docroot | 🟡 Avec structure `public/` (hardening P1.3) |
| V2.1.1 Longueur minimale MDP (>= 12) | ✅ `mot_de_passe_valide()` + HTML minlength |
| V2.1.4 Mot de passe interdit (dictionnaire rockyou) | ⚪ Non implémenté (recommandé pour ASVS N2) |
| V2.2.1 bcrypt/Argon2id avec cost élevé | ✅ bcrypt cost 12 (Argon2id prévu PHP 8.4+) |
| V2.3.1 Mot de passe jamais stocké en clair | ✅ Jamais en BDD, jamais en session |
| V2.6.2 Login sécurisé : HttpOnly Secure SameSite | ✅ `demarrer_session_securisee()` |
| V2.6.3 RÉGÉNÉRATION session id post login | ✅ `session_regenerate_id(true)` |
| V2.7.1 Logout invalide session côté serveur | ✅ `deconnecter_utilisateur()` + session_destroy |
| V3.1.1 2FA disponible | ✅ TOTP + anti-rejeu + codes récupération |
| V4.1.1 `Content-Security-Policy` | ✅ Nonces, `frame-ancestors`, `base-uri`, `form-action` |
| V4.1.2 `X-Content-Type-Options: nosniff` | ✅ Oui |
| V4.1.3 `X-Frame-Options: DENY / frame-ancestors none` | ✅ Les deux (compat ancien navigateurs) |
| V4.1.4 `Referrer-Policy` | ✅ `strict-origin-when-cross-origin` |
| V4.1.5 `Strict-Transport-Security` en HTTPS | ✅ `max-age=31536000; includeSubDomains` |
| V5.1.1 Validation entrées serveur (pas seulement client) | ✅ filter_var + strlen + catégories whitelist |
| V5.3.1 Sortie échappée par contexte (HTML/JS) | ✅ `e()` alias `htmlspecialchars()` |
| V5.3.5 JSON encode propre | ✅ `json_encode` UTF-8 sur endpoints ajax |
| V6.1.1 Requêtes paramétrées / ORM safe | ✅ PDO prepare() partout |
| V7.1.1 CSRF sur actions POST modifiant état | ✅ Token 256 bits + `hash_equals` |
| V7.2.1 CORS restrictif / credentials autorisés | ✅ CSP default-src self + host whitelist backend |
| V8.1.1 Passwords never logged | ✅ journal_actions jamais de mot de passe en clair |
| V8.3.4 Log d'événements auth + échecs | ✅ connexion_ok / connexion_echec / compte_bloque |
| V8.3.5 IP, UA dans journal | ✅ colonnes journal_actions ip / user_agent |
| V9.1.1 TLS en production requis | ✅ README le recommande, cookie Secure auto-HTTPS |
| V10.1.1 Crypto AES-GCM 256 bits | ✅ `aes-256-gcm` |
| V10.1.2 IV/nonces cryptographiquement aléatoires | ✅ `random_bytes(12)` + par entrée |
| V10.2.1 KDF itératif PBKDF2 avec >= 100k itérations | ✅ 100k, prévu 600k OWASP 2025 |
| V13.1.1 Secret TOTP stocké de façon sécurisée | 🟡 En clair prévu : chiffrement avec cle_maitre |
| V14.4.1 Build/Dockerfile sans secrets hardcodés | ✅ `.env` exclus de Git |

Légende : ✅ conforme · 🟡 partielle / en cours · ⚪ non applicable / hors périmètre N1

## Notes locales dev

- **HTTP localhost** : cookie **non** Secure (conforme). Activer `extension=curl` si besoin, mais non nécessaire.
- **HTTPS** : cookie Secure + HSTS activés automatiquement par `is_https_request()`.
- **Tester l'extension** : onglet `chrome://extensions` → Activer le mode développeur → Charger l'extension non empaquetée → pointer `extension-chrome/`.


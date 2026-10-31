# Décisions d'architecture (ADR)

Chaque décision structurante est consignée ici : **contexte**, **décision**, **alternatives écartées**, **conséquences**.
Une décision n'est jamais modifiée silencieusement : elle est remplacée par une nouvelle ADR qui la référence (statut « Remplacée par ADR-0XX »).

Les numéros correspondent aux décisions D1–D17 de [PLAN.md](PLAN.md#2-décisions-arrêtées).

| ADR | Sujet | Statut |
|---|---|---|
| [001](#adr-001--chiffrement-zero-knowledge-côté-client) | Chiffrement zero-knowledge côté client | Acceptée |
| [002](#adr-002--repartir-dun-schéma-vierge) | Repartir d'un schéma vierge | Acceptée |
| [003](#adr-003--argon2id-comme-kdf) | Argon2id comme KDF | Acceptée |
| [004](#adr-004--secret-key-dérivation-à-deux-secrets) | Secret Key (dérivation à deux secrets) | Acceptée |
| [005](#adr-005--totp-obligatoire--codes-de-secours) | TOTP obligatoire + codes de secours | Acceptée |
| [006](#adr-006--hibp-à-la-demande) | HIBP à la demande | Acceptée |
| [007](#adr-007--symfony-74-lts) | Symfony 7.4 LTS | Acceptée |
| [008](#adr-008--typescript--esbuild-sans-framework-ui) | TypeScript + esbuild, sans framework UI | Acceptée |
| [009](#adr-009--mysql-80-et-mariadb-114) | MySQL 8.0 et MariaDB 11.4 | Acceptée |
| [010](#adr-010--mutualisé-ou-vps-sans-docker) | Mutualisé ou VPS, sans Docker | Acceptée |
| [011](#adr-011--un-seul-compte-pas-de-démo-en-ligne) | Un seul compte, pas de démo en ligne | Acceptée |
| [012](#adr-012--extension-chromium-uniquement) | Extension Chromium uniquement | Acceptée |
| [013](#adr-013--code-en-anglais-interface-en-français) | Code en anglais, interface en français | Acceptée |
| [014](#adr-014--licence-agpl-30) | Licence AGPL-3.0 | Acceptée |
| [015](#adr-015--présentation-par-readme-et-vidéo) | Présentation par README et vidéo | Acceptée |
| [016](#adr-016--pas-déchéance) | Pas d'échéance | Acceptée |
| [017](#adr-017--niveau-de-correctifs-php-imposé) | Niveau de correctifs PHP imposé | Acceptée |
| [018](#adr-018--2fa-sans-scheb2fa-bundle) | 2FA sans `scheb/2fa-bundle` | Acceptée (précise ADR-005) |

---

## ADR-001 — Chiffrement zero-knowledge côté client

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : l'application d'origine chiffrait côté serveur et gardait la clé AES dans la session PHP. Le serveur voyait donc le mot de passe maître et chaque secret ; un accès au disque (fichiers de session) suffisait à déchiffrer le coffre.
- **Décision** : tout chiffrement et déchiffrement a lieu dans le navigateur ou l'extension (WebCrypto). Le serveur ne reçoit qu'une preuve d'authentification dérivée et des blobs chiffrés.
- **Alternatives écartées** : chiffrement serveur renforcé (clé partagée cookie/session) et clé de coffre aléatoire côté serveur — toutes deux laissent le serveur voir les secrets.
- **Conséquences** : réécriture complète ; la sécurité dépend désormais du client (une XSS devient critique → CSP stricte et Trusted Types) ; le serveur servant le JavaScript web, un serveur compromis *activement* reste une limite assumée (l'extension n'en dépend pas).

## ADR-002 — Repartir d'un schéma vierge

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : le projet ne contient que des données de test.
- **Décision** : nouveau schéma et nouvelles migrations, sans reprise des données. L'ancien état est conservé sous le tag git `avant-refonte`.
- **Alternatives écartées** : endpoint de migration à usage unique (code temporaire sensible, ~0,5 j).
- **Conséquences** : schéma conçu pour le zero-knowledge sans compromis d'héritage.

## ADR-003 — Argon2id comme KDF

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : la clé de coffre chiffrée est stockée en base ; en cas de vol, seule la KDF freine le craquage hors ligne.
- **Décision** : Argon2id via `hash-wasm` (WASM), m = 64 MiB, t = 3, p = 1. Paramètres stockés par compte ; planchers imposés par le client.
- **Alternatives écartées** : PBKDF2-SHA256 600k (natif WebCrypto, mais fortement parallélisable sur GPU).
- **Conséquences** : une dépendance WASM ; `'wasm-unsafe-eval'` dans la CSP (compilation WASM uniquement, pas `eval`) ; ~1 s au déverrouillage.

## ADR-004 — Secret Key (dérivation à deux secrets)

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : même avec Argon2id, un mot de passe maître faible reste craquable si la base ou une sauvegarde est volée.
- **Décision** : une Secret Key de 128 bits, générée sur l'appareil et jamais envoyée, est combinée au mot de passe (modèle 1Password). Un kit de secours imprimable est obligatoire à l'inscription.
- **Alternatives écartées** : mot de passe seul.
- **Conséquences** : base et sauvegardes volées inexploitables ; saisie de la Secret Key sur chaque nouvel appareil ; sa perte (avec tous les appareils qui la mémorisent) rend le coffre irrécupérable.

## ADR-005 — TOTP obligatoire + codes de secours

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : dans un modèle zero-knowledge, la 2FA ne protège pas le chiffrement mais l'accès au coffre chiffré (téléchargement pour craquage hors ligne avec une preuve volée). Une première implémentation TOTP maison avait été retirée (commit `67b1ae0`).
- **Décision** : TOTP (RFC 6238) via `scheb/2fa-bundle`, obligatoire, 10 codes de secours hachés à usage unique ; le coffre n'est servi qu'après validation du second facteur. Secret TOTP chiffré au repos avec une clé serveur.
- **Alternatives écartées** : WebAuthn (résistant au phishing mais complexe dans l'extension, ~+2,5 j) ; 2FA facultative.
- **Conséquences** : le serveur détient un secret (TOTP) qui protège l'authentification, jamais le contenu du coffre ; commande CLI de réinitialisation en dernier recours.
- **Mise en œuvre** : précisée par [ADR-018](#adr-018--2fa-sans-scheb2fa-bundle).

## ADR-006 — HIBP à la demande

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : signaler les mots de passe apparus dans des fuites est une fonction attendue.
- **Décision** : vérification sur clic uniquement, par k-anonymat (5 premiers caractères du SHA-1, en-tête `Add-Padding`).
- **Alternatives écartées** : aucune vérification ; vérification automatique.
- **Conséquences** : `api.pwnedpasswords.com` autorisé dans `connect-src`.

## ADR-007 — Symfony 7.4 LTS

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : en zero-knowledge le serveur ne fait que stocker, authentifier, limiter et journaliser. L'audit a montré que les défauts se concentraient dans le code de sécurité écrit à la main.
- **Décision** : PHP 8.3+ avec Symfony 7.4 LTS (sécurité maintenue jusqu'en 2029) : Security, RateLimiter, CSRF, Doctrine, `scheb/2fa-bundle`.
- **Alternatives écartées** : PHP sans framework (toute la sécurité maison) ; Go, Rust, Node (réécriture totale, hébergement contraint, risque npm pour Node).
- **Conséquences** : apprentissage du framework ; moins de code de sécurité spécifique ; même famille technique que Proton.

## ADR-008 — TypeScript + esbuild, sans framework UI

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : toute la cryptographie vit dans le client, partagée entre application web et extension.
- **Décision** : TypeScript `strict`, un module crypto unique compilé par esbuild pour les deux cibles ; interface en TypeScript sans framework.
- **Alternatives écartées** : JavaScript sans typage avec copie synchronisée du module ; Rust/WASM (surdimensionné).
- **Conséquences** : étape de build (Node ≥ 20 en développement et en CI seulement, jamais sur le serveur).

## ADR-009 — MySQL 8.0 et MariaDB 11.4

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : MariaDB est un fork compatible de MySQL ; les hébergeurs proposent l'un ou l'autre.
- **Décision** : développement sur MySQL 8.0 ; compatibilité MariaDB 11.4 vérifiée en CI dès que la base est utilisée (phase 1).
- **Conséquences** : rester dans le sous-ensemble commun, ce que Doctrine facilite.

## ADR-010 — Mutualisé ou VPS, sans Docker

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : le poste de développement n'a pas la virtualisation (pas de Docker). L'auteur veut pouvoir choisir l'hébergement.
- **Décision** : deux cibles documentées et testées — mutualisé (Alwaysdata de référence) et VPS sans Docker (Caddy + PHP-FPM + MariaDB). Livraison par archive produite en CI.
- **Conséquences** : rien qui exige Redis, workers ou Docker en production ; sessions et limiteurs stockés en base ou fichiers.

## ADR-011 — Un seul compte, pas de démo en ligne

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : coffre personnel ; une démo partageant l'instance exposerait le coffre réel.
- **Décision** : inscriptions fermées après le premier compte. Par prudence, chaque donnée reste rattachée à son compte et vérifiée à chaque accès. Pas d'instance publique.
- **Conséquences** : tests d'isolation avec deux comptes créés directement en base ; aucune fonctionnalité multi-utilisateur.

## ADR-012 — Extension Chromium uniquement

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Décision** : Chrome, Chromium et Edge (MV3).
- **Alternatives écartées** : Firefox (MV3 différent, ~+1,5 j).
- **Conséquences** : `chrome.storage.session` en accès restreint disponible.

## ADR-013 — Code en anglais, interface en français

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Décision** : en anglais les identifiants (fonctions, variables, classes, tables, colonnes), les commentaires et les messages de commit (Conventional Commits). En français l'interface et la documentation du dépôt.
- **Conséquences** : cohérence avec Symfony et TypeScript.

## ADR-014 — Licence AGPL-3.0

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : dépôt public ; « propriétaire » interdit toute réutilisation légale.
- **Décision** : AGPL-3.0-or-later.
- **Alternatives écartées** : MIT, Apache-2.0 (permissives), propriétaire.
- **Conséquences** : une version modifiée hébergée en ligne doit publier son code ; toutes les dépendances prévues (MIT, Apache-2.0) sont compatibles.

## ADR-015 — Présentation par README et vidéo

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Décision** : README « portfolio » (captures, GIF, schémas, modèle de sécurité) et vidéo de 2–3 minutes. Le projet s'essaie en local depuis GitHub.

## ADR-016 — Pas d'échéance

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Décision** : qualité avant vitesse, validation à la fin de chaque phase.

## ADR-017 — Niveau de correctifs PHP imposé

- **Date** : 2026-10-28 — **Statut** : Acceptée
- **Contexte** : le 2026-10-21, PHP a publié en urgence 8.5.12, 8.4.27, 8.3.36 et 8.2.35 pour corriger « PHP2Shell » (CVE-2026-109618, CVE-2026-109619) : deux exécutions de code à distance sans authentification (CVSS 9.2), exploitées activement et inscrites au catalogue KEV de la CISA. Le 2026-10-23, une troisième faille (CVE-2026-115560) a été annoncée sans correctif ; l'atténuation recommandée est `session.upload_progress.enabled = 0`. Certains hébergeurs corrigent sans changer le numéro de version.
- **Décision** :
  - `composer.json` exige `>=8.3.36 <8.4 || >=8.4.27 <8.5 || >=8.5.12`, et la plateforme de résolution est fixée à 8.3.36 ;
  - `tools/check-php-platform.php` vérifie la version et l'atténuation ; il tourne en CI et doit tourner à chaque déploiement avec la configuration de PHP-FPM ;
  - `public/.user.ini` désactive `session.upload_progress` (pris en compte par PHP-FPM/CGI, y compris sur mutualisé) ; la CI et le serveur de test utilisent le même réglage ;
  - l'application ne modifie jamais `session.serialize_handler` à l'exécution.
- **Conséquences** : impossible d'installer les dépendances sur une version vulnérable ; un hébergeur ayant corrigé sans changer de numéro de version doit être vérifié manuellement. À réviser à chaque nouvelle publication de sécurité de PHP.

## ADR-018 — 2FA sans `scheb/2fa-bundle`

- **Date** : 2026-10-31 — **Statut** : Acceptée (précise ADR-005)
- **Contexte** : `scheb/2fa-bundle` est pensé pour des formulaires HTML : il lit le code dans les paramètres POST, pas dans un corps JSON, et son parcours ne prévoit ni l'activation obligatoire en JSON, ni un secret chiffré au repos, ni nos codes de secours stockés en HMAC, ni notre limiteur de tentatives. L'intégrer aurait demandé d'en surcharger la plupart des composants.
- **Décision** : utiliser `spomky-labs/otphp` (la bibliothèque TOTP sur laquelle repose `scheb/2fa-totp`) pour le calcul des codes, et un parcours en deux étapes fondé sur les rôles de Symfony :
  - après la preuve, le jeton porte `ROLE_2FA_PENDING` (code attendu) ou `ROLE_2FA_SETUP` (activation obligatoire), qui n'ouvrent que les routes du second facteur ;
  - le second facteur validé, `Security::login()` crée un jeton complet (`ROLE_USER`) via un badge dédié ;
  - `User` implémente `EquatableInterface` pour que Symfony ne ferme pas la session intermédiaire en comparant les rôles.
- **Alternatives écartées** : `scheb/2fa-bundle` avec adaptateurs JSON (davantage de code d'intégration que de code utile) ; TOTP entièrement maison (algorithme simple mais `otphp` est maintenu et éprouvé).
- **Conséquences** : environ 300 lignes de code applicatif couvertes par des tests (vecteurs RFC 6238, anti-rejeu, fenêtre de ±1 période, codes à usage unique, limiteur, délai de 5 minutes) ; aucune dépendance « bundle ».

# Modèle de menace — Gestionnaire de mots de passe

Document de référence du projet. Objectif : définir **explicitement** contre qui on se protège, ce qu'on protège, et SURTOUT ce qui est hors périmètre (ce que le projet ne prétend PAS faire).

---

## 1. Actifs (ce qu'on protège)

| Actif                                               | Type               | Emplacement                                                                                                        |
| --------------------------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------ |
| Les mots de passe stockés par utilisateur           | Données sensibles  | Colonne `mdp_chiffre` en AES-256-GCM, BDD `gestionnaire_mdp`.`entrees`                                             |
| Mot de passe maître (en transit et en court de vie) | Secret utilisateur | Saisi dans un formulaire `<input type="password">`, jamais stocké en clair ni en BDD                               |
| Clé de chiffrement par utilisateur                  | Dérivée PBKDF2     | En session PHP (`$_SESSION['cle_chiffrement']` base64), **jamais en BDD**                                          |
| Secret TOTP par utilisateur                         | Secret 2FA         | Colonne `totp_secret` (clair aujourd'hui, prévu d'être chiffré par la suite)                                       |
| Session utilisateur active                          | État auth          | Cookie `gestionnaire_mdp_session` (HttpOnly / SameSite=Strict)                                                     |
| Identifiants, sites, catégories                     | Métadonnées        | Colonnes `identifiant`, `site`, `categorie` — stockés EN CLAIR (nécessaires pour l'autofill par requêtes SQL LIKE) |

---

## 2. Adversaires modélisés (contre qui on se protège)

| Niveau                                  | Adversaire                             | Méthodes typiques                                                                                     | Ce qu'on attend de lui                                                                           |
| --------------------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **N1 — Attaquant externe anonyme**      | N'importe qui sur internet             | Bruteforce login, SQL injection, XSS stocké, CSRF, vol de session via réseaux, reconnaissance d'email | Aucun accès serveur, aucune connaissance interne                                                 |
| **N2 — Leak de BDD seul**               | N'importe qui qui récupère un dump SQL | Exfiltration seule de la BDD (backup mal placé, faille d'hébergeur, injection aveugle INTO OUTFILE)   | Il a `utilisateurs.* + entrees.*`, mais pas de contrôle sur PHP, ni fichiers servis, ni sessions |
| **N3 — Utilisateur inscrit concurrent** | Un autre compte valide du coffre       | Test d'IDOR (changer id dans URL / AJAX), force brute CSRF token, navigation forcée                   | Accès à son propre compte, mais veut lire/modifier/supprimer des entrées d'autres comptes        |

---

## 3. Garanties (ce que le code fournit)

| Garantie                                            | Implémentation                                                                               | Vérifiable par                                                                                                                                                                            |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Hash lent + sel aléatoire par utilisateur**       | bcrypt cost 12 + PBKDF2-SHA256 100k itérations (prévu 600k)                                  | [config.php](file:///home/dev-chris06/dev/password-manager/config/config.php) + [crypto.php](file:///home/dev-chris06/dev/password-manager/includes/crypto.php#L23-L40)                   |
| **Chiffrement authentifié AES-256-GCM**             | `openssl_encrypt` mode GCM, IV 12 octets aléatoires par entrée, tag 16 octets séparé         | [crypto.php#L47-L128](file:///home/dev-chris06/dev/password-manager/includes/crypto.php#L47-L128)                                                                                         |
| **Aucune clé stockée en base**                      | Clé uniquement en session, base64                                                            | [crypto.php#L42-L45](file:///home/dev-chris06/dev/password-manager/includes/crypto.php#L42-L45)                                                                                           |
| **Sessions sécurisées**                             | HttpOnly + SameSite=Strict + Secure auto en HTTPS + strict_mode + use_only_cookies           | [auth.php#L22-L48](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L22-L48)                                                                                               |
| **Régénération d'ID post-login**                    | `session_regenerate_id(true)`                                                                | [auth.php#L411](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L411)                                                                                                     |
| **CSRF synchrone token**                            | 32 octets random_bytes + `hash_equals` vérif timing safe                                     | [auth.php#L83-L102](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L83-L102)                                                                                             |
| **SQLI impossible en théorie**                      | Toutes requêtes `prepare` + `execute([])` paramétré ; 0 concaténation de user-input dans SQL | Vérifiable par grep : 0 build chaîne SQL à partir de $_POST/$\_GET concaténé                                                                                                              |
| **Ownership à chaque accès**                        | `WHERE user_id = :user_id` systématiquement sur CRUD                                         | Toutes fonctions `lister_entrees`, `obtenir_entree`, `modifier_entree`, `supprimer_entree` dans [entrees.php](file:///home/dev-chris06/dev/password-manager/includes/entrees.php)         |
| **Rate limiting double (email + IP)**               | 3 échecs / email = blocage 60 s, 20 échecs / IP sur 1 h = blocage 1 h                        | [auth.php#L244-L347](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L244-L347) + [RATE_LIMITING.md](file:///home/dev-chris06/dev/password-manager/docs/RATE_LIMITING.md) |
| **2FA TOTP anti-rejeu (en cours d'implémentation)** | dernier slot time accepté stocké en BDD, réutilisation d'un code déjà valide refusée         | Colonnes `utilisateurs.last_totp_slot` + `verifier_code_totp(…, $slotUtilise)`                                                                                                            |
| **Expiration sessions** (en cours)                  | Inactivité 5 min + durée absolue 1 h max                                                     | `demarrer_session_securisee()` après hardening                                                                                                                                            |
| **Re-demande MDP maître** (en cours)                | Actions critiques export coffre + désactivation TOTP                                         | `exporter.php` + `totp_desactiver.php`                                                                                                                                                    |
| **Journalisation structurée**                       | Table `journal_actions` avec action, détail, IP, UA, rétention configurable                  | [auth.php#L502-L540](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L502-L540)                                                                                           |

---

## 4. Limites connues (HORS PÉRIMÈTRE — ce que le code NE PEUT PAS protéger)

⚠️ Ces points sont des choix délibérés, pas des bugs. Ils doivent apparaître dans le README / SECURITY.md.

### 4.1 Menaces hors protection par design

| #   | Menace                                                                                          | Pourquoi hors périmètre                                                                                                                                                                                                                      |
| --- | ----------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | **Keylogger / malware sur la machine de l'utilisateur**                                         | Si un malware capture les frappes clavier, la fuite est côté client avant tout chiffrement.                                                                                                                                                  |
| 2   | **Attaquant qui contrôle INTÉGRALEMENT le serveur** (PHP, fichiers servis, base)                | Peut injecter un JS malveillant sur login.php et lire le MDP maître au clavier, ou altérer `password_verify`. Aucun gestionnaire de mots de passe web protège contre ça (bitwarden self-hosted inclus), sauf desktop/extension offline-only. |
| 3   | **Mot de passe maître faible choisi par l'utilisateur** (ex. `azerty123456`)                    | Le code impose une **longueur minimale (12)** mais ne peut pas empêcher un MDP très commun. C'est de la responsabilité utilisateur.                                                                                                          |
| 4   | **Attaque homme-du-milieu réseau SUR UNE CHAÎNE HTTP NON CHIFFRÉE EN CLAIR localhost pour dev** | Attendu en développement ; en production HTTPS c'est obligatoire. Le cookie `Secure` s'active seule en HTTPS.                                                                                                                                |
| 5   | **Perte SIMULTANÉE du mot de passe maître ET de la clé de récupération**                        | Pas de backdoor, pas de reset par email (pour respecter zéro-connaissance). Perte définitive des entrées.                                                                                                                                    |
| 6   | **Extensions / userscripts Chrome malveillants installés par l'utilisateur lui-même**           | Content scripts non vérifiés par MV3 peuvent lire le DOM et voler ce que tu remplis.                                                                                                                                                         |
| 7   | **Phishing sur le coffre** (faux `password-manager.evil`)                                       | L'utilisateur peut être induit à saisir son MDP maître sur un site faux ; c'est de la vigilance humaine.                                                                                                                                     |

### 4.2 Limites techniques connues (non bloquantes)

| #   | Limite                                                                                                                               | Statut                                                                                                                                                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Métadonnées `site`, `identifiant`, `categorie` en clair dans la base                                                                 | ⚖️ **Choix** : nécessité de requêtes SQL performantes LIKE / ORDER BY / GROUP BY. Une version chiffrées aurait besoin d'index aveugles (searchable encryption), complexité élevée. Documenté explicitement. |
| 2   | Autofill par `remplissage.php` accepte correspondances partielles LIKE                                                               | ⚖️ **Choix** : compatibilité `accounts.google.com` ↔ `google.com`. Prévu d'ajouter un "domaine exact" configurable.                                                                                         |
| 3   | L'extension Chrome avec permission `<all_urls>` pour content scripts est refusée par Chrome Web Store si on publie sans vérification | Info seulement pour publication ; en dev / CRX côté utilisateur ça passe.                                                                                                                                   |
| 4   | Pas d'audit de sécurité indépendant (pen-test par un pro payant)                                                                     | Indiqué dans SECURITY.md "as-is no warranty".                                                                                                                                                               |

---

## 5. Stratégie de récupération de compte (PRÉVUE / documentation)

Pour répondre au problème "oubli fréquent du MDP maître" **SANS casser zéro-connaissance** :

### Principe retenu (documentation — implémentation prévue)

À chaque **inscription** et à chaque **changement du MDP maître**, le code affichera :

1. **Une clé de récupération unique** (format `XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX-XXXX` — 32 caractères aléatoires CSPRNG)
2. L'utilisateur doit :
   - l'imprimer OU la copier dans un gestionnaire de mots de passe _autre_
   - saisir les 4 premiers blocs dans un champ de confirmation (preuve qu'il l'a lue)
   - une case "J'ai sauvegardé ma clé de récupération" est **obligatoire** pour poursuivre

### Quand elle sert

Oubli du MDP maître → page `reset_par_recovery.php` :

- Saisie de la clé de récupération → vérification (hash bcrypt ou chiffrement-symmetrique)
- Saisie NOUVEAU MDP maître + confirmation
- La clé maître est rechiffrée (via un schéma _key wrapping_ AES-GCM dérivé de la recovery key) → toutes les entrées sont rechiffrées avec la nouvelle clé dérivée du nouveau MDP.
- L'ancienne recovery key est **invalidée** et une NOUVELLE est affichée (1 seule utilisation).

### Ce que n'est PAS prévu

- ❌ Pas de lien "oublié" par email : casserait zéro-connaissance
- ❌ Pas de questions secrètes seules (facilement social-engineerables) — elles pourront seulement compléter la recovery key, pas la remplacer
- ❌ Pas de backdoor admin pour reset le coffre d'un utilisateur

---

## 6. Validation du modèle

- Ce document est la **source de vérité** pour toute question "est-ce que c'est un bug ? est-ce que je dois corriger ça ?"
- Toute nouvelle correction / durcissement dans le code devrait pouvoir être **explicitement justifiée** par une section §2 (adversaire) → §3 (garantie)
- Toute correction refusée / impossibilité devrait être ajoutée en §4 (limites connues)

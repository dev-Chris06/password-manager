# Modèle de menace — Gestionnaire de mots de passe

Document de référence du projet. Objectif : définir **explicitement** contre qui on se protège, ce qu'on protège, et SURTOUT ce qui est hors périmètre (ce que le projet ne prétend PAS faire).

---

## 1. Actifs (ce qu'on protège)

| Actif                                               | Type               | Emplacement                                                                                                        |
| --------------------------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------------ |
| Les mots de passe stockés par utilisateur           | Données sensibles  | Colonne `mdp_chiffre` en AES-256-GCM, table `password_manager`.`entrees`                                            |
| Mot de passe maître (en transit et en court de vie) | Secret utilisateur | Saisi dans un formulaire `<input type="password">`, jamais stocké en clair ni en BDD                               |
| Clé de chiffrement par utilisateur                  | Dérivée PBKDF2     | En session PHP (`$_SESSION['cle_chiffrement']` base64), **jamais en BDD**                                          |
| Session utilisateur active                          | État auth          | Cookie `gestionnaire_mdp_session` (HttpOnly / SameSite=Strict)                                                     |
| Identifiants, sites, catégories                     | Métadonnées        | Colonnes `identifiant`, `site`, `categorie` — stockés EN CLAIR (nécessaires pour l'autofill par requêtes SQL LIKE) |

---

## 2. Adversaires modélisés (contre qui on se protège)

| Niveau                                  | Adversaire                             | Méthodes typiques                                                                                     | Ce qu'on attend de lui                                                                           |
| --------------------------------------- | -------------------------------------- | ----------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------ |
| **N1 — Attaquant externe anonyme**      | N'importe qui sur internet             | Bruteforce login, SQL injection, XSS stocké, CSRF, vol de session via réseaux, reconnaissance d'email | Aucun accès serveur, aucune connaissance interne                                                 |
| **N2 — Leak de BDD seul**               | N'importe qui qui récupère un dump SQL | Exfiltration seule de la BDD (backup mal placé, faille d'hébergeur, injection aveugle INTO OUTFILE)   | Il a `utilisateurs.* + entrees.*`, mais pas de contrôle sur PHP, ni fichiers servis, ni sessions |
| **N3 — Autre compte (si activé)**       | Un autre compte valide, si les inscriptions supplémentaires sont temporairement ouvertes | Test d'IDOR (changer id dans URL / AJAX), tentative d'accès aux entrées d'un autre compte | Le déploiement normal vise un seul utilisateur; ce scénario couvre le cas où plusieurs comptes sont créés. |

---

## 3. Garanties (ce que le code fournit)

| Garantie                                            | Implémentation                                                                               | Vérifiable par                                                                                                                                                                            |
| --------------------------------------------------- | -------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Hash lent + sel aléatoire par utilisateur**       | bcrypt cost 12 + PBKDF2-SHA256 600k itérations                                  | [config.php](../config/config.php) + [crypto.php](../includes/crypto.php#L23)                   |
| **Chiffrement authentifié AES-256-GCM**             | `openssl_encrypt` mode GCM, IV 12 octets aléatoires par entrée, tag 16 octets séparé         | [crypto.php](../includes/crypto.php#L47)                                                                                         |
| **Aucune clé stockée en base**                      | Clé uniquement en session, base64                                                            | [crypto.php](../includes/crypto.php#L42)                                                                                           |
| **Sessions sécurisées**                             | HttpOnly + SameSite=Strict + Secure auto en HTTPS + strict_mode + use_only_cookies           | [auth.php](../includes/auth.php#L58)                                                                                               |
| **Régénération d'ID post-login**                    | `session_regenerate_id(true)`                                                                | [auth.php](../includes/auth.php#L1012)                                                                                                     |
| **CSRF synchrone token**                            | 32 octets random_bytes + `hash_equals` vérif timing safe                                     | [auth.php](../includes/auth.php#L83)                                                                                             |
| **Défense contre l'injection SQL**                  | Le code utilise des requêtes préparées et des paramètres liés pour les valeurs dynamiques. Cela réduit le risque, sans prouver l'absence absolue de vulnérabilité. | Revue des requêtes dans `includes/`, `ajax/` et `pages/` |
| **Ownership à chaque accès**                        | `WHERE user_id = :user_id` systématiquement sur CRUD                                         | Toutes fonctions `lister_entrees`, `obtenir_entree`, `modifier_entree`, `supprimer_entree` dans [entrees.php](../includes/entrees.php)         |
| **Limitation des tentatives**                        | Trois axes de connexion (paire IP/courriel, IP globale, courriel global) et un axe distinct pour les inscriptions, avec seuils et durées configurables | [auth.php](../includes/auth.php) + [RATE_LIMITING.md](RATE_LIMITING.md) |
| **Expiration sessions**                             | Inactivité 5 min + durée absolue 1 h max                                                     | [auth.php](../includes/auth.php#L18) + [config.php](../config/config.php#L25)                                                                                                                                            |
| **Re-demande MDP maître**                            | Demandé avant l'export et le changement du mot de passe maître                               | [exporter.php](../ajax/exporter.php) + [changer_mdp.php](../pages/changer_mdp.php)                                                                                                                                                                            |
| **Journalisation structurée**                       | Table `journal_actions` avec action, détail, IP, UA, rétention configurable                  | [auth.php](../includes/auth.php#L502)                                                                                           |

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
| 5   | **Perte du mot de passe maître**                        | Aucun mécanisme de récupération n'est implémenté ; sans ce mot de passe, le coffre chiffré est inaccessible.                                                                                                                                    |
| 6   | **Extensions / userscripts Chrome malveillants installés par l'utilisateur lui-même**           | Content scripts non vérifiés par MV3 peuvent lire le DOM et voler ce que tu remplis.                                                                                                                                                         |
| 7   | **Phishing sur le coffre** (faux `password-manager.evil`)                                       | L'utilisateur peut être induit à saisir son MDP maître sur un site faux ; c'est de la vigilance humaine.                                                                                                                                     |

### 4.2 Limites techniques connues (non bloquantes)

| #   | Limite                                                                                                                               | Statut                                                                                                                                                                                                      |
| --- | ------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1   | Métadonnées `site`, `identifiant`, `categorie` en clair dans la base                                                                 | ⚖️ **Choix** : nécessité de requêtes SQL performantes LIKE / ORDER BY / GROUP BY. Une version chiffrées aurait besoin d'index aveugles (searchable encryption), complexité élevée. Documenté explicitement. |
| 2   | L'autofill exige désormais le même nom d'hôte après normalisation (schéma, chemin, port et `www.` ignorés)                             | ⚖️ Une entrée `google.com` ne couvre pas automatiquement `accounts.google.com`; enregistre le nom d'hôte exact pour chaque sous-domaine utilisé. Un domaine trompeur comme `evilgoogle.com` ne correspond pas. |
| 3   | L'extension injecte `content.js` à la demande dans l'onglet actif après ouverture du popup; son fonctionnement dépend des permissions Chrome `activeTab` et `scripting` | L'injection n'est pas automatique au chargement d'une page. Le manifeste n'utilise ni scripts de contenu statiques ni permission `<all_urls>`. |
| 4   | Pas d'audit de sécurité indépendant (pen-test par un pro payant)                                                                     | Indiqué dans SECURITY.md "as-is no warranty".                                                                                                                                                               |

---

## 5. Récupération de compte

Aucun mécanisme de récupération du mot de passe maître n'est implémenté dans le projet. Il n'existe ni réinitialisation par email, ni clé ou code de récupération, ni réinitialisation par un administrateur. La perte du mot de passe maître rend donc le coffre inaccessible.

---

## 6. Validation du modèle

- Ce document est la **source de vérité** pour toute question "est-ce que c'est un bug ? est-ce que je dois corriger ça ?"
- Toute nouvelle correction / durcissement dans le code devrait pouvoir être **explicitement justifiée** par une section §2 (adversaire) → §3 (garantie)
- Toute correction refusée / impossibilité devrait être ajoutée en §4 (limites connues)

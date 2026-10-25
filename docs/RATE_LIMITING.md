# Limitation des tentatives de connexion et d'inscription

## Vue d'ensemble (septembre 2026 — Défaut 3)

La limitation de connexion utilise **deux couches** :

| Couche | Objectif | Délai restant |
|---|---|---|
| 1. Historique (tentatives_login, tentatives_login_detail) | Compteurs + fenêtres glissantes pour **détecter** le dépassement des seuils. | Jamais affiché directement — sert juste de seuil. |
| 2. Verrous explicites (login_blocages V3, multi-axes) | **Source unique de vérité** pour l'affichage du délai restant (1 décrément/s). | Calculé par `TIMESTAMPDIFF(SECOND, NOW(), bloque_jusqu_a)` via `DateTimeImmutable` en PHP. |

Les verrous explicites sont la **seule** source utilisée par `connecter_utilisateur()` pour répondre "bloqué X s". Il n'y a plus de `MAX()` sur des constantes fixes, plus de plafonnement à 60 s.

---

## Les 3 axes de verrous (login_blocages.axe ENUM)

La même table stocke 3 types de verrous, discriminés par la colonne `axe` :

| Axe | Stockage | Seuil | Utilité |
|---|---|---|---|
| `PAIR_IP_EMAIL` | `<ip, email>` (identiques à la V2) | ≥ 3 échecs `<ip,email>` dans 5 min | Blocage de la paire (déjà existant). |
| `GLOBAL_IP` | `<ip, email=''>` (email vide) | ≥ 20 échecs `<ip>` confondus dans 1 h | **Brute-force horizontal** : une IP attaque N comptes différents → tous les comptes sur cette IP sont bloqués. |
| `GLOBAL_EMAIL` | `<ip='', email>` (ip vide) | ≥ 10 échecs `<email>` confondus dans 1 h | **Ciblage d'un compte** depuis plusieurs IP (botnet). |

Les durées des trois axes de connexion sont **escaladées** selon `infraction_n` sur 24 h (voir § escalade). L'inscription possède un quatrième axe distinct, `GLOBAL_IP_INSCRIPTION`, décrit ci-dessous.

## Limitation des inscriptions

Le code limite aussi les soumissions d'inscription par IP, qu'elles aboutissent ou non. Les valeurs par défaut dans `config/config.php` sont de 5 tentatives par heure, puis des blocages de 5 minutes, 1 heure et 24 heures selon l'escalade sur 48 heures. Cet axe utilise `GLOBAL_IP_INSCRIPTION` dans `login_blocages.axe` et a été ajouté par `migrations/004_add_global_ip_inscription.sql`. Une base créée avec le `database.sql` actuel contient déjà cette valeur; une base plus ancienne doit recevoir la migration.

---

## Escalade des durées (LOGIN_DUREE_*_SEC)

Chaque fois qu'un verrou est **posé**, le code regarde l'`infraction_n` maximale pour la même clé (axe + ip + email) créée dans les `LOGIN_ESCALADE_HISTORIQUE_H=24` dernières heures, et ajoute 1. Puis la durée est lue dans une table PHP. Valeurs par défaut dans [config.php](../config/config.php#L41) :

| infraction_n → | 1 | 2 | 3 (et +) |
|---|---|---|---|
| PAIR_IP_EMAIL  | 60 s      | 300 s (5 min) | 3600 s (1 h) |
| GLOBAL_IP      | 300 s (5 min) | 1800 s (30 min) | 21600 s (6 h) |
| GLOBAL_EMAIL   | 600 s (10 min) | 3600 s (1 h) | 28800 s (8 h) |

**Remarques de sécurité :**
- L'`ON DUPLICATE KEY UPDATE` utilise `GREATEST(bloque_jusqu_a, NOUVEAU)` : un verrou plus long existant n'est **jamais** raccourci par un plus court.
- `infraction_n` est mis à jour avec `GREATEST` aussi.
- Une infraction "oubliée" au bout de 24 h repart à `n=1`.

---

## Flux complet (vue boîte noire)

```
connecter_utilisateur(email, mdp)
│
├─ migrations/003_login_blocages_v3.sql          ← mise à niveau d'une ancienne table de verrous
├─ migrations/004_add_global_ip_inscription.sql   ← mise à niveau de l'ENUM pour le verrou d'inscription
│
├─ fusionner_statuts_verrous(ip, email)         ← 1 appel = 3 axes lus
│  ├─ statut_verrou_pair(email, ip)             → axe PAIR
│  ├─ statut_verrou_ip(ip)                      → axe GLOBAL_IP
│  └─ statut_verrou_email(email)                → axe GLOBAL_EMAIL
│  └─ RETURN { bloque: bool, secondes_restantes: MAX des 3, axes_declenches: [...] }
│
├─ SI bloque → journal + return [bloqué X s]     (SEUL message → utilisateur. X = MAX)
│
├─ SI mot de passe ÉCHEC
│  ├─ enregistrer_echec_login (ancienne table)
│  ├─ enregistrer_tentative_login_detail (1 ligne par échec)
│  │
│  ├─ SI COUNT(<ip,email>) ≥ 3 / 5 min  → poser_verrou_pair (escaladé)
│  ├─ SI COUNT(<ip>) ≥ 20 / 1 h         → poser_verrou_ip   (escaladé)
│  └─ SI COUNT(<email>) ≥ 10 / 1 h      → poser_verrou_email(escaladé)
│
└─ SI mot de passe OK
   ├─ reinitialiser_tentatives_login (ancienne table)
   ├─ supprimer_verrou_couple (PAIR + GLOBAL_IP + GLOBAL_EMAIL liés)
   ├─ session_regenerate_id(true)
   └─ ...
```

Points clés d'architecture :
- **Responsabilité unique** : 3 `poser_verrou_*()` séparés, 3 `statut_verrou_*()` séparés, 1 `fusionner_statuts_verrous()`.
- **Pas de couplage temporel** : le fallback par seuils (`statut_blocage_login_detail`) n'est **plus jamais utilisé** par le code applicatif (gardé pour compatibilité éventuelle de scripts exotiques, et marqué @deprecated implicitement).
- **Préfixage interne dans le message utilisateur** : les 3 axes produisent **exactement le même message** ("Compte temporairement bloqué. Réessayez dans X seconde(s).") ; la décomposition par axe n'est présente que dans les logs (`journal_actions.detail`).

---

## Mise à niveau du schéma des verrous

### Migrations explicites
Les migrations de schéma sont appliquées explicitement, jamais depuis une requête HTTP. `migrer_login_blocages_si_besoin()` est volontairement sans effet et ne met pas le schéma à jour. Pour une ancienne base, sauvegarde-la et applique les scripts dans l'ordre avec un client MySQL/MariaDB qui accepte les procédures stockées et `DELIMITER` :

```sql
SOURCE migrations/003_login_blocages_v3.sql;
SOURCE migrations/004_add_global_ip_inscription.sql;
```
`SOURCE` est une commande du client MySQL en ligne de commande, pas d'un formulaire SQL phpMyAdmin. Les scripts sont conçus pour être rejouables; effectue une sauvegarde avant de modifier une base existante.

---

## Debug SQL

### Lister les verrous actifs (quel axe ? quelle durée ?)
```sql
SELECT
  axe, ip, email, infraction_n,
  bloque_jusqu_a,
  TIMESTAMPDIFF(SECOND, NOW(), bloque_jusqu_a) AS sec_rest,
  created_at
FROM login_blocages
WHERE bloque_jusqu_a > NOW()
ORDER BY sec_rest DESC;
```

### Simuler l'escalade (faire expirer un verrou)
```sql
UPDATE login_blocages
SET bloque_jusqu_a = DATE_SUB(NOW(), INTERVAL 1 SECOND),
    created_at     = DATE_SUB(NOW(), INTERVAL 5 MINUTE)
WHERE axe='PAIR_IP_EMAIL' AND ip='10.0.0.1' AND email='bob@exemple.fr';
```
Puis re-déclencher 3 échecs sur cette paire : `infraction_n` doit passer à **2** et la durée à **300 s** (5 min).

### Vider les données de limitation pour reprendre un test propre

À exécuter uniquement sur une base locale de développement/test : ces commandes suppriment les historiques de tentatives, les blocages et les lignes de journal.
```sql
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE tentatives_login;
TRUNCATE tentatives_login_detail;
TRUNCATE login_blocages;
TRUNCATE journal_actions;
SET FOREIGN_KEY_CHECKS=1;
```

### Vérifier qu'une IP globale bloque un nouveau compte
Ce scénario est couvert par `testVerrouGlobalIpBloqueTousLesComptes` dans `tests/RateLimitTest.php`, qui configure un seuil de test à 4 et appelle les fonctions PHP. Le projet ne crée pas de procédure stockée `poser_verrou_ip`; le `CALL` parfois montré dans d'anciens exemples n'est pas utilisable. En usage normal, le seuil par défaut est 20 échecs par IP et par heure.

---

## Tests PHPUnit associés

Fichier [tests/RateLimitTest.php](../tests/RateLimitTest.php) — (base MySQL `password_manager_test`, créée/détruite par chaque exécution de la suite) :

| Nom du test | Vérifie |
|---|---|
| `testTroisEchecsBloquentCompte` | Historique (tentatives_login) — compatibilité ascendante |
| `testReinitialisationSucces` | Reset compteur sur succès |
| `testVerrouPair3Echecs` | PAIR_IP_EMAIL niveau 1 = 60 s |
| `testEscaladePairNiveau2` | 2e infraction sur la même clé → n=2 → 300 s |
| `testVerrouGlobalIpBloqueTousLesComptes` | GLOBAL_IP bloquant un **nouveau** compte sur la même IP |
| `testFusionPrendLeMaxDes3Axes` | Fusion MAX(60s, 300s, 0) = 300 s |
| `testConnecterUtilisateurBoucleBloquant` | Intégration bout-en-bout : 3 mauvais passwords → 4e renvoie `bloqué=1` |

Pour lancer les tests :
```bash
php vendor/bin/phpunit tests/RateLimitTest.php tests/AuthIntegrationTest.php --testdox
```
(Attention : les tests suppriment puis recréent explicitement la base `password_manager_test` et la suppriment à la fin. Ils lisent les paramètres MySQL du fichier `.env` à la racine. Ne les lance que si cette base est réservée aux tests et peut être détruite.)

---

## Anciennes conventions (compatibilité préservée)

Les éléments suivants existent toujours mais **ne pilotent plus** le blocage réel ; ils sont là pour que le legacy code / les scripts qui les appelleraient ne cassent pas :

| Ancien appelable | Remplacement |
|---|---|
| `statut_verrou_login($email, $ip)`  | `statut_verrou_pair()` — shim 1 ligne |
| `poser_verrou_login($email, $ip)`   | `poser_verrou_pair()` — shim 1 ligne |
| `supprimer_verrou_login($email, $ip)` | `supprimer_verrou_couple()` — shim 1 ligne, supprime aussi GLOBAL_IP et GLOBAL_EMAIL liés |
| `statut_blocage_login_detail()` | Fallback inutilisé — supprimé du flux de `connecter_utilisateur` |
| `statut_blocage_login()` (tentatives_login) | Compatibilité historique seulement |

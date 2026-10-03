# Rate limiting de connexion — Défaut 3 (2 axes historiques + escalade)

## Vue d'ensemble (septembre 2026 — Défaut 3)

Le rate-listing utilise **deux couches** :

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

Les 3 durées sont **escaladées** selon `infraction_n` sur 24 h (voir § escalade).

---

## Escalade des durées (LOGIN_DUREE_*_SEC)

Chaque fois qu'un verrou est **posé**, le code regarde l'`infraction_n` maximale pour la même clé (axe + ip + email) créée dans les `LOGIN_ESCALADE_HISTORIQUE_H=24` dernières heures, et ajoute 1. Puis la durée est lue dans une table PHP. Valeurs par défaut dans [config.php](file:///home/dev-chris06/dev/password-manager/config/config.php#L41-L76) :

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
├─ migrer_login_blocages_si_besoin()            ← auto-migration V2→V3 safe si besoin
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

## Migration V2 → V3 (idempotente)

### Auto-migration à la volée (sans intervention)
La fonction [migrer_login_blocages_si_besoin](file:///home/dev-chris06/dev/password-manager/includes/auth.php#L443-L505) est appelée à chaque début de `connecter_utilisateur()` :
- Si `login_blocages` contient déjà la colonne `axe` (V3) → **no-op** (coût : 1 `SHOW COLUMNS` mis en cache par le `static $fait`).
- Si la table est en V2 → on SELECT toutes les lignes existantes → DROP + CREATE V3 → réinsertion en `axe='PAIR_IP_EMAIL'`, `infraction_n=1`.

### Migration manuelle (recommandé pour être sûr)
Le fichier SQL standalone [migrations/003_login_blocages_v3.sql](file:///home/dev-chris06/dev/password-manager/migrations/003_login_blocages_v3.sql) contient la même logique mais dans une procédure stockée éphémère `_migrate_login_blocages_v3()`. À jouer dans phpMyAdmin/Heidi :

```sql
SOURCE migrations/003_login_blocages_v3.sql;
```
Rejouable sans effet de bord, et ne casse jamais les lignes existantes.

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

### Vider rapidement tout pour reprendre un test clean
```sql
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE tentatives_login;
TRUNCATE tentatives_login_detail;
TRUNCATE login_blocages;
TRUNCATE journal_actions;
SET FOREIGN_KEY_CHECKS=1;
```

### Vérifier qu'une IP globale bloque un nouveau compte
1. Générer 4 échecs depuis `10.0.0.99` sur 4 emails distincts (le test utilise un seuil réduit LOGIN_SEUIL_IP_MAX_1H=4).
2. Poser le verrou : `CALL poser_verrou_ip('10.0.0.99')` ou en PHP.
3. Une 5e tentative sur un email **jamais** vu : `fusionner_statuts_verrous('10.0.0.99', 'toto@exemple.fr')['bloque']` doit être **true**, et la durée celle de GLOBAL_IP (5 min au niveau 1).

---

## Tests PHPUnit associés

Fichier [tests/RateLimitTest.php](file:///home/dev-chris06/dev/password-manager/tests/RateLimitTest.php) — (base MySQL `password_manager_test`, créée/ détruite par chaque run de la suite) :

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
(Prérequis : l'utilisateur MySQL en `config/.env` doit avoir le droit de `CREATE/DROP DATABASE password_manager_test`.)

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

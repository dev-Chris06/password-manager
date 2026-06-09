# Rate limiting de connexion

## Implémentation

Le rate limiting utilise la table `tentatives_login` :

- `email` est unique via `UNIQUE KEY uq_email (email)`.
- `ip` stocke l'adresse IP tronquée à 45 caractères pour le blocage global.
- `nb_tentatives` compte les échecs.
- `derniere_tentative` trace le dernier essai.
- `bloque_jusqu_a` contient la fin du blocage temporaire.

La fonction `enregistrer_echec_login()` utilise `INSERT ... ON DUPLICATE KEY UPDATE` pour créer ou mettre à jour la ligne sans course applicative simple. Après 3 échecs, `bloque_jusqu_a` est défini à `NOW() + 60 secondes`.

La fonction `statut_blocage_login()` calcule le temps restant avec `TIMESTAMPDIFF(SECOND, NOW(), bloque_jusqu_a)`, côté SQL, pour éviter les écarts de timezone entre PHP et MySQL.

## Rate limiting par IP

En plus du blocage par email, l'application compte les tentatives récentes par adresse IP. Si une même IP atteint 20 tentatives dans la dernière heure, la connexion est refusée temporairement avec un délai indicatif de 3600 secondes.

La colonne `ip` et l'index `idx_tentatives_ip` sont donc nécessaires dans `tentatives_login`. Ils sont présents dans `database.sql` pour les nouvelles installations et dans `migrations/add_ip_to_tentatives_login.sql` pour les bases déjà créées.

## Flux

1. L'utilisateur soumet email et mot de passe.
2. Le code vérifie d'abord si un blocage actif existe.
3. Si `bloque_jusqu_a <= NOW()`, le compteur est remis à `0` et `bloque_jusqu_a = NULL`.
4. Si le compte est encore bloqué, la connexion est refusée avec un message générique de blocage temporaire.
5. Si l'authentification échoue, le compteur est incrémenté.
6. Si l'authentification réussit, le compteur est remis à zéro.

## Bugs potentiels évités

- Sans clé unique sur `email`, `ON DUPLICATE KEY UPDATE` ne se déclenche pas.
- Un calcul PHP du délai restant peut être faux si PHP et MySQL n'utilisent pas la même timezone.
- Un blocage expiré non réinitialisé laisse un état confus en base.
- Un message trop précis peut révéler si un email existe ; l'application conserve des erreurs génériques.

## Debug

Vérifier une ligne :

```sql
SELECT email, ip, nb_tentatives, derniere_tentative, bloque_jusqu_a,
       TIMESTAMPDIFF(SECOND, NOW(), bloque_jusqu_a) AS secondes_restantes
FROM tentatives_login
WHERE email = 'test@example.com';
```

Réinitialiser un compte de test :

```sql
UPDATE tentatives_login
SET nb_tentatives = 0, bloque_jusqu_a = NULL, derniere_tentative = NOW()
WHERE email = 'test@example.com';
```

Pour valider le scénario, faire 3 connexions échouées, constater un blocage d'environ 60 secondes, attendre l'expiration, puis vérifier que `nb_tentatives` repasse à `0`.

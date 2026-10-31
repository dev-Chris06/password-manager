# Restaurer une sauvegarde

Une sauvegarde (`coffre-AAAA-MM-JJ.json`, format `pm-backup-v2`) est **autonome** : elle contient la clé du coffre chiffrée, les paramètres de dérivation et les entrées chiffrées. Elle s'ouvre avec le **mot de passe maître et la Secret Key valables au moment de l'export**, sans dépendre du serveur d'origine.

## Restaurer dans un coffre existant

1. Se connecter, ouvrir **Paramètres → Sauvegarde → Importer**.
2. Choisir le fichier, saisir le mot de passe maître **de la sauvegarde**.
3. Saisir sa Secret Key si elle diffère de la Secret Key actuelle (sinon laisser vide).
4. Les entrées sont déchiffrées dans le navigateur, rechiffrées avec la clé actuelle du coffre, puis envoyées. Celles qui existent déjà (même site et même identifiant) sont ignorées.

## Restaurer après la perte du serveur

1. Réinstaller l'application ([mutualisé](deploy/mutualise.md) ou [VPS](deploy/vps.md)).
2. Créer un nouveau compte (nouveau mot de passe maître possible, nouvelle Secret Key, nouveau kit de secours, double authentification).
3. Importer la sauvegarde en saisissant le mot de passe maître **et la Secret Key de l'ancien kit de secours**.

## Test de restauration documenté

Procédure à refaire après chaque changement majeur. Elle est aussi automatisée dans `tests/e2e/backup.spec.ts` (« a backup survives the loss of the whole database »).

| Étape | Attendu |
|---|---|
| Installation propre, inscription, double authentification | Coffre vide |
| Ajouter 20 entrées variées (accents, notes longues, catégories) | 20 entrées |
| Exporter la sauvegarde | Fichier `coffre-…json` ; aucun nom de site lisible dedans |
| Supprimer la base (`doctrine:database:drop`), réinstaller, recréer un compte | Coffre vide |
| Importer avec l'ancien mot de passe maître et l'ancienne Secret Key | « 20 entrée(s) importée(s) » |
| Vérifier quelques entrées (mot de passe affiché, copie) | Identiques aux originales |
| Importer une deuxième fois | « 0 entrée(s) importée(s), 20 déjà présente(s) ignorée(s) » |
| Modifier un octet du fichier (ou baisser `iterations`) | Import refusé |

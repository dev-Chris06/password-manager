# Extension Chrome - Gestionnaire MDP

Cette extension Manifest V3 permet de remplir automatiquement un formulaire de connexion avec les entrées du gestionnaire PHP local.

## Installation en mode développeur

1. Ouvrir Chrome.
2. Aller sur `chrome://extensions`.
3. Activer le mode développeur.
4. Cliquer sur `Charger l'extension non empaquetée`.
5. Sélectionner le dossier `extension-chrome`.
6. Se connecter au gestionnaire dans le même navigateur : `http://localhost/projet_ameliore/pages/login.php`.
7. Ouvrir une page qui contient un formulaire de connexion.
8. Cliquer sur l'icône de l'extension, choisir un compte, puis valider.

## Fonctionnement

- Le popup lit le domaine de l'onglet actif.
- Il appelle `http://localhost/projet_ameliore/ajax/remplissage.php?domaine=...` avec `credentials: "include"`.
- Le cookie PHP `gestionnaire_mdp_session` reste HttpOnly.
- L'endpoint retourne seulement les métadonnées des entrées et le token CSRF courant.
- Au clic sur une entrée, le popup appelle `ajax/dechiffrer.php` avec `entry_id` et `csrf_token`.
- Le mot de passe déchiffré est envoyé directement au content script pour remplir le champ.

## Suggestion pendant la création d'un compte

- Clique dans un champ `mot de passe` sur une page d'inscription.
- Une carte apparaît près du champ avec :
  - un mot de passe fort généré localement ;
  - un champ identifiant prérempli si l'extension trouve l'e-mail ou le téléphone ;
  - un bouton `Utiliser + enregistrer`.
- Tu peux modifier le mot de passe proposé avant de l'utiliser.
- Quand tu cliques sur `Utiliser + enregistrer`, l'extension :
  1. remplit les champs du site ;
  2. récupère le token CSRF courant via `ajax/remplissage.php` ;
  3. envoie l'entrée à `ajax/enregistrer_extension.php` ;
  4. le serveur chiffre le mot de passe et l'ajoute au dashboard.

## Contraintes respectées

- Aucun JWT.
- Aucune clé de chiffrement dans l'extension.
- Aucun mot de passe déchiffré dans `chrome.storage`.
- Pas de log console contenant un secret.
- La cryptographie reste entièrement côté serveur PHP.

## Configuration de l'URL locale

L'URL par défaut est :

```text
http://localhost/projet_ameliore
```

Elle peut être modifiée dans le champ du popup. La valeur est stockée dans `chrome.storage.local`, mais elle ne contient aucun secret.

## Remarque session

L'extension dépend de la session PHP active. Si le popup affiche `Session expirée`, reconnecte-toi d'abord au gestionnaire dans le même profil Chrome.

## Permissions requises

L'extension demande les permissions suivantes dans `manifest.json` :

- **`activeTab`** : Permet à l'extension de lire l'URL de l'onglet actif pour détecter le domaine courant et proposer les entrées correspondantes.
- **`storage`** : Permet de stocker localement l'URL du gestionnaire (configurable par l'utilisateur) dans `chrome.storage.local`. Aucun secret n'est stocké.
- **`cookies`** : Permet d'envoyer les cookies avec les requêtes vers le gestionnaire local pour maintenir la session PHP authentifiée.

### `host_permissions`

L'extension est limitée aux hôtes suivants pour des raisons de sécurité :

- `http://localhost/*`
- `http://127.0.0.1/*`

Ces permissions restreignent l'extension à ne communiquer qu'avec le gestionnaire local, empêchant tout appel vers des serveurs externes.

### Content scripts

Le script `content.js` est injecté sur tous les sites (`http://*/*` et `https://*/*`) pour :
- Détecter les champs de mot de passe
- Afficher le bouton de suggestion lors de la création de compte
- Remplir automatiquement les identifiants

Toute la logique de chiffrement/déchiffrement reste côté serveur PHP.

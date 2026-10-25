# Extension Chrome - Gestionnaire MDP

Cette extension Manifest V3 est prévue pour utiliser le gestionnaire PHP local. Le manifeste autorise les connexions HTTP vers `localhost` et `127.0.0.1`. Elle utilise `activeTab`, `scripting` et `storage`; elle ne demande pas la permission Chrome `cookies`.

## Installation en mode développeur

1. Ouvrir Chrome ou un navigateur Chromium compatible.
2. Aller sur `chrome://extensions` et activer le mode développeur.
3. Cliquer sur `Charger l'extension non empaquetée` et sélectionner `extension-chrome/`.
4. Lancer l'application PHP en suivant [le guide d'installation](../INSTALL.md).
5. Dans le popup de l'extension, configurer l'URL locale réelle de l'application. La valeur initiale est `http://localhost/password-manager`; avec le serveur PHP intégré configuré dans `INSTALL.md`, l'URL est `http://localhost:8000`.
6. Se connecter au gestionnaire dans le même profil de navigateur.

## Fonctionnement prévu et état actuel

- Le popup lit l'hôte de l'onglet actif et demande à `ajax/remplissage.php` les métadonnées des entrées correspondantes.
- Les hôtes sont comparés après suppression du préfixe `www.`. Les sous-domaines doivent être enregistrés séparément.
- Le popup demande le déchiffrement à `ajax/dechiffrer.php` et transmet le mot de passe au script de contenu pour remplir le formulaire.
- La session PHP est envoyée avec `credentials: "include"`; son cookie reste HttpOnly. L'extension ne lit pas directement le cookie.
- Le manifeste ne déclare aucun `content_script` statique. À l'ouverture du popup, celui-ci vérifie si le script répond déjà; si nécessaire, il l'injecte dans l'onglet actif avec `chrome.scripting.executeScript`. L'injection est déclenchée par l'ouverture du popup, donc par une action de l'utilisateur, et évite de charger le script deux fois dans le même onglet.

## Suggestion lors de la création d'un compte

Le script de contenu comprend une interface de suggestion : génération locale d'un mot de passe, remplissage de champs et enregistrement dans le coffre via `ajax/enregistrer_extension.php`. Après activation de l'extension depuis son popup, la suggestion peut apparaître lorsqu'un champ de mot de passe est sélectionné.

## Sécurité et données locales

- Aucun JWT ni clé de chiffrement n'est stocké dans l'extension.
- L'URL du gestionnaire est conservée dans `chrome.storage.local`; aucun secret n'y est enregistré.
- Le mot de passe est déchiffré côté serveur puis transmis au script de contenu pour le remplissage.
- La configuration de base n'autorise que `http://localhost/*` et `http://127.0.0.1/*` comme hôtes de l'application. L'URL doit donc utiliser l'un de ces hôtes et le schéma HTTP tant que les autorisations HTTPS ne sont pas ajoutées au manifeste.

## Permissions déclarées

- `activeTab` : accès temporaire à l'onglet actif après interaction avec l'extension.
- `scripting` : injection du script de contenu à la demande.
- `storage` : sauvegarde de l'URL locale choisie.
- `host_permissions` : accès aux endpoints du gestionnaire sur `localhost` et `127.0.0.1` en HTTP.

Les scripts de contenu ne sont pas configurés pour s'injecter automatiquement sur tous les sites dans `manifest.json`.

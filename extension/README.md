# Extension Chrome

Remplit vos identifiants depuis votre coffre. **Tout le déchiffrement a lieu dans l'extension** : son code est installé localement et ne dépend pas du JavaScript servi par le serveur.

Navigateurs pris en charge : Chrome, Chromium, Edge (Manifest V3, version 120 ou plus).

## Installation (mode développeur)

```bash
npm ci
npm run build          # produit extension/dist/
```

1. Ouvrir `chrome://extensions`, activer le **mode développeur**.
2. **Charger l'extension non empaquetée** et choisir le dossier `extension/dist/`.
3. Identifiant de l'extension : `nfnbankmkanaglimeogpgljgpkpdbenl` (fixé par la clé publique du manifeste).
4. Sur le serveur, autoriser cette origine dans `.env.local` :

   ```dotenv
   EXTENSION_ORIGIN=chrome-extension://nfnbankmkanaglimeogpgljgpkpdbenl
   ```

## Utilisation

1. Ouvrir la popup, saisir l'adresse du coffre (HTTPS, ou `http://localhost` en développement) : Chrome demande l'autorisation d'accéder à ce seul serveur.
2. Déverrouiller avec l'email, le mot de passe maître et, la première fois, la Secret Key (kit de secours). Si le navigateur n'a pas de session ouverte sur le coffre, la double authentification est demandée.
3. Sur une page de connexion, ouvrir la popup : les comptes du site s'affichent, **Remplir** complète le formulaire.

La double authentification doit avoir été activée depuis l'application web avant la première utilisation.

## Sécurité

| Mesure | Détail |
|---|---|
| Aucun script permanent dans les pages | Pas de content script ni de menu injecté : les pages ne peuvent ni lire ni recouvrir l'interface de l'extension (protection contre le *clickjacking* d'extension). Le formulaire n'est rempli qu'après un clic dans la popup. |
| Domaine lu par le navigateur | L'adresse de la page vient de `chrome.tabs`, jamais d'un message. Correspondance exacte de l'hôte ; sous-domaines seulement si l'entrée l'autorise. |
| Pages refusées | HTTP (hors `localhost`), champ de mot de passe masqué ou recouvert, page qui a changé d'origine entre le clic et le remplissage. |
| Messages | Le service worker n'accepte que les messages des pages de l'extension elle-même. |
| Clé du coffre | Gardée dans `chrome.storage.session` (en mémoire, accès réservé à l'extension, effacé à la fermeture du navigateur). Verrouillage automatique après 15 minutes d'inactivité. |
| Paramètres de dérivation | Refus de paramètres sous le plancher ; alerte s'ils baissent par rapport à ceux déjà vus pour ce compte. |
| Secret Key | Mémorisée dans `chrome.storage.local` de cet appareil uniquement. |

## Build de test

`node tools/build.mjs --e2e` produit une variante réservée aux tests Playwright (autorisations d'hôte accordées d'avance, onglet cible désigné par l'URL de la popup). Ne jamais la distribuer.

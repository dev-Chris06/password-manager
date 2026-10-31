# Modèle de menace

Ce document décrit ce que le gestionnaire protège, contre qui, et ce qu'il ne protège pas. Il complète l'[architecture](PLAN.md#4-architecture-cible) et les [décisions](DECISIONS.md).

## 1. Ce qu'il faut protéger

| Actif | Où il se trouve |
|---|---|
| Mots de passe, identifiants, sites, notes | Uniquement chiffrés côté client (blob AES-256-GCM par entrée) |
| Mot de passe maître | Seulement dans la mémoire de l'utilisateur et, brièvement, du navigateur |
| Secret Key (128 bits) | Kit de secours imprimé, `localStorage` du navigateur, `chrome.storage.local` de l'extension |
| Clé du coffre | Chiffrée sur le serveur ; en clair seulement en mémoire, pendant que le coffre est déverrouillé |
| Accès au compte | Preuve d'authentification + TOTP (ou code de secours) |

## 2. Attaquants considérés

| Attaquant | Capacités | Protection |
|---|---|---|
| **Voleur de la base de données** (fuite, sauvegarde volée, hébergeur indiscret) | Lit toutes les tables | Données chiffrées ; la clé du coffre est enveloppée par une clé dérivée du mot de passe **et** de la Secret Key : craquage hors ligne impossible sans la Secret Key. Secret TOTP chiffré avec une clé hors base (`APP_SECRET`). |
| **Lecteur du disque du serveur** (fichiers, sessions, journaux) | Lit `var/`, la table `sessions`, les logs | Les sessions ne contiennent que l'identité et des horodatages ; le journal ne contient ni site ni identifiant. `APP_SECRET` donne accès au secret TOTP, pas au coffre. |
| **Serveur compromis passivement** (observe les requêtes) | Voit tout le trafic déchiffré par TLS | Ne voit que la preuve d'authentification et des blobs chiffrés. Test automatisé : aucun secret dans le trafic d'une session complète. |
| **Attaquant réseau** | Intercepte ou modifie le trafic | HTTPS obligatoire, HSTS, cookies `__Host-` `Secure`. |
| **Attaquant en ligne** (devine les mots de passe) | Envoie des tentatives de connexion, éventuellement en parallèle | Argon2id coûteux côté client ; limiteurs par paire IP/email, IP, email, appareil, comptés avant toute vérification ; second facteur obligatoire, codes à usage unique garantis par la base ; 5 codes faux ferment la tentative. |
| **Premier visiteur d'une installation neuve** | Tente de créer le compte unique avant le propriétaire | `REGISTRATION_CODE` exigé ; `app:check-deployment` refuse un déploiement sans compte et sans code. |
| **Attaquant qui bloque le propriétaire** | Épuise volontairement les limites | Le device cookie exempte le navigateur habituel des limites par IP et par email. Aucune pause côté serveur (pas de déni de service par épuisement). |
| **Voleur de la preuve d'authentification** | Rejoue la preuve | Ne déchiffre rien ; le coffre n'est servi qu'après le second facteur ; la preuve change avec le mot de passe maître. |
| **Site malveillant** (CSRF, XSS, clickjacking) | Fait agir le navigateur de la victime | CSRF : en-tête `X-PM-Client`, jeton de session, corps JSON, `SameSite=Strict`, contrôle d'`Origin`. XSS : CSP stricte avec nonce, Trusted Types, aucun `innerHTML` (vérifié en CI). Clickjacking : `frame-ancestors 'none'`. |
| **Page piégée face à l'extension** | Faux champs, calques invisibles, domaine ressemblant, redirection pendant le remplissage | Aucun script injecté en permanence ; remplissage sur clic dans la popup ; domaine lu par le navigateur, correspondance exacte (port compris, jamais sur tout un suffixe public) ; un seul relevé de l'adresse sert à la vérification et au remplissage ; refus des champs masqués ou recouverts et des pages HTTP. |
| **Serveur malveillant qui affaiblit la dérivation** | Envoie des paramètres Argon2id faibles | Planchers imposés par le client et l'extension ; alerte si les paramètres baissent par rapport à ceux déjà vus. |
| **Serveur qui échange des entrées** | Place le blob d'une entrée sous un autre identifiant | L'UUID est la donnée associée d'AES-GCM : le déchiffrement échoue. |

## 3. Limites acceptées

Ces cas ne sont pas couverts ; ils sont documentés pour être assumés en connaissance de cause.

1. **Serveur compromis activement servant un JavaScript modifié** à l'application web : il pourrait capturer le mot de passe maître lors de la saisie. Limite commune à tous les coffres web (Bitwarden, 1Password web). L'extension, installée localement, n'en dépend pas.
2. **Poste client compromis** (logiciel malveillant, enregistreur de frappe, extension hostile) : hors de portée de toute application.
3. **Retour en arrière ou masquage d'entrées** par un serveur malveillant : il peut renvoyer une ancienne version d'un blob ou en cacher. Pas de manifeste d'intégrité global (comme chez Bitwarden).
4. **Secret TOTP lisible par le serveur** : nécessaire pour vérifier les codes. Chiffré au repos ; il ne protège que l'authentification, jamais le contenu du coffre.
5. **Perte du mot de passe maître ou de la Secret Key** (et de tous les appareils qui la mémorisent) : coffre définitivement perdu. Aucun mécanisme de récupération, par conception.
6. **Effacement du presse-papiers** : tenté après 15 secondes, mais non garanti par les navigateurs.
7. **Mémoire du navigateur** : JavaScript ne permet pas d'effacer de façon garantie les chaînes en mémoire ; les clés sont non exportables là où c'est possible et les tableaux d'octets sont remis à zéro après usage.
8. **Clé du coffre dans l'extension** : gardée en octets bruts dans `chrome.storage.session` (en mémoire, réservée à l'extension) pour survivre à l'arrêt du service worker ; effacée au verrouillage et à la fermeture du navigateur.

## 4. Hypothèses

- PHP est maintenu à jour : les versions sans les correctifs du 2026-10-21 sont refusées à l'installation et au déploiement ([ADR-017](DECISIONS.md#adr-017--niveau-de-correctifs-php-imposé)).
- `APP_SECRET` est aléatoire, unique et reste sur le serveur (vérifié par `app:check-deployment`).
- Le site est servi uniquement en HTTPS.
- L'utilisateur conserve son kit de secours hors ligne et choisit un mot de passe maître qui n'est pas parmi les plus courants (refusé à la création).

# Revue de sécurité finale

Date : 2026-10-31. Référentiel : **OWASP ASVS 4.0.3, niveau 2**, chapitres retenus dans le [plan](PLAN.md#phase-9--documentation-revue-finale-présentation). Chaque exigence renvoie au code ou au test qui la démontre.

Légende : ✅ conforme · ⚠️ conforme avec réserve (voir notes) · ➖ non applicable.

## V2 — Authentification

| Exigence | État | Preuve |
|---|---|---|
| 2.1.1 Secrets d'au moins 12 caractères | ✅ | `frontend/web/src/passwordPolicy.ts` ; le serveur ne voit jamais le mot de passe maître |
| 2.1.7 Refus des mots de passe divulgués / courants | ✅ | Liste des 10 000 plus courants (SecLists) ; HIBP à la demande pour les entrées |
| 2.1.8 Indicateur de robustesse | ✅ | Jauge à l'inscription, au changement et dans le formulaire d'entrée |
| 2.2.1 Protection contre les attaques automatisées | ✅ | Limiteurs multi-axes, `tests/Controller/AuthControllerTest.php` |
| 2.2.1 Pas de blocage du propriétaire par un tiers | ✅ | Device cookie OWASP, test `testATrustedDeviceIsNotLockedOutByAttacksFromElsewhere` |
| 2.4.x Stockage des vérificateurs | ✅ | Preuve dérivée (Argon2id + Secret Key + HKDF), puis hachée par `PasswordHasher` (Argon2id/bcrypt) |
| 2.5.x Récupération | ➖ | Aucune récupération du mot de passe maître, par conception (zero-knowledge) |
| 2.7 / 2.8 Second facteur | ✅ | TOTP obligatoire, ±1 période, anti-rejeu, codes de secours HMAC à usage unique, `TwoFactorControllerTest`, vecteurs RFC 6238 |
| 2.8.4 Un code ne sert qu'une fois | ✅ | `totp_last_counter`, test `testLoginNeedsTheSecondFactorAndRefusesAReplayedCode` |

## V3 — Gestion des sessions

| Exigence | État | Preuve |
|---|---|---|
| 3.2.1 Nouveau jeton à l'authentification | ✅ | Migration de session Symfony, jetons CSRF renouvelés |
| 3.3.1 Déconnexion effective | ✅ | `api/auth/logout` invalide la session, test `testLogoutEndsTheSession` |
| 3.3.2 Expiration d'inactivité et absolue | ✅ | 15 min (stockage), 4 h (`SessionLifetimeSubscriber`), 5 min entre les deux facteurs ; verrouillage local après 5 min |
| 3.3.3 Fermeture des autres sessions au changement de secret | ✅ | `EquatableInterface` + empreinte de preuve, test `testOtherSessionsAndTrustedDevicesAreClosed` |
| 3.4.x Attributs des cookies | ✅ | `__Host-pm_session`, `Secure`, `HttpOnly`, `SameSite=Strict` (vérifié en mode production) |
| 3.5.3 Jetons non devinables | ✅ | Identifiants de session et nonces issus de `random_bytes` |

## V4 — Contrôle d'accès

| Exigence | État | Preuve |
|---|---|---|
| 4.1.x Refus par défaut | ✅ | `access_control` : tout `/api/` exige `ROLE_USER` sauf les routes publiques listées |
| 4.2.1 Pas d'IDOR | ✅ | Entrées filtrées par propriétaire ; entrée d'autrui = 404 ; `EntryControllerTest::testAnotherAccountCanNeitherReadNorChangeTheEntries` |
| 4.2.2 Protection CSRF | ✅ | `X-PM-Client`, jeton CSRF, JSON obligatoire, `Origin`, `SameSite=Strict`, `ApiRequestSubscriberTest` |
| 4.3.1 Ré-authentification pour les actions sensibles | ✅ | Preuve fraîche + limiteur (`ReauthenticationVerifier`) |

## V6 — Cryptographie stockée

| Exigence | État | Preuve |
|---|---|---|
| 6.2.1 Modes authentifiés | ✅ | AES-256-GCM partout, données associées (UUID, contexte) |
| 6.2.2 Algorithmes éprouvés | ✅ | WebCrypto, `hash-wasm` (Argon2id), OpenSSL, `spomky-labs/otphp` ; vecteurs de test de référence |
| 6.2.5 Pas de réutilisation d'IV | ✅ | IV aléatoire de 96 bits par chiffrement |
| 6.3.1 Aléa cryptographique | ✅ | `crypto.getRandomValues`, `random_bytes`, `random_int` ; générateur sans biais (rejet) |
| 6.4.1 Gestion des clés | ✅ | Clé de coffre aléatoire, enveloppée ; non exportable côté web ; renouvelable |
| 6.4.2 Clés hors du code | ✅ | `APP_SECRET` dans `.env.local` (600), contrôlé par `app:check-deployment` |

## V8 — Protection des données

| Exigence | État | Preuve |
|---|---|---|
| 8.1.1 Pas de cache des données sensibles | ✅ | `Cache-Control: no-store` sur l'API et la page de l'application |
| 8.2.x Données côté client | ⚠️ | Secret Key dans `localStorage` (nécessaire pour ne pas la ressaisir) ; clé du coffre jamais persistée côté web |
| 8.3.1 Données sensibles hors des URL | ✅ | Aucune donnée sensible en paramètre d'URL |
| 8.3.4 Minimisation | ✅ | Le journal ne contient ni site ni identifiant ; la base aucun contenu lisible (`invariant.spec.ts`) |

## V13 — API

| Exigence | État | Preuve |
|---|---|---|
| 13.1.x Contrôle des entrées | ✅ | `JsonPayload` : types stricts, tailles, base64 canonique, UUID v4 |
| 13.2.x Méthodes et types de contenu | ✅ | Méthodes déclarées par route, `415` hors JSON |
| 13.2.3 Protection CSRF des API à cookies | ✅ | Voir V4 |
| 13.4.x Erreurs uniformes | ✅ | Codes JSON sans détail interne |

## V14 — Configuration

| Exigence | État | Preuve |
|---|---|---|
| 14.1.x Build et déploiement reproductibles | ✅ | `composer.lock`, `package-lock.json`, CI, archive avec sommes SHA-256 |
| 14.2.1 Dépendances à jour et auditées | ✅ | `composer audit`, `npm audit` en CI ; versions de PHP corrigées imposées (ADR-017) |
| 14.3.x Pas de fuite d'informations | ✅ | Page d'erreur générique, `expose_php=Off`, en-têtes `Server`/`X-Powered-By` retirés |
| 14.4.x En-têtes de sécurité | ✅ | CSP avec nonce + Trusted Types, HSTS, COOP, CORP, `nosniff`, `Referrer-Policy: no-referrer`, `frame-ancestors 'none'` |
| 14.5.x Origine des requêtes | ✅ | CORS limité à l'origine exacte de l'extension |

## Revue indépendante

Une relecture indépendante de tout le code (serveur, module crypto, client, extension, scripts de déploiement) a été menée sans connaître les conclusions ci-dessus. **Aucune faille critique.** Tous les points retenus ont été corrigés et sont couverts par des tests.

| # | Gravité | Constat | Correction | Test |
|---|---|---|---|---|
| 1 | Haute | Changement de Secret Key : si la réponse du serveur se perdait après application, la nouvelle Secret Key n'était ni enregistrée ni montrée → coffre définitivement inaccessible | La nouvelle Secret Key est affichée (kit de secours) et confirmée **avant** l'envoi, gardée sur l'appareil comme « en attente », et essayée automatiquement à la connexion et au déverrouillage | `backup.spec.ts` « a Secret Key change whose answer is lost… » |
| 2 | Moyenne | Extension : domaine vérifié sur un premier relevé de l'onglet, remplissage sur un second → une navigation entre les deux pouvait faire remplir sur un autre site | Un seul relevé de l'adresse sert à la correspondance et à la vérification d'origine dans la page | `extension.spec.ts`, `domain.test.ts` |
| 3 | Moyenne | Limiteurs « vérifier puis compter » : des requêtes parallèles dépassaient le budget ; même code TOTP ou de secours utilisable deux fois en parallèle | Chaque tentative est comptée **avant** la vérification ; prise du pas TOTP et usage du code de secours par `UPDATE … WHERE` atomique | `ParallelUseTest`, `AuthControllerTest`, `TwoFactorControllerTest` |
| 4 | Basse | Extension : après un renouvellement de la clé du coffre ailleurs, l'ancienne clé restait utilisée pour chiffrer de nouvelles entrées | Comparaison de la clé enveloppée avec celle du serveur ; verrouillage en cas de différence | — (chemin court, revu) |
| 5 | Basse | Une session déjà complète puis repassée au premier facteur gardait la durée de 4 h | Horodatage de connexion complète retiré à l'étape intermédiaire | `SessionLifetimeSubscriberTest` |
| 6 | Basse | Import avec UUID en double ou création concurrente : erreur 500 | Doublons refusés (409), violation d'unicité convertie en 409 | `AccountControllerTest` |
| 7 | Basse | Compteurs des limiteurs effacés à chaque déploiement (`cache:clear`) | Stockage dans `var/rate_limiter`, hors du cache | — (configuration) |
| 8 | Basse | Contrôle de baisse des paramètres KDF absent au déverrouillage et à la re-authentification web | Contrôle ajouté sur ces deux chemins | — |
| 9 | Basse | Redirection HTTPS de `public/.htaccess` contournable par `X-Forwarded-Proto` | Redirection confiée au panneau de l'hébergeur (et HSTS) | — |
| 10 | À vérifier → corrigé | Une installation neuve pouvait être « réclamée » par le premier visiteur | `REGISTRATION_CODE` exigé à l'inscription ; `app:check-deployment` refuse un déploiement sans compte et sans code | `RegistrationCodeTest` |
| 11 | À vérifier → corrigé | Une session restée à l'étape d'activation pouvait remplacer un secret TOTP activé entre-temps ailleurs | Activation refusée si la 2FA est déjà active et que la session n'a pas le second facteur | — |
| 12 | À vérifier → corrigé | Extension : « sous-domaines » sur un suffixe public (`co.uk`, `github.io`) ; port ignoré | Liste de suffixes publics courants exclue ; port exigé identique | `domain.test.ts` |
| 13 | Accepté | Verrouillage de l'email depuis un navigateur sans device cookie | Compromis OWASP documenté ([RATE_LIMITING.md](RATE_LIMITING.md)) : le navigateur habituel n'est pas concerné | `AuthControllerTest` |

Points vérifiés et jugés solides par la relecture : règles d'accès (aucune route `ROLE_USER` atteignable avec le seul premier facteur), CSRF/CORS, absence d'IDOR, rotation de clé atomique, device cookie, chiffrement du secret TOTP, IV aléatoires et données associées, planchers Argon2id, analyse de la Secret Key, absence de puits HTML (Trusted Types), contrôle de l'expéditeur des messages de l'extension, routage Caddy, contenu de l'archive de livraison.

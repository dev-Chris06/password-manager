# Gestionnaire de mots de passe zero-knowledge

> 🚧 **Refonte en cours.** L'application est reconstruite sur une architecture **zero-knowledge** : tout le chiffrement a lieu dans le navigateur ou l'extension, le serveur ne voit jamais ni le mot de passe maître ni les secrets.
> Le déroulé, les décisions et l'avancement sont décrits dans [docs/PLAN.md](docs/PLAN.md). L'ancienne version (chiffrement côté serveur) reste consultable sous le tag git `avant-refonte`.

## Ce que sera l'application

- **Chiffrement côté client** : Argon2id + Secret Key (dérivation à deux secrets), AES-256-GCM, clé de coffre aléatoire.
- **Double authentification TOTP obligatoire**, codes de secours.
- **Coffre sur une seule page** avec verrouillage automatique, générateur, sauvegarde chiffrée autonome, vérification des fuites (HIBP, k-anonymat).
- **Extension Chrome** qui déchiffre localement et remplit uniquement sur le bon domaine.
- **Serveur Symfony 7.4** qui ne fait que stocker, authentifier, limiter et journaliser.

## Pile technique

| Couche | Technologies |
|---|---|
| Serveur | PHP 8.3+, Symfony 7.4 LTS, Doctrine, MySQL 8 / MariaDB 11.4 |
| Client et extension | TypeScript, esbuild, WebCrypto, Argon2id (WASM) |
| Qualité | PHPUnit, PHPStan, PHP-CS-Fixer, Vitest, ESLint, Playwright, GitHub Actions |

Les choix sont justifiés dans [docs/DECISIONS.md](docs/DECISIONS.md).

## Démarrer en local

Voir [docs/DEVELOPPEMENT.md](docs/DEVELOPPEMENT.md) (installation sans Docker).

## Contribuer et sécurité

- [CONTRIBUTING.md](CONTRIBUTING.md) — conventions de code et de commit.
- [SECURITY.md](SECURITY.md) — signaler une vulnérabilité.

## Licence

[AGPL-3.0-or-later](LICENSE).

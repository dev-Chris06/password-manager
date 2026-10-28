# Contribuer

## Langue

- **Anglais** : identifiants (fonctions, variables, classes, tables, colonnes), commentaires dans le code, messages de commit.
- **Français** : textes de l'interface et documentation du dépôt (`README.md`, `docs/`).

Voir [ADR-013](docs/DECISIONS.md#adr-013--code-en-anglais-interface-en-français).

## Messages de commit

Format [Conventional Commits](https://www.conventionalcommits.org/) :

```text
<type>(<portée facultative>): <résumé à l'impératif, en anglais>

<corps facultatif : le pourquoi plutôt que le comment>
```

| Type | Usage |
|---|---|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `security` | Correction ou durcissement de sécurité |
| `refactor` | Restructuration sans changement de comportement |
| `test` | Ajout ou correction de tests |
| `docs` | Documentation uniquement |
| `build` | Build, dépendances |
| `ci` | Intégration continue |
| `chore` | Maintenance diverse |

Exemples : `feat(vault): add entry conflict detection`, `fix(auth): reject KDF parameters below floor`.

## Branches et revues

- Une branche par phase du [plan](docs/PLAN.md) : `refonte/phase-N-<slug>`.
- Une PR par phase ; la CI doit être verte avant fusion.

## Avant de pousser

```bash
composer check   # lint, PHP-CS-Fixer (vérification), PHPStan, PHPUnit
npm run check    # typecheck, ESLint, Vitest, build, garde-fou API interdites
npm run test:e2e # Playwright (nécessite MySQL et le serveur PHP)
```

## Règles de sécurité

- Le serveur ne doit **jamais** recevoir le mot de passe maître, la Secret Key, une clé ou un secret en clair.
- Interdits dans le code client : `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `document.write`, `eval`, `new Function` (vérifié en CI).
- Aucune nouvelle dépendance sans justification ajoutée au [plan](docs/PLAN.md#49-dépendances-prévues).

Pour signaler une vulnérabilité, voir [SECURITY.md](SECURITY.md).

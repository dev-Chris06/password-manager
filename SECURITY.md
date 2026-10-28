# Politique de sécurité — Gestionnaire de mots de passe

📅 Dernière mise à jour : Octobre 2026

---

## 1. Signaler une vulnérabilité (divulgation responsable)

**⚠️ N'ouvre PAS d'issue GitHub publique pour un bug de sécurité.**
Les issues publiques permettent à n'importe qui d'exploiter la faille avant correction.

### Comment signaler (ordre préféré)

1. **Email chiffré recommandé** :
   ```
   christopherahoglele9@gmail.com
   ```
   *(Une clé PGP permettrait de chiffrer les signalements sensibles ; sans clé, contacte-moi d'abord pour convenir d'un moyen privé.)*

2. **Message direct GitHub** :
   - Ouvre un message privé sur GitHub auprès du propriétaire du dépôt.

3. **En dernier recours, issue GitHub privée** :
   Si tu n'as aucune réponse sous 48 h, ouvre une issue en **marquant bien "confidentiel"** — on transformera en discussion privée.

### Ce qu'il faut inclure dans ton signalement

Pour accélérer la résolution :
- Version exacte du code (hash commit `git rev-parse HEAD` ou tag)
- Étapes **minimales reproductibles** (pas de code superflu)
- Comportement attendu vs. observé
- Estimation de l'impact (Ex. : "permet de lire le MDP maître de n'importe quel utilisateur authentifié")
- PoC / code exploit **seulement si tu le souhaites** (ce n'est pas obligatoire)

### Délai de divulgation responsable

| Étape | Délai standard |
|---|---|
| Accusé de réception | 2 jours ouvrés |
| Analyse / tri (vrai positif / faux positif) | 5 jours ouvrés |
| Correctif prêt + revue | 30 jours |
| Publication fixe + avis de sécurité | **90 jours max** après signalement initial |

Dans la limite du possible, on **ne divulgue la faille** publiquement qu'après que le fix a été mergé et que tu as eu le temps de mettre à jour ton déploiement.

---

## 2. Périmètre pris en charge (in scope)

> ⚠️ Le projet est en **refonte complète** vers une architecture zero-knowledge (voir [docs/PLAN.md](docs/PLAN.md)). Ce périmètre et les limites ci-dessous seront réécrits à la phase 9.

Sont considérés **"en scope"** pour la sécurité :
- Code serveur Symfony : `src/`, `config/`, `templates/`, `public/index.php`
- Code client : `frontend/` (module crypto partagé et application du coffre)
- Extension Chrome MV3 : `extension/`
- Scripts de build et de déploiement : `tools/`, `deploy/`

**Sont HORS scope (ne pas signaler sauf impact grave démontré)** :
- Documentation Markdown hors code
- L'état antérieur à la refonte (tag `avant-refonte`), qui n'est plus maintenu
- Faille qui nécessite déjà un accès administrateur au serveur
- Attaques physiques sur la machine utilisateur

---

## 3. Limites connues — ce qu'on considère "acceptable" (ne pas signaler)

Ces limites sont détaillées dans [docs/PLAN.md §7](docs/PLAN.md#7-limites-acceptées) et le seront dans le futur modèle de menace :

| Limite |
|---|
| Serveur compromis **activement** qui servirait un JavaScript modifié à l'interface web (l'extension n'en dépend pas) |
| Keylogger / logiciel malveillant sur le poste client |
| Retour en arrière ou masquage d'entrées par un serveur malveillant |
| Secret TOTP lisible par le serveur (nécessaire à la vérification, chiffré au repos) |
| Perte du mot de passe maître ou de la Secret Key → coffre définitivement inaccessible |
| Effacement du presse-papiers non garanti par le navigateur |

---

## 4. Versions prises en charge (security support)

Pour un projet portfolio, seule **la dernière version du HEAD de la branche principale (`main` ou `master`)** reçoit des corrections de sécurité. Les tags anciens ne sont pas backportés sauf cas exceptionnel critique (ex. RCE).

Déploiements conseillés :
- ✅ Toujours utiliser un commit à jour
- ❌ Ne pas utiliser d'anciens tags pour un usage réel

---

## 5. Garantie — "AS IS / NO WARRANTY"

```
LE PROJET EST FOURNI "EN L'ÉTAT", SANS AUCUNE GARANTIE DE QUELQUE NATURE QUE CE SOIT,
EXPRESSE OU IMPLICITE, Y COMPRIS MAIS SANS S'Y LIMITER AUX GARANTIES DE QUALITÉ
MARCHANDE, D'ADÉQUATION À UN USAGE PARTICULIER ET D'ABSENCE DE CONTREFAÇON. EN AUCUN
CAS LES AUTEURS OU TITULAIRES DE DROITS D'AUTEUR NE POURRONT ÊTRE TENUS POUR RESPONSABLES
DE TOUTE RÉCLAMATION, DOMMAGES OU AUTRE RESPONSABILITÉ, QU'IL SOIT DANS UNE ACTION EN
CONTRAT, EN DÉLIT OU AUTRE, DÉCOULANT DE OU EN LIEN AVEC LE LOGICIEL OU L'UTILISATION
OU D'AUTRES INTERACTIONS AVEC CE LOGICIEL.
```

Ce projet est un **exercice de sécurité et de développement portfolio, réalisé à des fins pédagogiques**. Pour un usage personnel réel, évalue attentivement les limites ci-dessus avant hébergement. Préfère une solution auditée professionnellement (Bitwarden, KeePassXC offline + sync cloud chiffré indépendant, 1Password) pour les identifiants critiques.

---

## 6. Historique avis de sécurité (à compléter après chaque fix)

| Date | CVE / Identifiant | Sévérité | Description courte | Versions corrigées |
|---|---|---|---|---|
| — | — | — | Aucun avis publié | — |

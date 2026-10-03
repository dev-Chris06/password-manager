# Politique de sécurité — Gestionnaire de mots de passe

📅 Dernière mise à jour : Octobre 2026

---

## 1. Signaler une vulnérabilité (divulgation responsable)

**⚠️ N'ouvre PAS d'issue GitHub publique pour un bug de sécurité.**
Les issues publiques permettent à n'importe qui d'exploiter la faille avant correction.

### Comment signaler (ordre préféré)

1. **Email chiffré recommandé** :
   ```
   security (at) example.com
   ```
   *(Remplace avant publication par ta vraie adresse + clé PGP disponible sur un keyserver public)*

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

Sont considérés **"en scope"** pour la sécurité :
- [index.php](file:///home/dev-chris06/dev/password-manager/index.php) + `pages/*.php` + `ajax/*.php`
- Code PHP principal : `includes/*.php` + `config/*.php`
- Schéma SQL : [database.sql](file:///home/dev-chris06/dev/password-manager/database.sql)
- Assets JS : `assets/js/*.js` (générateur, dashboard, backup)
- Extension Chrome MV3 : `extension-chrome/` (manifest, background, content, popup)

**Sont HORS scope (ne pas signaler sauf impact grave démontré)** :
- README.md, MENACE.md, TEST_GUIDE.md et docs Markdown hors code
- Exemples de code dans commentaires
- Faille qui nécessite déjà un accès admin sur le serveur
- DoS via upload (aucun upload autorisé dans l'app)
- Attaques physiques sur la machine utilisateur (hors modèle de menace §4)

---

## 3. Limites connues — ce qu'on considère "acceptable" (ne pas signaler)

Ces points sont **dûment documentés** dans le modèle de menace [docs/MENACE.md](file:///home/dev-chris06/dev/password-manager/docs/MENACE.md). Ce ne sont pas des vulnérabilités à corriger dans ce périmètre :

| Limite | Documentée ici |
|---|---|
| Mot de passe maître faible choisi par l'utilisateur (moins de 60 bits d'entropie, ex. `Azerty123456`) | MENACE §4.1.3 |
| Keylogger / malware sur le poste client | MENACE §4.1.1 |
| Attaquant qui contrôle intégralement le serveur (modification fichiers PHP, JS) | MENACE §4.1.2 |
| Perte simultanée MDP maître + clé de récupération → perte du coffre | MENACE §4.1.5 + §5 |
| Métadonnées `site` / `identifiant` en clair (pas chiffrées en base) | MENACE §4.2.1 |
| Pas de reset par email (choix zéro-connaissance) | MENACE §5 |
| Phishing sur site tiers qui usurpe le login du coffre | MENACE §4.1.7 |

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

Ce projet est un **exercice de sécurité et de développement portfolio, réalisé à des fins pédagogiques**. Pour un usage personnel réel, évalue attentivement les risques de MENACE.md avant hébergement. Préfère une solution auditee professionnellement (Bitwarden, KeePassXC offline + sync cloud chiffré indépendant, 1Password) pour les identifiants critiques.

---

## 6. Historique avis de sécurité (à compléter après chaque fix)

| Date | CVE / Identifiant | Sévérité | Description courte | Versions corrigées |
|---|---|---|---|---|
| — | — | — | Aucun avis publié | — |

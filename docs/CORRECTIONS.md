# Corrections a prevoir

Ce document liste les erreurs et points faibles releves pendant l'analyse du projet, ainsi que les corrections recommandees. Il ne modifie pas le code : il sert de feuille de route.

## 1. Mauvais test du retour de `ajouter_entree()` dans l'extension

- Fichier : `ajax/enregistrer_extension.php`
- Lignes concernees : autour de l'appel a `ajouter_entree()`
- Probleme : `ajouter_entree()` renvoie un tableau du type `['ok' => true/false, ...]`, mais le fichier teste le resultat comme un booleen avec `if (!$ok)`.
- Impact : une erreur d'ajout peut etre consideree comme un succes, car un tableau PHP non vide est truthy meme si `ok` vaut `false`.
- Correction recommandee :

```php
$resultat = ajouter_entree(
    id_utilisateur_connecte(),
    $categorie,
    $site,
    $identifiant,
    $motDePasse
);

if (!($resultat['ok'] ?? false)) {
    reponse_json_extension([
        'ok' => false,
        'message' => $resultat['warning'] ?? 'Enregistrement impossible.',
    ], 500);
}
```

## 2. Erreur JavaScript avec une constante dans le content script Chrome

- Fichier : `extension-chrome/content.js`
- Ligne concernee : `generatedPassword = "";`
- Probleme : `generatedPassword` est declare avec `const`, puis le code tente de le modifier.
- Impact : le bouton `Nouveau` peut lever une erreur runtime et interrompre la generation d'un nouveau mot de passe dans le panneau de l'extension.
- Correction recommandee :
  - Supprimer la ligne `generatedPassword = "";`, car vider une variable locale constante n'apporte pas de securite reelle.
  - Ou remplacer `const generatedPassword` par `let generatedPassword`, puis vider la variable apres usage.

Correction simple :

```js
newButton.addEventListener("click", () => {
  const generatedPassword = generatePassword();
  passwordField.input.value = generatedPassword;
  fillPasswordInputs(passwordInput, generatedPassword);
  setPanelStatus(message, "Etat : nouveau mot de passe genere.");
});
```

## 3. URL de backup codee en dur

- Fichier : `assets/js/backup.js`
- Lignes concernees : appels `fetch('/projet_ameliore/ajax/exporter.php')` et `fetch('/projet_ameliore/ajax/importer.php')`
- Probleme : l'URL `/projet_ameliore` est codee en dur, alors que le projet utilise aussi `APP_URL` et que la documentation mentionne parfois `/projet`.
- Impact : l'export/import peut casser si le projet est installe sous un autre nom de dossier.
- Correction recommandee :
  - Utiliser la variable deja declaree dans `pages/backup.php` : `window.APP_BASE_URL`.

Exemple :

```js
const baseUrl = window.APP_BASE_URL || "";

const response = await fetch(`${baseUrl}/ajax/exporter.php`, {
    method: "POST",
    body: formData
});
```

Et pour l'import :

```js
const response = await fetch(`${baseUrl}/ajax/importer.php`, {
    method: "POST",
    body: formData
});
```

## 4. Colonne `ip` manquante dans le schema principal

- Fichier : `database.sql`
- Fichier lie : `migrations/add_ip_to_tentatives_login.sql`
- Probleme : le code de rate limiting utilise `tentatives_login.ip`, mais `database.sql` ne cree pas cette colonne. La colonne est ajoutee seulement par une migration separee.
- Impact : une installation neuve qui importe uniquement `database.sql` peut provoquer une erreur SQL dans `statut_blocage_login()`.
- Correction recommandee :
  - Ajouter directement la colonne `ip` et son index dans `database.sql`, ou indiquer clairement dans le README que la migration doit etre executee apres l'import initial.

Schema recommande :

```sql
CREATE TABLE IF NOT EXISTS tentatives_login (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    nb_tentatives TINYINT UNSIGNED NOT NULL DEFAULT 0,
    derniere_tentative DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bloque_jusqu_a DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_email (email),
    INDEX idx_tentatives_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## 5. Le secret TOTP est envoye a un service externe

- Fichier : `pages/totp_activer.php`
- Ligne concernee : image QR code servie par `https://api.qrserver.com`
- Probleme : l'URL `otpauth://...` contient le secret TOTP. En l'envoyant a un service externe pour generer le QR code, le secret sort de l'application locale.
- Impact : risque de confidentialite pendant l'activation TOTP.
- Correction recommandee :
  - Generer le QR code localement avec une vraie bibliotheque PHP.
  - Eviter l'implementation actuelle de `includes/qrcode.php`, car elle indique elle-meme etre une matrice simplifiee de test.
  - Exemple de bibliotheque possible : `endroid/qr-code`, si Composer est accepte dans le projet.

## 6. Generateur QR local non fiable

- Fichier : `includes/qrcode.php`
- Probleme : le fichier contient une implementation simplifiee qui genere une matrice pseudo-aleatoire basee sur `crc32`, pas un QR code standard complet.
- Impact : un QR genere par ce fichier risque de ne pas etre lisible par les applications d'authentification.
- Correction recommandee :
  - Remplacer ce fichier par une vraie implementation QR.
  - Ou supprimer ce fichier s'il n'est pas utilise, afin d'eviter une fausse impression de securite/fonctionnalite.

## 7. Script de test base de donnees avec mauvais chemin

- Fichier : `test_db.php`
- Ligne concernee : `require_once __DIR__ . '/includes/database.php';`
- Probleme : `includes/database.php` n'existe pas. Le fichier de connexion est `config/database.php`.
- Impact : le script de test echoue avant meme d'essayer la connexion PDO.
- Correction recommandee :

```php
require_once __DIR__ . '/config/database.php';
```

## 8. Mot de passe reposte dans un champ cache lors de la confirmation de doublon

- Fichiers : `pages/ajouter.php` et `pages/modifier.php`
- Lignes concernees : champs caches `name="mot_de_passe"`
- Probleme : quand un doublon est detecte, le mot de passe est remis dans un champ cache HTML pour confirmation.
- Impact : le secret reste temporairement visible dans le DOM et peut etre expose par l'inspection de page, une extension navigateur ou un script compromis.
- Correction recommandee :
  - Stocker temporairement la valeur cote session avec un identifiant de confirmation court.
  - Ou redemander le mot de passe apres confirmation au lieu de le placer dans le HTML.

## 9. Message d'erreur trop detaille dans l'import

- Fichier : `ajax/importer.php`
- Ligne concernee : reponse contenant `$e->getMessage()`
- Probleme : l'endpoint renvoie le detail exact de l'exception au client.
- Impact : utile en developpement, mais trop verbeux en production. Cela peut exposer des informations internes.
- Correction recommandee :
  - Journaliser le detail cote serveur.
  - Renvoyer un message generique cote client.

Exemple :

```php
} catch (Throwable $e) {
    http_response_code(500);
    journaliser_action(null, 'import_erreur', substr($e->getMessage(), 0, 200));
    echo json_encode(['ok' => false, 'message' => 'Erreur lors de l\'import.']);
}
```

## 10. Incoherence entre les chemins documentes

- Fichiers concernes : `.env.example`, `README.md`, `TEST_GUIDE.md`, `extension-chrome/background.js`, `extension-chrome/popup.js`, `assets/js/backup.js`
- Probleme : certaines parties utilisent `http://localhost/projet`, d'autres `http://localhost/projet_ameliore`.
- Impact : confusion pendant l'installation et erreurs de requetes AJAX/extension selon le dossier XAMPP utilise.
- Correction recommandee :
  - Choisir un seul chemin par defaut.
  - Aligner `README.md`, `TEST_GUIDE.md`, `.env.example`, l'extension Chrome et les scripts JS.
  - Preferer `APP_URL` partout cote application PHP.

## 11. Documentation du rate limiting incomplete par rapport au code actuel

- Fichier : `RATE_LIMITING.md`
- Probleme : le document decrit surtout le blocage par email. Le code ajoute aussi un blocage global par IP, mais cette partie est peu documentee.
- Impact : un lecteur peut ne pas comprendre pourquoi une IP est bloquee meme si un email donne n'a pas atteint la limite.
- Correction recommandee :
  - Ajouter une section "Rate limiting par IP".
  - Preciser le seuil actuel : 20 tentatives par IP sur 1 heure.
  - Preciser que la colonne `ip` doit exister dans `tentatives_login`.

## 12. `node_modules` present dans le sous-projet de presentation

- Dossier : `presentation-gestionnaire-mdp/node_modules`
- Probleme : le dossier contient des dependances externes et fichiers generes.
- Impact : ce n'est pas une erreur fonctionnelle, mais cela alourdit fortement le projet si le dossier est partage ou archive.
- Correction recommandee :
  - Garder `node_modules/` ignore par Git.
  - Ajouter un vrai fichier de dependances si besoin (`package-lock.json`, `pnpm-lock.yaml` ou instructions d'installation).
  - Ne pas modifier directement les fichiers dans `node_modules`, meme si l'IDE les ouvre.

## 13. Fichiers generes de presentation a distinguer du code source

- Dossiers : `presentation-gestionnaire-mdp/output` et `presentation-gestionnaire-mdp/scratch`
- Probleme : ces dossiers contiennent des `.pptx`, images de preview, montages et rapports JSON.
- Impact : ils peuvent etre confondus avec du code source alors qu'ils sont des artefacts generes.
- Correction recommandee :
  - Documenter clairement quels scripts les generent.
  - Eventuellement ajouter les artefacts non necessaires dans `.gitignore`.
  - Conserver seulement les livrables finaux si le projet doit rester leger.

## 14. Points verifies sans erreur detectee

- Tous les fichiers PHP applicatifs passent la verification syntaxique `php -l`.
- Tous les fichiers JavaScript/MJS/CJS applicatifs passes a `node --check` ne presentent pas d'erreur de syntaxe.
- Les sorties HTML principales utilisent globalement `e()` pour echapper les donnees affichees.
- Les formulaires sensibles utilisent majoritairement des tokens CSRF.
- Les requetes SQL applicatives principales utilisent PDO prepare.

## Priorites conseillees

1. Corriger `ajax/enregistrer_extension.php`.
2. Corriger `extension-chrome/content.js`.
3. Harmoniser les chemins `/projet` et `/projet_ameliore`.
4. Integrer la colonne `ip` dans `database.sql` ou rendre la migration obligatoire.
5. Remplacer le QR code TOTP externe par une generation locale fiable.
6. Corriger `test_db.php`.
7. Eviter le stockage temporaire des mots de passe dans des champs caches.
8. Rendre les erreurs d'import plus generiques en production.

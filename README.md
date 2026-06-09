# Gestionnaire de mots de passe sécurisé

Application PHP procédurale pour XAMPP permettant de gérer un coffre de mots de passe chiffrés par utilisateur.

## Prérequis

- XAMPP avec PHP récent et MySQL/MariaDB récent.
- Extensions PHP activées : `openssl`, `pdo_mysql`.
- Navigateur moderne pour l'API Clipboard et `crypto.getRandomValues`.

## Installation

1. Placez le dossier du projet dans `htdocs`, par exemple `C:\xampp\htdocs\projet_ameliore`.
2. Importez `database.sql` dans MySQL :

```sql
SOURCE C:/xampp/htdocs/projet_ameliore/database.sql;
```

3. Copiez `.env.example` vers `.env`.
4. Adaptez les variables :

```env
DB_HOST=127.0.0.1
DB_NAME=password_manager
DB_USER=root
DB_PASS=
APP_URL=http://localhost/projet_ameliore
```

5. Ouvrez `http://localhost/projet_ameliore`.

## Usage

- Inscrivez-vous avec un email et un mot de passe maître d'au moins 12 caractères.
- Connectez-vous pour accéder au dashboard.
- Ajoutez, modifiez, supprimez et filtrez vos entrées.
- Le bouton `Copier` déclenche un endpoint AJAX protégé CSRF qui déchiffre uniquement l'entrée demandée après vérification du propriétaire.
- Le changement de mot de passe maître rechiffre toutes les entrées dans une transaction.

## Sécurité

- Mot de passe maître hashé avec `password_hash(..., PASSWORD_BCRYPT, ['cost' => 12])`.
- Sel PBKDF2 unique par utilisateur, stocké en hexadécimal.
- Clé AES dérivée avec PBKDF2-SHA256 et 100 000 itérations.
- Secrets chiffrés en AES-256-GCM avec `mdp_chiffre`, `iv` et `auth_tag` séparés.
- La clé de chiffrement n'est jamais stockée en base ; elle est conservée en session encodée en base64.
- Sessions avec cookies `HttpOnly`, `SameSite=Strict`, `Secure` seulement si HTTPS.
- ID de session régénéré après connexion.
- CSRF vérifié sur tous les formulaires POST et sur l'endpoint AJAX.
- Requêtes PDO préparées, ownership vérifié par `user_id`.
- Headers de sécurité configurés dans `config/config.php`.
- Migration automatique CBC legacy vers GCM à la connexion ; les entrées legacy illisibles sont ignorées sans casser la session.

## Structure

```text
config/
includes/
pages/
ajax/
assets/css/
assets/js/
docs/
extension-chrome/
presentation-gestionnaire-mdp/
index.php
database.sql
README.md
```

## Documentation

- [Guide de test](docs/TEST_GUIDE.md)
- [Rate limiting](docs/RATE_LIMITING.md)
- [Corrections et points d'audit](docs/CORRECTIONS.md)
- [Extension Chrome](extension-chrome/README.md)

## Notes locales

En HTTP local, le cookie de session n'a pas l'attribut `Secure`, ce qui permet les tests sous XAMPP. En HTTPS, l'attribut est automatiquement activé.

<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$_testPdo = null;

final class AuthIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../config/env.php';
        load_env(dirname(__DIR__) . '/.env');

        $host = (string) env_value('DB_HOST', '127.0.0.1');
        $user = (string) env_value('DB_USER', 'root');
        $pass = (string) env_value('DB_PASS', '');
        $dbTest = 'password_manager_test';

        $root = new PDO(
            sprintf('mysql:host=%s;charset=utf8mb4', $host),
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
        $root->exec('SET FOREIGN_KEY_CHECKS=0');
        $root->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $dbTest));
        $root->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $dbTest
        ));
        $root->exec(sprintf('USE `%s`', $dbTest));

        $root->exec('CREATE TABLE utilisateurs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            hash_mdp VARCHAR(255) NOT NULL,
            sel_pbkdf2 VARCHAR(128) NOT NULL,
            totp_secret VARCHAR(255) NULL DEFAULT NULL,
            totp_active TINYINT(1) NOT NULL DEFAULT 0,
            last_totp_slot BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_utilisateurs_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('CREATE TABLE entrees (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            categorie ENUM(\'Réseaux sociaux\', \'Email\', \'Banque\', \'École\', \'Autre\') NOT NULL DEFAULT \'Autre\',
            site VARCHAR(255) NOT NULL,
            identifiant VARCHAR(255) NOT NULL,
            mdp_chiffre TEXT NOT NULL,
            iv VARCHAR(255) NOT NULL,
            auth_tag VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_entrees_user_id (user_id),
            INDEX idx_entrees_categorie (categorie),
            CONSTRAINT fk_entrees_user
                FOREIGN KEY (user_id) REFERENCES utilisateurs(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('SET FOREIGN_KEY_CHECKS=1');

        self::$pdo = $root;
        global $_testPdo;
        $_testPdo = self::$pdo;

        if (!function_exists('get_pdo')) {
            function get_pdo(): PDO {
                global $_testPdo;
                assert($_testPdo instanceof PDO, 'Le mock PDO n\'a pas été initialisé.');
                return $_testPdo;
            }
        }

        if (!defined('CRYPTO_METHOD_GCM')) define('CRYPTO_METHOD_GCM', 'aes-256-gcm');
        if (!defined('PBKDF2_ITERATIONS')) define('PBKDF2_ITERATIONS', 10000);
        if (!defined('BCRYPT_COST')) define('BCRYPT_COST', 10);
        if (!defined('APP_URL')) define('APP_URL', 'http://localhost');
        if (!defined('SESSION_TIMEOUT_INACTIVITE_SEC')) define('SESSION_TIMEOUT_INACTIVITE_SEC', 3600);
        if (!defined('SESSION_TIMEOUT_ABSOLU_SEC')) define('SESSION_TIMEOUT_ABSOLU_SEC', 86400);

        require_once __DIR__ . '/../includes/crypto.php';
        require_once __DIR__ . '/../includes/entrees.php';
    }

    protected function setUp(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec('TRUNCATE TABLE entrees');
        self::$pdo->exec('TRUNCATE TABLE utilisateurs');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$pdo instanceof PDO) {
            self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            self::$pdo->exec('DROP DATABASE IF EXISTS password_manager_test');
            self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        self::$pdo = null;
    }

    public function testOwnershipLecture(): void
    {
        $selA = generer_sel_pbkdf2();
        $selB = generer_sel_pbkdf2();
        $cleA = deriver_cle_chiffrement('password-A1234', $selA);

        $stmt = self::$pdo->prepare('INSERT INTO utilisateurs (email, hash_mdp, sel_pbkdf2) VALUES (?, ?, ?)');
        $stmt->execute(['a@exemple.fr', password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]), $selA]);
        $userIdA = (int) self::$pdo->lastInsertId();
        $stmt->execute(['b@exemple.fr', password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]), $selB]);
        $userIdB = (int) self::$pdo->lastInsertId();

        $eA = chiffrer_mdp_gcm('MDP-DE-A', $cleA);
        $stmt = self::$pdo->prepare('INSERT INTO entrees (user_id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag)
            VALUES (:uid, \'Autre\', :site, :ident, :c, :iv, :tag)');
        $stmt->execute(['uid' => $userIdA, 'site' => 'site-a.fr', 'ident' => 'alice',
            'c' => $eA['mdp_chiffre'], 'iv' => $eA['iv'], 'tag' => $eA['auth_tag']]);
        $entreeIdA = (int) self::$pdo->lastInsertId();

        $resultB = dechiffrer_entree_utilisateur($userIdB, $entreeIdA);
        $this->assertNull($resultB, 'L\'utilisateur B NE PEUT PAS lire une entrée de A.');

        $resultA = dechiffrer_entree_utilisateur($userIdA, $entreeIdA);
        $this->assertSame('MDP-DE-A', $resultA, 'L\'utilisateur A lit sa propre entrée.');
    }

    public function testOwnershipSuppression(): void
    {
        $selA = generer_sel_pbkdf2();
        $selB = generer_sel_pbkdf2();
        $cleA = deriver_cle_chiffrement('pw-A-zzzz', $selA);

        $stmt = self::$pdo->prepare('INSERT INTO utilisateurs (email, hash_mdp, sel_pbkdf2) VALUES (?, ?, ?)');
        $stmt->execute(['a2@exemple.fr', password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]), $selA]);
        $userIdA = (int) self::$pdo->lastInsertId();
        $stmt->execute(['b2@exemple.fr', password_hash('x', PASSWORD_BCRYPT, ['cost' => 10]), $selB]);
        $userIdB = (int) self::$pdo->lastInsertId();

        $eA = chiffrer_mdp_gcm('TRESSECRET', $cleA);
        $stmt = self::$pdo->prepare('INSERT INTO entrees (user_id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag)
            VALUES (:uid, \'Autre\', :site, :ident, :c, :iv, :tag)');
        $stmt->execute(['uid' => $userIdA, 'site' => 'important.bank', 'ident' => 'ceo',
            'c' => $eA['mdp_chiffre'], 'iv' => $eA['iv'], 'tag' => $eA['auth_tag']]);
        $entreeId = (int) self::$pdo->lastInsertId();

        $supprB = supprimer_entree($userIdB, $entreeId);
        $this->assertFalse($supprB, 'B ne peut pas supprimer l\'entrée de A.');

        $stmtCheck = self::$pdo->prepare('SELECT id FROM entrees WHERE id = ?');
        $stmtCheck->execute([$entreeId]);
        $this->assertNotEmpty($stmtCheck->fetch(), 'L\'entrée de A doit exister après tentative B.');

        $supprA = supprimer_entree($userIdA, $entreeId);
        $this->assertTrue($supprA, 'A supprime sa propre entrée.');
    }
}

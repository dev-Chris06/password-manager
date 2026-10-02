<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$_testPdo = null;

final class RateLimitTest extends TestCase
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

        $root->exec('CREATE TABLE tentatives_login (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            nb_tentatives TINYINT UNSIGNED NOT NULL DEFAULT 0,
            derniere_tentative DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            bloque_jusqu_a DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uq_email (email),
            INDEX idx_tentatives_ip (ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('CREATE TABLE tentatives_login_detail (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            ip            VARCHAR(45)  NOT NULL,
            email         VARCHAR(255) NOT NULL,
            tentative_at  DATETIME     NOT NULL,
            succes        TINYINT(1)   NOT NULL DEFAULT 0,
            INDEX idx_ip_email_time (ip, email, tentative_at),
            INDEX idx_email_time      (email, tentative_at),
            INDEX idx_ip_time         (ip, tentative_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('CREATE TABLE login_blocages (
            ip              VARCHAR(45)  NOT NULL,
            email           VARCHAR(255) NOT NULL,
            bloque_jusqu_a  DATETIME     NOT NULL,
            UNIQUE KEY uq_ip_email (ip, email),
            INDEX idx_bloque_jusqu_a (bloque_jusqu_a)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('CREATE TABLE journal_actions (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id     INT UNSIGNED NULL,
            action      VARCHAR(64) NOT NULL,
            detail      VARCHAR(512) NOT NULL DEFAULT \'\',
            ip          VARCHAR(45) NOT NULL DEFAULT \'\',
            user_agent  VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_journal_user_id (user_id),
            INDEX idx_journal_action  (action),
            INDEX idx_journal_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $root->exec('SET FOREIGN_KEY_CHECKS=1');

        self::$pdo = $root;
        global $_testPdo;
        $_testPdo = self::$pdo;

        if (!isset($_SERVER['REMOTE_ADDR']) || !is_string($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }

        if (!defined('LOGIN_MAX_FAILURES')) define('LOGIN_MAX_FAILURES', 3);
        if (!defined('LOGIN_BLOCK_SECONDS')) define('LOGIN_BLOCK_SECONDS', 60);
        if (!defined('RL_IP_EMAIL_MAX_5MIN')) define('RL_IP_EMAIL_MAX_5MIN', 3);
        if (!defined('RL_IP_EMAIL_BLOCK_SEC')) define('RL_IP_EMAIL_BLOCK_SEC', 60);
        if (!defined('LOGIN_LOCK_MAX_FAILURES')) define('LOGIN_LOCK_MAX_FAILURES', 3);
        if (!defined('LOGIN_LOCK_SECONDS')) define('LOGIN_LOCK_SECONDS', 60);

        if (!function_exists('get_pdo')) {
            function get_pdo(): PDO {
                global $_testPdo;
                assert($_testPdo instanceof PDO, 'Le mock PDO n\'a pas été initialisé.');
                return $_testPdo;
            }
        }
    }

    protected function setUp(): void
    {
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        self::$pdo->exec('TRUNCATE TABLE tentatives_login');
        self::$pdo->exec('TRUNCATE TABLE tentatives_login_detail');
        self::$pdo->exec('TRUNCATE TABLE login_blocages');
        self::$pdo->exec('TRUNCATE TABLE journal_actions');
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

    private function chargerFonctions(): void
    {
        require_once __DIR__ . '/../includes/auth.php';
    }

    public function testTroisEchecsBloquentCompte(): void
    {
        $this->chargerFonctions();

        $email = 'victime@exemple.fr';

        for ($i = 0; $i < 3; $i++) {
            enregistrer_echec_login($email);
        }

        $statut = statut_blocage_login($email);
        $this->assertTrue($statut['bloque'], sprintf(
            'Après 3 échecs, le compte doit être bloqué (nb_tentatives = %d).',
            LOGIN_MAX_FAILURES
        ));
        $this->assertGreaterThan(0, $statut['secondes_restantes'],
            'bloque_jusqu_a doit être dans le futur.');
    }

    public function testReinitialisationSucces(): void
    {
        $this->chargerFonctions();
        $email = 'success@exemple.fr';

        enregistrer_echec_login($email);
        enregistrer_echec_login($email);

        reinitialiser_tentatives_login($email);

        $stmt = self::$pdo->prepare('SELECT nb_tentatives, bloque_jusqu_a FROM tentatives_login WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $this->assertNotFalse($row);
        $this->assertSame(0, (int) $row['nb_tentatives']);
        $this->assertNull($row['bloque_jusqu_a']);
    }
}

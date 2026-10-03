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
        $root->exec("SET time_zone = '+00:00'");
        $root->exec('SET FOREIGN_KEY_CHECKS=0');
        $root->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $dbTest));
        $root->exec(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $dbTest
        ));
        $root->exec(sprintf('USE `%s`', $dbTest));

        // —— tables obligatoires pour charger auth.php + tests ——
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

        // login_blocages V3 (Défaut 3 — multi-axes + escalade infraction_n)
        $root->exec('CREATE TABLE login_blocages (
            id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            axe            ENUM(\'PAIR_IP_EMAIL\', \'GLOBAL_IP\', \'GLOBAL_EMAIL\', \'GLOBAL_IP_INSCRIPTION\') NOT NULL,
            ip             VARCHAR(45)  NOT NULL DEFAULT \'\',
            email          VARCHAR(255) NOT NULL DEFAULT \'\',
            bloque_jusqu_a DATETIME     NOT NULL,
            infraction_n   TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_blocages_axe (axe, ip, email),
            INDEX idx_login_blocages_expire (bloque_jusqu_a)
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

        // Pour test intégration connecter_utilisateur() : besoin de utilisateurs()
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

        $root->exec('SET FOREIGN_KEY_CHECKS=1');

        self::$pdo = $root;
        global $_testPdo;
        $_testPdo = self::$pdo;

        if (!isset($_SERVER['REMOTE_ADDR']) || !is_string($_SERVER['REMOTE_ADDR'])) {
            $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        }

        // Constantes escalade (Défaut 3)
        if (!defined('LOGIN_SEUIL_PAIR_MAX_5MIN')) define('LOGIN_SEUIL_PAIR_MAX_5MIN', 3);
        if (!defined('LOGIN_SEUIL_IP_MAX_1H'))     define('LOGIN_SEUIL_IP_MAX_1H',     4);
        if (!defined('LOGIN_SEUIL_EMAIL_MAX_1H'))  define('LOGIN_SEUIL_EMAIL_MAX_1H',  3);
        if (!defined('LOGIN_ESCALADE_HISTORIQUE_H')) define('LOGIN_ESCALADE_HISTORIQUE_H', 24);
        if (!defined('LOGIN_DUREE_PAIR_SEC'))      define('LOGIN_DUREE_PAIR_SEC',      [1 => 60,    2 => 300,   3 => 3600]);
        if (!defined('LOGIN_DUREE_IP_SEC'))        define('LOGIN_DUREE_IP_SEC',        [1 => 300,   2 => 1800,  3 => 21600]);
        if (!defined('LOGIN_DUREE_EMAIL_SEC'))     define('LOGIN_DUREE_EMAIL_SEC',     [1 => 600,   2 => 3600,  3 => 28800]);
        if (!defined('RL_RETENTION_DAYS'))         define('RL_RETENTION_DAYS', 7);
        if (!defined('LOGIN_MAX_FAILURES'))        define('LOGIN_MAX_FAILURES', 3);
        if (!defined('LOGIN_BLOCK_SECONDS'))       define('LOGIN_BLOCK_SECONDS', 60);
        if (!defined('RL_IP_EMAIL_MAX_5MIN'))      define('RL_IP_EMAIL_MAX_5MIN', 3);
        if (!defined('RL_IP_EMAIL_BLOCK_SEC'))     define('RL_IP_EMAIL_BLOCK_SEC', 60);
        if (!defined('RL_IP_MAX_1H'))              define('RL_IP_MAX_1H', LOGIN_SEUIL_IP_MAX_1H);
        if (!defined('RL_IP_BLOCK_SEC'))           define('RL_IP_BLOCK_SEC', 60);
        if (!defined('RL_EMAIL_MAX_1H'))           define('RL_EMAIL_MAX_1H', LOGIN_SEUIL_EMAIL_MAX_1H);
        if (!defined('RL_EMAIL_BLOCK_SEC'))        define('RL_EMAIL_BLOCK_SEC', 60);
        if (!defined('LOGIN_LOCK_MAX_FAILURES'))   define('LOGIN_LOCK_MAX_FAILURES', 3);
        if (!defined('LOGIN_LOCK_SECONDS'))        define('LOGIN_LOCK_SECONDS', 60);
        if (!defined('SESSION_TIMEOUT_INACTIVITE_SEC')) define('SESSION_TIMEOUT_INACTIVITE_SEC', 300);
        if (!defined('SESSION_TIMEOUT_ABSOLU_SEC'))     define('SESSION_TIMEOUT_ABSOLU_SEC', 3600);
        if (!defined('LOGIN_DUMMY_BCRYPT_HASH'))   define('LOGIN_DUMMY_BCRYPT_HASH', '$2y$12$DRjm/5F3C1g3QVtR0k1WGOxhpBpR.G0a1.3nVh19XxZz7f7q5b6Oa');
        if (!defined('JOURNAL_RETENTION_DAYS'))    define('JOURNAL_RETENTION_DAYS', 90);
        if (!defined('INSCRIPTION_SEUIL_IP_MAX_1H'))        define('INSCRIPTION_SEUIL_IP_MAX_1H',         5);
        if (!defined('INSCRIPTION_FENETRE_COMPTAGE_MIN'))   define('INSCRIPTION_FENETRE_COMPTAGE_MIN',    60);
        if (!defined('INSCRIPTION_DUREE_IP_SEC'))           define('INSCRIPTION_DUREE_IP_SEC',            [1 => 300, 2 => 3600, 3 => 86400]);
        if (!defined('INSCRIPTION_ESCALADE_HISTORIQUE_H'))  define('INSCRIPTION_ESCALADE_HISTORIQUE_H',   48);

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
        self::$pdo->exec('TRUNCATE TABLE utilisateurs');
        self::$pdo->exec('TRUNCATE TABLE tentatives_login');
        self::$pdo->exec('TRUNCATE TABLE tentatives_login_detail');
        self::$pdo->exec('TRUNCATE TABLE login_blocages');
        self::$pdo->exec('TRUNCATE TABLE journal_actions');
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        // Reset session pour éviter l'état résiduel inter-test (PHPUnit lance CLI sans session persistante)
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
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

    /**
     * Test historique : le compteur de tentatives anciennes table bloque toujours.
     */
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

    // ====================================================================
    // Tests Défaut 3 — axes multi-verrous + escalade
    // ====================================================================

    /**
     * Axe PAIR_IP_EMAIL : 3 échecs <IP, Email> → verrou PAIR.
     */
    public function testVerrouPair3Echecs(): void
    {
        $this->chargerFonctions();
        $ip = '10.0.0.1';
        $email = 'alice@exemple.fr';

        for ($i = 0; $i < 3; $i++) {
            enregistrer_tentative_login_detail($email, $ip, false);
        }
        poser_verrou_pair($email, $ip);

        $s = statut_verrou_pair($email, $ip);
        $this->assertTrue($s['bloque']);
        $this->assertSame('PAIR_IP_EMAIL', $s['axe']);
        $this->assertSame(1, $s['infraction_n']);
        // Durée escalade n=1 PAIR => 60 s (tolérance 5 s d'écart d'exécution)
        $this->assertGreaterThanOrEqual(55, $s['secondes_restantes']);
        $this->assertLessThanOrEqual(60, $s['secondes_restantes']);
    }

    /**
     * Escalade : 2e infraction sur la même clé (historique 24h) => n=2.
     * PAIR n=2 → 300 s (5 min).
     * La simulation d'"expiration" se fait via UPDATE created_at DANS la fenêtre historique.
     */
    public function testEscaladePairNiveau2(): void
    {
        $this->chargerFonctions();
        $ip = '10.0.0.2';
        $email = 'bob@exemple.fr';

        // Niveau 1 : pose + "fausse expiration" + fausse création récente
        poser_verrou_pair($email, $ip);
        self::$pdo->prepare("UPDATE login_blocages
            SET bloque_jusqu_a = DATE_SUB(NOW(), INTERVAL 1 SECOND),
                created_at     = DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            WHERE axe='PAIR_IP_EMAIL' AND ip=? AND email=?")
            ->execute([$ip, $email]);

        // Niveau 2 : nouveau verrou
        poser_verrou_pair($email, $ip);

        $s = statut_verrou_pair($email, $ip);
        $this->assertTrue($s['bloque']);
        $this->assertSame(2, $s['infraction_n'], 'infraction_n doit être 2 pour la 2e infraction.');
        // 300 s attendues (5 min)
        $this->assertGreaterThanOrEqual(295, $s['secondes_restantes']);
        $this->assertLessThanOrEqual(300, $s['secondes_restantes']);
    }

    /**
     * Axe GLOBAL_IP : 4 échecs depuis N comptes différents sur la même IP.
     * → La 5e tentative sur un TOUT NOUVEAU compte doit renvoyer bloqué=true,
     *   car fusionner_statuts_verrous prend le max.
     *
     * Dans les constantes du test : LOGIN_SEUIL_IP_MAX_1H = 4.
     */
    public function testVerrouGlobalIpBloqueTousLesComptes(): void
    {
        $this->chargerFonctions();
        $ip = '10.0.0.99';
        $_SERVER['REMOTE_ADDR'] = $ip;

        $emails = [];
        for ($i = 0; $i < 4; $i++) {
            $e = "user{$i}@exemple.fr";
            $emails[] = $e;
            enregistrer_tentative_login_detail($e, $ip, false);
        }
        poser_verrou_ip($ip);

        // —— Fusion : un 5e compte (jamais vu) depuis cette IP est BLOQUÉ ——
        $victime = 'totally-new@exemple.fr';
        $fusion = fusionner_statuts_verrous($ip, $victime);
        $this->assertTrue($fusion['bloque'],
            'Un nouveau compte sur une IP globalement bloquée DOIT être bloqué aussi (axe GLOBAL_IP).');
        $this->assertGreaterThanOrEqual(295, $fusion['secondes_restantes']); // IP n=1 = 300 s

        // Vérifier le détail des axes déclenchés
        $axes = array_column($fusion['axes_declenches'], 'axe');
        $this->assertContains('GLOBAL_IP', $axes);
    }

    /**
     * Fusion MAX() correcte : si PAIR est à 60s et GLOBAL_IP à 300s, le délai
     * renvoyé est 300 s.
     */
    public function testFusionPrendLeMaxDes3Axes(): void
    {
        $this->chargerFonctions();
        $ip = '10.0.0.50';
        $email = 'mixte@exemple.fr';

        poser_verrou_pair($email, $ip);   // n=1 → 60 s
        poser_verrou_ip($ip);             // n=1 → 300 s (IP dure plus longtemps)

        $fusion = fusionner_statuts_verrous($ip, $email);
        $this->assertTrue($fusion['bloque']);
        $this->assertGreaterThanOrEqual(295, $fusion['secondes_restantes'],
            'La fusion doit retourner le MAX des axes, ici GLOBAL_IP (300 s).');

        $axes = array_column($fusion['axes_declenches'], 'axe');
        $this->assertContains('PAIR_IP_EMAIL', $axes);
        $this->assertContains('GLOBAL_IP', $axes);
    }

    /**
     * Intégration bout en bout : connecter_utilisateur() avec un compte réel,
     * 3 mauvais mdp → bloque + next appel renvoie le verrou.
     * (évite TOTP : totp_active=0, mdp conforme 12+ caracters.)
     */
    public function testConnecterUtilisateurBoucleBloquant(): void
    {
        $this->chargerFonctions();
        $ip = '10.0.0.77';
        $_SERVER['REMOTE_ADDR'] = $ip;
        $email = 'integ@exemple.fr';
        $bonMdp = 'MonMdpSuper123!';
        $hash = password_hash($bonMdp, PASSWORD_BCRYPT, ['cost' => 10]);
        $sel = 'test_sel_test_sel_test_sel_test_sel_test_sel_test_sel_salt';

        $stmt = self::$pdo->prepare('INSERT INTO utilisateurs (email, hash_mdp, sel_pbkdf2, totp_active)
            VALUES (?, ?, ?, 0)');
        $stmt->execute([$email, $hash, $sel]);

        // 3 mauvais passwords → doit déclencher poser_verrou_pair en interne
        for ($i = 0; $i < 3; $i++) {
            $r = connecter_utilisateur($email, 'MAUVAIS_MOT_DE_PASSE_'.$i);
            $this->assertFalse($r['ok']);
            $this->assertFalse($r['bloque'] ?? true, "Tentative $i : ne doit pas être bloquée avant le 3e échec.");
        }

        // 4e tentative → DOIT être bloquée (verrou PAIR_IP_EMAIL n=1)
        $bloque = connecter_utilisateur($email, 'encore-un-mauvais');
        $this->assertFalse($bloque['ok']);
        $this->assertTrue($bloque['bloque'] ?? false, '4e appel DOIT renvoyer bloque=true.');
        $this->assertGreaterThanOrEqual(55, $bloque['secondes_restantes'] ?? 0);
    }
}

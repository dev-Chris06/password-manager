<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

$_testPdo = null;

final class RateLimitTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new PDO('sqlite::memory:');
        global $_testPdo;
        $_testPdo = self::$pdo;
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::$pdo->exec('CREATE TABLE tentatives_login (
            email TEXT PRIMARY KEY,
            ip TEXT,
            nb_tentatives INTEGER DEFAULT 0,
            derniere_tentative DATETIME,
            bloque_jusqu_a DATETIME
        )');

        if (!defined('LOGIN_MAX_FAILURES')) define('LOGIN_MAX_FAILURES', 3);
        if (!defined('LOGIN_BLOCK_SECONDS')) define('LOGIN_BLOCK_SECONDS', 60);

        if (!function_exists('get_pdo')) {
            function get_pdo(): PDO {
                global $_testPdo;
                assert($_testPdo instanceof PDO, 'Le mock PDO n\'a pas été initialisé (appelez setUpBeforeClass avant get_pdo()).');
                return $_testPdo;
            }
        }
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

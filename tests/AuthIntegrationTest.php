<?php
declare(strict_types=1);



use PHPUnit\Framework\TestCase;

$_testPdo = null;

final class AuthIntegrationTest extends TestCase
{
    private static ?PDO $pdo = null;

    public static function setUpBeforeClass(): void
    {
        self::$pdo = new PDO('sqlite::memory:');
        global $_testPdo;
        $_testPdo = self::$pdo;
        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        self::$pdo->exec('CREATE TABLE utilisateurs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            hash_mdp TEXT NOT NULL,
            sel_pbkdf2 TEXT NOT NULL,
            totp_active INTEGER DEFAULT 0,
            totp_secret TEXT NULL,
            last_totp_slot INTEGER DEFAULT 0
        )');
        self::$pdo->exec('CREATE TABLE entrees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            categorie TEXT DEFAULT \'Autre\',
            site TEXT NOT NULL,
            identifiant TEXT NOT NULL,
            mdp_chiffre TEXT NOT NULL,
            iv TEXT NOT NULL,
            auth_tag TEXT NOT NULL
        )');

        if (!function_exists('get_pdo')) {
            function get_pdo(): PDO {
                global $_testPdo;
                assert($_testPdo instanceof PDO, 'Le mock PDO n\'a pas été initialisé (appelez setUpBeforeClass avant get_pdo()).');
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

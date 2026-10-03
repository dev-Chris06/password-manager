<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    public function setUp(): void
    {
        if (!defined('CRYPTO_METHOD_GCM')) {
            define('CRYPTO_METHOD_GCM', 'aes-256-gcm');
        }
        if (!defined('PBKDF2_ITERATIONS')) {
            define('PBKDF2_ITERATIONS', 100000);
        }
        require_once __DIR__ . '/../includes/crypto.php';
    }

    public function testChiffrementDechiffrementInverse(): void
    {
        $cle = random_bytes(32);
        $mdp = 'LeGrenouille-1234 !@#$';
        $c = chiffrer_mdp_gcm($mdp, $cle);
        $this->assertIsArray($c);
        $this->assertArrayHasKey('iv', $c);
        $this->assertArrayHasKey('auth_tag', $c);
        $this->assertArrayHasKey('mdp_chiffre', $c);
        $dechiffre = dechiffrer_mdp_gcm($c['mdp_chiffre'], $c['iv'], $c['auth_tag'], $cle);
        $this->assertSame($mdp, $dechiffre);
    }

    public function testTagFalsifieEchoue(): void
    {
        $cle = random_bytes(32);
        $mdp = 'invalide-tag';
        $c = chiffrer_mdp_gcm($mdp, $cle);

        $fauxTag = base64_encode(str_repeat("\x00", 16));
        $this->expectException(\RuntimeException::class);
        dechiffrer_mdp_gcm($c['mdp_chiffre'], $c['iv'], $fauxTag, $cle);
    }

    public function testDerivationCleDeterministe(): void
    {
        $sel = bin2hex(random_bytes(16));
        $k1 = deriver_cle_chiffrement('mot-de-passe-secret', $sel);
        $k2 = deriver_cle_chiffrement('mot-de-passe-secret', $sel);
        $this->assertSame(strlen($k1), 32);
        $this->assertSame($k1, $k2);

        $k3 = deriver_cle_chiffrement('autre', $sel);
        $this->assertNotSame($k1, $k3);
    }
}

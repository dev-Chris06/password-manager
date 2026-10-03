<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function setUp(): void
    {
        require_once __DIR__ . '/../includes/totp.php';
    }

    public function testGenererCode6Chiffres(): void
    {
        $secret = generer_secret_totp();
        $this->assertNotEmpty($secret);

        $code = generer_code_totp($secret, time());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function testVerifierFenetre1(): void
    {
        $secret = generer_secret_totp();
        $t = time() - 30;
        $code = generer_code_totp($secret, $t);

        $slotCourant = null;
        $this->assertTrue(verifier_code_totp($secret, $code, $slotCourant, 1),
            'Code du slot précédent doit être accepté dans fenêtre ±1.');
        $this->assertIsInt($slotCourant);
    }

    public function testVerifierFuturTropLoinEchoue(): void
    {
        $secret = generer_secret_totp();
        $t = time() + 90;
        $code = generer_code_totp($secret, $t);

        $slot = null;
        $this->assertFalse(verifier_code_totp($secret, $code, $slot, 1),
            'Code ~90s dans le futur refusé par fenêtre ±1 (3 slots).');
    }

    public function testHashEqualsTimingSafeUtilise(): void
    {
        $body = file_get_contents(__DIR__ . '/../includes/totp.php');
        $this->assertNotFalse($body);
        $this->assertStringContainsString('hash_equals', $body,
            'verifier_code_totp() doit employer hash_equals pour comparer les codes.');
    }
}

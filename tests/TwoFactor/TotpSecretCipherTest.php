<?php

declare(strict_types=1);

namespace App\Tests\TwoFactor;

use App\Entity\User;
use App\Model\KdfParameters;
use App\TwoFactor\TotpSecretCipher;
use PHPUnit\Framework\TestCase;

final class TotpSecretCipherTest extends TestCase
{
    public function testSecretsRoundTripAndAreBoundToTheirUser(): void
    {
        $cipher = new TotpSecretCipher('app-secret');
        $alice = self::user(1);
        $encrypted = $cipher->encrypt($alice, 'JBSWY3DPEHPK3PXP');

        self::assertSame('JBSWY3DPEHPK3PXP', $cipher->decrypt($alice, $encrypted));
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', (string) base64_decode($encrypted, true));

        $this->expectException(\RuntimeException::class);
        $cipher->decrypt(self::user(2), $encrypted);
    }

    public function testAnotherAppSecretCannotDecrypt(): void
    {
        $encrypted = (new TotpSecretCipher('app-secret'))->encrypt(self::user(1), 'JBSWY3DPEHPK3PXP');

        $this->expectException(\RuntimeException::class);
        (new TotpSecretCipher('another-secret'))->decrypt(self::user(1), $encrypted);
    }

    private static function user(int $id): User
    {
        $user = new User('u'.$id.'@example.com', KdfParameters::defaults(), 'c2FsdA==', 'hash', 'key');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}

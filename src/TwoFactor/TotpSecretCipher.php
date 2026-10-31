<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts TOTP secrets at rest (docs/PLAN.md §4.6).
 *
 * The server must be able to read the secret to verify codes; encrypting it with a key derived
 * from APP_SECRET (stored outside the database) keeps a database leak from exposing it.
 * Format: base64(0x01 ‖ iv[12] ‖ tag[16] ‖ ciphertext), AES-256-GCM, user id as associated data.
 * Rotating APP_SECRET makes existing secrets unreadable: two-factor must then be enrolled again.
 */
final readonly class TotpSecretCipher
{
    private const int VERSION = 1;
    private const string CIPHER = 'aes-256-gcm';

    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = hash_hkdf('sha256', $secret, 32, 'pm-totp-v1');
    }

    public function encrypt(User $user, string $secret): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, self::CIPHER, $this->key, \OPENSSL_RAW_DATA, $iv, $tag, self::associatedData($user), 16);
        if (false === $ciphertext) {
            throw new \RuntimeException('TOTP secret encryption failed.');
        }

        return base64_encode(\chr(self::VERSION).$iv.$tag.$ciphertext);
    }

    public function decrypt(User $user, string $encrypted): string
    {
        $bytes = base64_decode($encrypted, true);
        if (false === $bytes || \strlen($bytes) < 30 || self::VERSION !== \ord($bytes[0])) {
            throw new \RuntimeException('Unreadable TOTP secret.');
        }

        $plaintext = openssl_decrypt(substr($bytes, 29), self::CIPHER, $this->key, \OPENSSL_RAW_DATA, substr($bytes, 1, 12), substr($bytes, 13, 16), self::associatedData($user));
        if (false === $plaintext) {
            throw new \RuntimeException('Unreadable TOTP secret.');
        }

        return $plaintext;
    }

    private static function associatedData(User $user): string
    {
        return 'pm-totp-v1:'.$user->getId();
    }
}

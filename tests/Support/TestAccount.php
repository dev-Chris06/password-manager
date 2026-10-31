<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Model\KdfParameters;
use App\TwoFactor\TotpSecretCipher;
use App\TwoFactor\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A ready-to-use account (two-factor already enrolled), created directly in the database so
 * that tests can have several accounts despite the single-account registration.
 */
final class TestAccount
{
    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function __construct(public readonly User $user, public readonly string $proof)
    {
    }

    public static function create(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $hasher,
        TotpSecretCipher $cipher,
        string $email,
    ): self {
        $proof = base64_encode(random_bytes(32));
        $user = new User($email, KdfParameters::defaults(), base64_encode(random_bytes(16)), '', base64_encode(random_bytes(61)));
        $user->upgradeAuthHash($hasher->hashPassword($user, $proof));
        $entityManager->persist($user);
        $entityManager->flush();
        $user->enableTotp($cipher->encrypt($user, self::TOTP_SECRET));
        $entityManager->flush();

        return new self($user, $proof);
    }

    /**
     * Logs in with both factors through the API.
     */
    public function login(KernelBrowser $client): void
    {
        $send = static function (string $uri, array $body) use ($client): void {
            $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1']);
            $session = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
            \assert(\is_array($session) && \is_string($session['csrfToken']));
            $client->request('POST', $uri, server: [
                'HTTP_X_PM_CLIENT' => '1',
                'HTTP_X_CSRF_TOKEN' => $session['csrfToken'],
                'CONTENT_TYPE' => 'application/json',
            ], content: json_encode($body, \JSON_THROW_ON_ERROR));
        };

        $send('/api/auth/login', ['email' => $this->user->getEmail(), 'authProof' => $this->proof]);
        $send('/api/auth/2fa/check', ['code' => TotpService::codeAt(self::TOTP_SECRET, Clock::get()->now()->getTimestamp())]);
        \assert(200 === $client->getResponse()->getStatusCode());
    }
}

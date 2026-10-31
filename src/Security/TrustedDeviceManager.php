<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Repository\TrustedDeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OWASP "device cookie": proves that this browser already logged in to the account.
 *
 * Cookie value: base64url(nonce) "." base64url(HMAC-SHA256(secret, "pm-device|" email "|" nonce)).
 * Only SHA-256(nonce) is stored, so a database leak does not yield valid cookies.
 */
final readonly class TrustedDeviceManager
{
    public const int LIFETIME_DAYS = 90;
    private const string SECURE_COOKIE = '__Host-pm_device';
    private const string INSECURE_COOKIE = 'pm_device';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrustedDeviceRepository $repository,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
    }

    public function findValid(Request $request, string $email): ?TrustedDevice
    {
        $value = $request->cookies->get(self::cookieName($request));
        if (!\is_string($value) || 1 !== preg_match('/^([A-Za-z0-9_-]{43})\.([A-Za-z0-9_-]{43})$/', $value, $parts)) {
            return null;
        }

        $nonce = self::base64UrlDecode($parts[1]);
        if (!hash_equals($this->mac($email, $nonce), $parts[2])) {
            return null;
        }

        $device = $this->repository->findOneBy(['nonceHash' => hash('sha256', $nonce)]);
        if (null === $device
            || $device->getUser()->getEmail() !== $email
            || $device->getLastUsedAt() < new \DateTimeImmutable(\sprintf('-%d days', self::LIFETIME_DAYS))) {
            return null;
        }

        return $device;
    }

    public function issue(User $user, Request $request, Response $response): TrustedDevice
    {
        $nonce = random_bytes(32);
        $device = new TrustedDevice($user, hash('sha256', $nonce));
        $this->entityManager->persist($device);
        $this->entityManager->flush();

        $response->headers->setCookie(Cookie::create(self::cookieName($request))
            ->withValue(self::base64UrlEncode($nonce).'.'.$this->mac($user->getEmail(), $nonce))
            ->withExpires(new \DateTimeImmutable(\sprintf('+%d days', self::LIFETIME_DAYS)))
            ->withPath('/')
            ->withSecure($request->isSecure())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));

        return $device;
    }

    public function markUsed(TrustedDevice $device): void
    {
        $device->markUsed();
        $this->entityManager->flush();
    }

    /**
     * Revokes every device of the account (e.g. after a master password change).
     */
    public function revokeAll(User $user): void
    {
        $this->entityManager->createQueryBuilder()
            ->delete(TrustedDevice::class, 'd')
            ->where('d.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    public static function limiterKey(TrustedDevice $device): string
    {
        return $device->getNonceHash();
    }

    private function mac(string $email, string $nonce): string
    {
        return self::base64UrlEncode(hash_hmac('sha256', 'pm-device|'.$email.'|'.$nonce, $this->secret, true));
    }

    private static function cookieName(Request $request): string
    {
        return $request->isSecure() ? self::SECURE_COOKIE : self::INSECURE_COOKIE;
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}

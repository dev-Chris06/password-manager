<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * TOTP (RFC 6238): SHA-1, 6 digits, 30-second period, ±1 period of clock drift.
 * Every accepted time step is remembered so that a code can never be used twice.
 */
final readonly class TotpService
{
    public const string ISSUER = 'Gestionnaire MDP';
    private const int PERIOD = 30;
    private const int DRIFT_STEPS = 1;

    public function __construct(
        private TotpSecretCipher $cipher,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Starts an enrollment: stores a new secret waiting for confirmation.
     *
     * @return array{secret: string, uri: string, qrCode: string}
     */
    public function startEnrollment(User $user): array
    {
        $label = $user->getEmail();
        if ('' === $label) {
            throw new \LogicException('An account must have an email.');
        }
        $totp = TOTP::generate();
        $totp->setLabel($label);
        $totp->setIssuer(self::ISSUER);

        $user->setTotpPendingEncrypted($this->cipher->encrypt($user, $totp->getSecret()));
        $this->entityManager->flush();

        $uri = $totp->getProvisioningUri();
        $qrCode = (new Builder(writer: new SvgWriter(), data: $uri, size: 240, margin: 8))->build();

        return ['secret' => $totp->getSecret(), 'uri' => $uri, 'qrCode' => $qrCode->getDataUri()];
    }

    /**
     * Confirms the enrollment with a first valid code from the authenticator app.
     */
    public function confirmEnrollment(User $user, string $code): bool
    {
        $pending = $user->getTotpPendingEncrypted();
        if (null === $pending) {
            return false;
        }

        $secret = $this->cipher->decrypt($user, $pending);
        $counter = $this->matchingCounter($secret, $code, null);
        if (null === $counter) {
            return false;
        }

        $user->enableTotp($this->cipher->encrypt($user, $secret));
        $user->setTotpLastCounter($counter);
        $this->entityManager->flush();

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        $encrypted = $user->getTotpSecretEncrypted();
        if (null === $encrypted) {
            return false;
        }

        $counter = $this->matchingCounter($this->cipher->decrypt($user, $encrypted), $code, $user->getTotpLastCounter());
        if (null === $counter) {
            return false;
        }

        $user->setTotpLastCounter($counter);
        $this->entityManager->flush();

        return true;
    }

    /**
     * Code for a given secret and Unix time (used by the RFC 6238 test vectors).
     */
    public static function codeAt(string $secret, int $timestamp): string
    {
        if ('' === $secret || $timestamp < 0) {
            throw new \InvalidArgumentException('A TOTP needs a secret and a positive time.');
        }

        return TOTP::createFromSecret($secret)->at($timestamp);
    }

    private function matchingCounter(string $secret, string $code, ?int $lastCounter): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (1 !== preg_match('/^\d{6}$/', $code)) {
            return null;
        }

        $now = intdiv($this->clock->now()->getTimestamp(), self::PERIOD);
        $match = null;
        // Every candidate is computed and compared in constant time, whatever matches.
        for ($counter = max(0, $now - self::DRIFT_STEPS); $counter <= $now + self::DRIFT_STEPS; ++$counter) {
            if (hash_equals(self::codeAt($secret, $counter * self::PERIOD), $code) && (null === $lastCounter || $counter > $lastCounter)) {
                $match = $counter;
            }
        }

        return $match;
    }
}

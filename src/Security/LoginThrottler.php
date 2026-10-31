<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Login attempt limits (docs/PLAN.md §4.6).
 *
 * Without a trusted device cookie, attempts count per IP+email pair, per IP and per email.
 * With a valid device cookie only the per-device limiter applies: an attacker hammering the
 * owner's email from elsewhere can no longer lock the owner out.
 */
final readonly class LoginThrottler
{
    public function __construct(
        private RateLimiterFactoryInterface $loginPairShortLimiter,
        private RateLimiterFactoryInterface $loginPairLongLimiter,
        private RateLimiterFactoryInterface $loginIpShortLimiter,
        private RateLimiterFactoryInterface $loginIpLongLimiter,
        private RateLimiterFactoryInterface $loginEmailShortLimiter,
        private RateLimiterFactoryInterface $loginEmailLongLimiter,
        private RateLimiterFactoryInterface $loginDeviceLimiter,
    ) {
    }

    /**
     * Counts this attempt on every applicable limiter before the credentials are checked, so that
     * parallel requests cannot all pass a check made before any of them is counted.
     * Returns when the next attempt will be allowed if this one is refused, or null.
     */
    public function attempt(string $email, string $ip, ?string $deviceKey): ?\DateTimeImmutable
    {
        $until = null;
        foreach ($this->limiters($email, $ip, $deviceKey) as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted() && (null === $until || $limit->getRetryAfter() > $until)) {
                $until = $limit->getRetryAfter();
            }
        }

        return $until;
    }

    /**
     * A successful login frees the per-pair and per-device budgets (not the per-IP and
     * per-email ones, which also protect against slow guessing from many sources).
     */
    public function recordSuccess(string $email, string $ip, ?string $deviceKey): void
    {
        $this->loginPairShortLimiter->create(self::pairKey($email, $ip))->reset();
        $this->loginPairLongLimiter->create(self::pairKey($email, $ip))->reset();
        if (null !== $deviceKey) {
            $this->loginDeviceLimiter->create($deviceKey)->reset();
        }
    }

    /**
     * @return list<LimiterInterface>
     */
    private function limiters(string $email, string $ip, ?string $deviceKey): array
    {
        if (null !== $deviceKey) {
            return [$this->loginDeviceLimiter->create($deviceKey)];
        }

        $pair = self::pairKey($email, $ip);
        $emailKey = hash('sha256', $email);

        return [
            $this->loginPairShortLimiter->create($pair),
            $this->loginPairLongLimiter->create($pair),
            $this->loginIpShortLimiter->create($ip),
            $this->loginIpLongLimiter->create($ip),
            $this->loginEmailShortLimiter->create($emailKey),
            $this->loginEmailLongLimiter->create($emailKey),
        ];
    }

    private static function pairKey(string $email, string $ip): string
    {
        return hash('sha256', $ip.'|'.$email);
    }
}

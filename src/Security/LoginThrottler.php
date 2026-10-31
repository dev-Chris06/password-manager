<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Login attempt limits (docs/PLAN.md §4.6).
 *
 * Without a trusted device cookie, failures count per IP+email pair, per IP and per email.
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
     * Returns when the next attempt will be allowed, or null if an attempt is allowed now.
     * Never consumes a token.
     */
    public function blockedUntil(string $email, string $ip, ?string $deviceKey): ?\DateTimeImmutable
    {
        $until = null;
        foreach ($this->limiters($email, $ip, $deviceKey) as $limiter) {
            if ($limiter->consume(0)->getRemainingTokens() > 0) {
                continue;
            }
            // A rejected consumption does not count as a hit: it only reveals the retry date.
            $retryAfter = $limiter->consume()->getRetryAfter();
            if (null === $until || $retryAfter > $until) {
                $until = $retryAfter;
            }
        }

        return $until;
    }

    public function recordFailure(string $email, string $ip, ?string $deviceKey): void
    {
        foreach ($this->limiters($email, $ip, $deviceKey) as $limiter) {
            $limiter->consume();
        }
    }

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

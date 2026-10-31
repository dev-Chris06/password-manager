<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Checks a fresh authentication proof before a sensitive action (two-factor setup, backup
 * codes, master password change), with its own attempt limit.
 */
final readonly class ReauthenticationVerifier
{
    public function __construct(
        private UserPasswordHasherInterface $passwordHasher,
        private RateLimiterFactoryInterface $reauthenticationLimiter,
    ) {
    }

    public function verify(User $user, string $authProof): void
    {
        $limiter = $this->reauthenticationLimiter->create((string) $user->getId());
        if (0 === $limiter->consume(0)->getRemainingTokens()) {
            throw new TooManyRequestsHttpException(max(1, $limiter->consume()->getRetryAfter()->getTimestamp() - time()));
        }

        if (!$this->passwordHasher->isPasswordValid($user, $authProof)) {
            $limiter->consume();

            throw new AccessDeniedHttpException('Invalid authentication proof.');
        }
    }
}

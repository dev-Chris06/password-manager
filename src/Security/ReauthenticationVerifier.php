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
        // Counted before the check, so that parallel requests cannot exceed the budget.
        $limiter = $this->reauthenticationLimiter->create((string) $user->getId());
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }

        if (!$this->passwordHasher->isPasswordValid($user, $authProof)) {
            throw new AccessDeniedHttpException('Invalid authentication proof.');
        }
        $limiter->reset();
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Http\Authenticator\Passport\Badge\BadgeInterface;

/**
 * Marks a login whose second factor has just been verified: the token then gets full rights.
 */
final class SecondFactorCompletedBadge implements BadgeInterface
{
    public function isResolved(): bool
    {
        return true;
    }
}

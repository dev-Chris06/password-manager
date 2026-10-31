<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class TooManyAttemptsException extends AuthenticationException
{
    public function __construct(public readonly \DateTimeImmutable $retryAfter)
    {
        parent::__construct('Too many attempts.');
    }

    public function getMessageKey(): string
    {
        return 'Too many attempts.';
    }
}

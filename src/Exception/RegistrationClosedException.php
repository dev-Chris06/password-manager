<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class RegistrationClosedException extends HttpException implements ApiErrorCode
{
    public function __construct()
    {
        parent::__construct(403, 'Registration is closed: an account already exists.');
    }

    public function getErrorCode(): string
    {
        return 'registration_closed';
    }
}

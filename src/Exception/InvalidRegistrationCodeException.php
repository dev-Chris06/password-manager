<?php

declare(strict_types=1);

namespace App\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvalidRegistrationCodeException extends HttpException implements ApiErrorCode
{
    public function __construct()
    {
        parent::__construct(403, 'Invalid registration code.');
    }

    public function getErrorCode(): string
    {
        return 'invalid_registration_code';
    }
}

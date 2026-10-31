<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Implemented by HTTP exceptions that carry a more precise API error code than their status.
 */
interface ApiErrorCode
{
    public function getErrorCode(): string;
}

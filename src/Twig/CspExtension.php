<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonce;
use Twig\Attribute\AsTwigFunction;

final class CspExtension
{
    public function __construct(private readonly CspNonce $cspNonce)
    {
    }

    #[AsTwigFunction('csp_nonce')]
    public function nonce(): string
    {
        return $this->cspNonce->get();
    }
}

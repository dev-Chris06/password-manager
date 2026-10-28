<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Provides the Content-Security-Policy nonce of the current main request.
 *
 * The nonce is generated once per main request and stored in its attributes, so the
 * value rendered in templates is the same as the one sent in the response header.
 */
final class CspNonce
{
    private const string ATTRIBUTE = '_csp_nonce';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function get(): string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            throw new \LogicException('A CSP nonce can only be used during a request.');
        }

        $nonce = $request->attributes->get(self::ATTRIBUTE);
        if (!\is_string($nonce)) {
            $nonce = base64_encode(random_bytes(18));
            $request->attributes->set(self::ATTRIBUTE, $nonce);
        }

        return $nonce;
    }
}

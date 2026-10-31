<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonce;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds the security headers to every main response.
 *
 * The CSP only grants what the application needs. 'wasm-unsafe-eval' lets the Argon2id
 * WebAssembly module compile; it does not allow eval() of JavaScript. See docs/PLAN.md §4.6.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly CspNonce $cspNonce)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -64]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        $headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            \sprintf("script-src 'self' 'nonce-%s' 'wasm-unsafe-eval'", $this->cspNonce->get()),
            "style-src 'self'",
            "img-src 'self' data:",
            "connect-src 'self'",
            "font-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "object-src 'none'",
            "require-trusted-types-for 'script'",
            "trusted-types 'none'",
        ]));
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }
    }
}

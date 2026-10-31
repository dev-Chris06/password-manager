<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces the absolute session lifetime (4 hours after login, docs/PLAN.md §4.4) and the
 * 5-minute window to complete the second factor.
 * The idle timeout (15 minutes) is handled by the session storage itself.
 */
final class SessionLifetimeSubscriber implements EventSubscriberInterface
{
    public const int ABSOLUTE_LIFETIME_SECONDS = 4 * 3600;
    public const int SECOND_FACTOR_DELAY_SECONDS = 5 * 60;
    /** Same list as the PUBLIC_ACCESS rule of config/packages/security.yaml. */
    private const string PUBLIC_API_PATHS = '#^/api/(session|auth/(prelogin|register|login|logout))$#';

    public function __construct(private readonly TokenStorageInterface $tokenStorage)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Right after the firewall (8) has restored the token from the session.
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession() || null === $this->tokenStorage->getToken()) {
            return;
        }

        $session = $request->getSession();
        $authenticatedAt = $session->get(ProofAuthenticator::SESSION_AUTHENTICATED_AT);
        if (\is_int($authenticatedAt) && time() - $authenticatedAt <= self::ABSOLUTE_LIFETIME_SECONDS) {
            return;
        }
        // Between the two factors, the session only lives a few minutes.
        $firstFactorAt = $session->get(ProofAuthenticator::SESSION_FIRST_FACTOR_AT);
        if (\is_int($firstFactorAt) && time() - $firstFactorAt <= self::SECOND_FACTOR_DELAY_SECONDS) {
            return;
        }

        $this->tokenStorage->setToken(null);
        $session->invalidate();

        // Public endpoints (session state, login…) simply continue anonymously.
        if (ApiRequestSubscriber::isApiRequest($request) && 1 !== preg_match(self::PUBLIC_API_PATHS, $request->getPathInfo())) {
            $event->setResponse(ApiRequestSubscriber::error(401, 'session_expired'));
        }
    }
}

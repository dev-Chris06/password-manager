<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces the absolute session lifetime (4 hours after login, docs/PLAN.md §4.4).
 * The idle timeout (15 minutes) is handled by the session storage itself.
 */
final class SessionLifetimeSubscriber implements EventSubscriberInterface
{
    public const int ABSOLUTE_LIFETIME_SECONDS = 4 * 3600;

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

        $this->tokenStorage->setToken(null);
        $session->invalidate();

        if (ApiRequestSubscriber::isApiRequest($request)) {
            $event->setResponse(ApiRequestSubscriber::error(401, 'session_expired'));
        }
    }
}

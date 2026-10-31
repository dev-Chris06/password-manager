<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\ProofAuthenticator;
use App\Security\SessionLifetimeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SessionLifetimeSubscriberTest extends TestCase
{
    public function testARecentLoginIsKept(): void
    {
        [$event, $tokenStorage] = self::event('/api/vault', time() - 3600);

        (new SessionLifetimeSubscriber($tokenStorage))->onKernelRequest($event);

        self::assertNotNull($tokenStorage->getToken());
        self::assertNull($event->getResponse());
    }

    public function testALoginOlderThanFourHoursIsLoggedOut(): void
    {
        [$event, $tokenStorage] = self::event('/api/vault', time() - SessionLifetimeSubscriber::ABSOLUTE_LIFETIME_SECONDS - 1);

        (new SessionLifetimeSubscriber($tokenStorage))->onKernelRequest($event);

        self::assertNull($tokenStorage->getToken());
        self::assertSame(401, $event->getResponse()?->getStatusCode());
        self::assertSame('{"error":"session_expired"}', $event->getResponse()->getContent());
    }

    public function testASessionWithoutLoginTimeIsLoggedOut(): void
    {
        [$event, $tokenStorage] = self::event('/', null);

        (new SessionLifetimeSubscriber($tokenStorage))->onKernelRequest($event);

        self::assertNull($tokenStorage->getToken());
        self::assertNull($event->getResponse());
    }

    /**
     * @return array{RequestEvent, TokenStorage}
     */
    private static function event(string $path, ?int $authenticatedAt): array
    {
        $session = new Session(new MockArraySessionStorage());
        if (null !== $authenticatedAt) {
            $session->set(ProofAuthenticator::SESSION_AUTHENTICATED_AT, $authenticatedAt);
        }
        $request = Request::create($path);
        $request->setSession($session);
        $request->cookies->set($session->getName(), 'id');

        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('owner@example.com', null), 'main'));

        $kernel = self::createStub(HttpKernelInterface::class);

        return [new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST), $tokenStorage];
    }
}

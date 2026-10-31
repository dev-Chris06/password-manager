<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\TrustedDeviceManager;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class TrustedDeviceManagerTest extends KernelTestCase
{
    public function testAnIssuedCookieIsRecognisedOnlyForItsAccount(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(TrustedDeviceManager::class);
        $user = UserFactory::create($entityManager);

        $response = new Response();
        $device = $manager->issue($user, Request::create('/'), $response);
        $cookie = self::cookie($response);

        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertStringNotContainsString($device->getNonceHash(), (string) $cookie->getValue());

        self::assertSame($device, $manager->findValid(self::requestWith((string) $cookie->getValue()), 'owner@example.com'));
        self::assertNull($manager->findValid(self::requestWith((string) $cookie->getValue()), 'someone@example.com'));
    }

    public function testAForgedOrAlteredCookieIsRefused(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(TrustedDeviceManager::class);
        $response = new Response();
        $manager->issue(UserFactory::create($entityManager), Request::create('/'), $response);
        $value = (string) self::cookie($response)->getValue();

        [$nonce, $mac] = explode('.', $value);
        $forgedNonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        self::assertNull($manager->findValid(self::requestWith($forgedNonce.'.'.$mac), 'owner@example.com'));
        self::assertNull($manager->findValid(self::requestWith($nonce.'.'.strrev($mac)), 'owner@example.com'));
        self::assertNull($manager->findValid(self::requestWith('garbage'), 'owner@example.com'));
    }

    public function testRevokedDevicesAreNoLongerTrusted(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $manager = self::getContainer()->get(TrustedDeviceManager::class);
        $user = UserFactory::create($entityManager);
        $response = new Response();
        $manager->issue($user, Request::create('/'), $response);

        $manager->revokeAll($user);

        self::assertNull($manager->findValid(self::requestWith((string) self::cookie($response)->getValue()), 'owner@example.com'));
    }

    private static function cookie(Response $response): Cookie
    {
        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies);
        self::assertSame('pm_device', $cookies[0]->getName());

        return $cookies[0];
    }

    private static function requestWith(string $cookieValue): Request
    {
        return Request::create('/', cookies: ['pm_device' => $cookieValue]);
    }
}

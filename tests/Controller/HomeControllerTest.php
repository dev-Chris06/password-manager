<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageRenders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Gestionnaire de mots de passe');
        self::assertSelectorExists('html[lang="fr"]');
    }

    public function testSecurityHeadersAreSent(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertResponseHeaderSame('Cross-Origin-Opener-Policy', 'same-origin');
        self::assertResponseHeaderSame('Cross-Origin-Resource-Policy', 'same-origin');

        $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');
        foreach (["default-src 'self'", "frame-ancestors 'none'", "object-src 'none'", "base-uri 'none'", "require-trusted-types-for 'script'"] as $directive) {
            self::assertStringContainsString($directive, $csp);
        }
        self::assertStringNotContainsString('unsafe-inline', $csp);
        self::assertStringNotContainsString("'unsafe-eval'", $csp);
    }

    public function testHstsIsOnlySentOverHttps(): void
    {
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertResponseNotHasHeader('Strict-Transport-Security');

        $client->request('GET', 'https://localhost/');
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
    }

    public function testScriptNonceMatchesTheHeaderAndChangesOnEveryRequest(): void
    {
        $client = self::createClient();

        $nonces = [];
        for ($i = 0; $i < 2; ++$i) {
            $crawler = $client->request('GET', '/');
            $csp = (string) $client->getResponse()->headers->get('Content-Security-Policy');

            self::assertSame(1, preg_match("/'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $matches));
            self::assertSame($matches[1], $crawler->filter('script')->attr('nonce'));
            $nonces[] = $matches[1];
        }

        self::assertNotSame($nonces[0], $nonces[1]);
    }

    public function testUnknownRouteReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/pages/login.php');

        self::assertResponseStatusCodeSame(404);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiRequestSubscriberTest extends WebTestCase
{
    private const string EXTENSION_ORIGIN = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

    public function testRequestWithoutClientHeaderIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'client_header_missing'], self::json($client));
    }

    public function testSameOriginRequestReturnsTheCsrfToken(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1', 'HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseIsSuccessful();
        $data = self::json($client);
        self::assertFalse($data['authenticated']);
        self::assertIsString($data['csrfToken']);
        self::assertNotSame('', $data['csrfToken']);
        self::assertResponseHeaderSame('Cache-Control', 'no-store, private');
        self::assertResponseNotHasHeader('Access-Control-Allow-Origin');
    }

    public function testForeignOriginIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1', 'HTTP_ORIGIN' => 'https://evil.example']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'origin_not_allowed'], self::json($client));
    }

    public function testCrossSiteFetchWithoutOriginIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testExtensionOriginReceivesCorsHeaders(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1', 'HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::EXTENSION_ORIGIN);
        self::assertResponseHeaderSame('Access-Control-Allow-Credentials', 'true');
    }

    public function testPreflightIsOnlyAnsweredForTheExtension(): void
    {
        $client = self::createClient();

        $client->request('OPTIONS', '/api/session', server: ['HTTP_ORIGIN' => self::EXTENSION_ORIGIN]);
        self::assertResponseStatusCodeSame(204);
        self::assertResponseHeaderSame('Access-Control-Allow-Origin', self::EXTENSION_ORIGIN);
        self::assertStringContainsString('X-CSRF-Token', (string) $client->getResponse()->headers->get('Access-Control-Allow-Headers'));

        $client->request('OPTIONS', '/api/session', server: ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testMutationRequiresJson(): void
    {
        $client = self::createClient();
        $client->request('POST', '/api/session', ['a' => 'b'], server: ['HTTP_X_PM_CLIENT' => '1']);

        self::assertResponseStatusCodeSame(415);
    }

    public function testMutationRequiresAValidCsrfToken(): void
    {
        $client = self::createClient();
        self::postJson($client, '/api/session', 'not-the-token');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'invalid_csrf_token'], self::json($client));
    }

    public function testMutationWithValidCsrfTokenReachesTheRouter(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1']);
        $token = self::json($client)['csrfToken'];
        self::assertIsString($token);

        self::postJson($client, '/api/session', $token);

        // The route only accepts GET: the request went through every API check.
        self::assertResponseStatusCodeSame(405);
        self::assertSame(['error' => 'method_not_allowed'], self::json($client));
    }

    public function testUnknownApiRouteReturnsJson404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/does-not-exist', server: ['HTTP_X_PM_CLIENT' => '1']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['error' => 'not_found'], self::json($client));
    }

    public function testForwardedForIsIgnoredWithoutTrustedProxy(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/session', server: [
            'HTTP_X_PM_CLIENT' => '1',
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        self::assertSame('203.0.113.10', $client->getRequest()->getClientIp());
    }

    private static function postJson(KernelBrowser $client, string $uri, string $csrfToken): void
    {
        $client->request('POST', $uri, server: [
            'HTTP_X_PM_CLIENT' => '1',
            'HTTP_X_CSRF_TOKEN' => $csrfToken,
            'CONTENT_TYPE' => 'application/json',
        ], content: '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(KernelBrowser $client): array
    {
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }
}

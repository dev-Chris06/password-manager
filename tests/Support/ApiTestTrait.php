<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Calls the JSON API the way the web client does (client header, CSRF token, JSON body).
 */
trait ApiTestTrait
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string>     $server
     *
     * @return array<string, mixed>
     */
    private static function api(KernelBrowser $client, string $method, string $uri, ?array $body = null, array $server = []): array
    {
        $headers = ['HTTP_X_PM_CLIENT' => '1'] + $server;
        if ('GET' !== $method) {
            $headers['HTTP_X_CSRF_TOKEN'] = self::csrfToken($client);
            $headers['CONTENT_TYPE'] = 'application/json';
        }

        $client->request($method, $uri, server: $headers, content: null === $body ? '{}' : json_encode($body, \JSON_THROW_ON_ERROR));

        return self::responseData($client);
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseData(KernelBrowser $client): array
    {
        $content = (string) $client->getResponse()->getContent();
        if ('' === $content) {
            return [];
        }
        $data = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }

    private static function csrfToken(KernelBrowser $client): string
    {
        $client->request('GET', '/api/session', server: ['HTTP_X_PM_CLIENT' => '1']);
        $token = self::responseData($client)['csrfToken'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /**
     * Registration data as the client would compute it (random values stand in for the derived ones).
     *
     * @return array<string, mixed>
     */
    private static function registrationPayload(string $email, string $authProof): array
    {
        return [
            'email' => $email,
            'kdf' => ['algorithm' => 'argon2id', 'memoryKib' => 65536, 'iterations' => 3, 'parallelism' => 1],
            'salt' => base64_encode(random_bytes(16)),
            'authProof' => $authProof,
            'wrappedVaultKey' => base64_encode(random_bytes(61)),
        ];
    }

    private static function newProof(): string
    {
        return base64_encode(random_bytes(32));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\ApiTestTrait;
use App\Tests\Support\TestAccount;
use App\TwoFactor\TotpSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class EntryControllerTest extends WebTestCase
{
    use ApiTestTrait;
    use ClockSensitiveTrait;

    private KernelBrowser $client;
    private TestAccount $owner;

    protected function setUp(): void
    {
        self::mockTime('2026-10-31 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->owner = self::account('owner@example.com');
        $this->owner->login($this->client);
    }

    public function testEntriesAreCreatedListedUpdatedAndDeleted(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $created = self::api($this->client, 'POST', '/api/entries', ['uuid' => $uuid, 'ciphertext' => self::ciphertext()]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['uuid' => $uuid, 'version' => 1], array_intersect_key($created, ['uuid' => 1, 'version' => 1]));

        $vault = self::api($this->client, 'GET', '/api/vault');
        self::assertIsArray($vault['entries']);
        self::assertCount(1, $vault['entries']);

        $newCiphertext = self::ciphertext();
        $updated = self::api($this->client, 'PUT', '/api/entries/'.$uuid, ['ciphertext' => $newCiphertext, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $updated['version']);
        $vault = self::api($this->client, 'GET', '/api/vault');
        self::assertIsArray($vault['entries']);
        self::assertIsArray($vault['entries'][0]);
        self::assertSame($newCiphertext, $vault['entries'][0]['ciphertext']);

        self::api($this->client, 'DELETE', '/api/entries/'.$uuid);
        self::assertResponseIsSuccessful();
        self::assertSame([], self::api($this->client, 'GET', '/api/vault')['entries']);
    }

    public function testAStaleVersionIsRejected(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        self::api($this->client, 'POST', '/api/entries', ['uuid' => $uuid, 'ciphertext' => self::ciphertext()]);
        self::api($this->client, 'PUT', '/api/entries/'.$uuid, ['ciphertext' => self::ciphertext(), 'version' => 1]);

        $data = self::api($this->client, 'PUT', '/api/entries/'.$uuid, ['ciphertext' => self::ciphertext(), 'version' => 1]);

        self::assertResponseStatusCodeSame(409);
        self::assertSame(['error' => 'version_conflict'], $data);
    }

    public function testAnotherAccountCanNeitherReadNorChangeTheEntries(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        $ciphertext = self::ciphertext();
        self::api($this->client, 'POST', '/api/entries', ['uuid' => $uuid, 'ciphertext' => $ciphertext]);
        self::api($this->client, 'POST', '/api/auth/logout');

        $intruder = self::account('intruder@example.com');
        self::mockTime('+60 seconds');
        $intruder->login($this->client);

        self::assertSame([], self::api($this->client, 'GET', '/api/vault')['entries']);
        self::api($this->client, 'PUT', '/api/entries/'.$uuid, ['ciphertext' => self::ciphertext(), 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
        self::api($this->client, 'DELETE', '/api/entries/'.$uuid);
        self::assertResponseStatusCodeSame(404);
        self::api($this->client, 'POST', '/api/entries', ['uuid' => $uuid, 'ciphertext' => self::ciphertext()]);
        self::assertResponseStatusCodeSame(409);

        $stored = self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->fetchOne('SELECT ciphertext FROM vault_entry WHERE uuid = ?', [Uuid::fromString($uuid)->toBinary()]);
        self::assertSame($ciphertext, $stored);
    }

    public function testInvalidPayloadsAreRejected(): void
    {
        self::api($this->client, 'POST', '/api/entries', ['uuid' => 'not-a-uuid', 'ciphertext' => self::ciphertext()]);
        self::assertResponseStatusCodeSame(422);
        self::api($this->client, 'POST', '/api/entries', ['uuid' => Uuid::v4()->toRfc4122(), 'ciphertext' => 'not base64 !']);
        self::assertResponseStatusCodeSame(422);
        self::api($this->client, 'POST', '/api/entries', ['uuid' => Uuid::v4()->toRfc4122(), 'ciphertext' => base64_encode(random_bytes(70_000))]);
        self::assertResponseStatusCodeSame(422);
        self::api($this->client, 'PUT', '/api/entries/'.Uuid::v4()->toRfc4122(), ['ciphertext' => self::ciphertext(), 'version' => 1]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheJournalListsActionsWithoutContent(): void
    {
        $uuid = Uuid::v4()->toRfc4122();
        self::api($this->client, 'POST', '/api/entries', ['uuid' => $uuid, 'ciphertext' => self::ciphertext()]);

        $journal = self::api($this->client, 'GET', '/api/audit-log');

        self::assertIsArray($journal['items']);
        self::assertIsArray($journal['items'][0]);
        self::assertSame('entry_created', $journal['items'][0]['action']);
        self::assertSame($uuid, $journal['items'][0]['detail']);
        self::assertSame(1, $journal['page']);
    }

    public function testTheApiNeedsAFullyAuthenticatedSession(): void
    {
        self::api($this->client, 'POST', '/api/auth/logout');

        self::api($this->client, 'POST', '/api/entries', ['uuid' => Uuid::v4()->toRfc4122(), 'ciphertext' => self::ciphertext()]);
        self::assertResponseStatusCodeSame(401);
        self::api($this->client, 'GET', '/api/audit-log');
        self::assertResponseStatusCodeSame(401);
    }

    private static function account(string $email): TestAccount
    {
        return TestAccount::create(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(TotpSecretCipher::class),
            $email,
        );
    }

    private static function ciphertext(): string
    {
        return base64_encode("\x01".random_bytes(90));
    }
}

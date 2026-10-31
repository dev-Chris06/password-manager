<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ApiTestTrait;
use App\Tests\Support\TestAccount;
use App\TwoFactor\TotpSecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AccountControllerTest extends WebTestCase
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
        $this->owner = TestAccount::create(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(TotpSecretCipher::class),
            'owner@example.com',
        );
        $this->owner->login($this->client);
    }

    public function testChangingTheMasterPasswordReplacesEveryDerivedValue(): void
    {
        $newProof = self::newProof();
        $payload = self::credentials($this->owner->proof, $newProof);

        $data = self::api($this->client, 'POST', '/api/account/password', $payload);

        self::assertResponseIsSuccessful();
        self::assertSame('done', $data['step']);
        $user = $this->reloadUser();
        self::assertSame($payload['salt'], $user->getKdfSalt());
        self::assertSame($payload['wrappedVaultKey'], $user->getWrappedVaultKey());
        self::assertTrue(password_verify($newProof, $user->getAuthHash()));

        // The current session goes on with the new credentials.
        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseIsSuccessful();
    }

    public function testTheCurrentProofIsRequired(): void
    {
        self::api($this->client, 'POST', '/api/account/password', self::credentials(self::newProof(), self::newProof()));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue(password_verify($this->owner->proof, $this->reloadUser()->getAuthHash()));
    }

    public function testAKeyRotationMustReEncryptEveryEntry(): void
    {
        $first = Uuid::v4()->toRfc4122();
        $second = Uuid::v4()->toRfc4122();
        self::api($this->client, 'POST', '/api/entries/batch', ['entries' => [
            ['uuid' => $first, 'ciphertext' => self::ciphertext()],
            ['uuid' => $second, 'ciphertext' => self::ciphertext()],
        ]]);
        self::assertResponseStatusCodeSame(201);

        $partial = self::credentials($this->owner->proof, self::newProof()) + ['entries' => [['uuid' => $first, 'ciphertext' => self::ciphertext()]]];
        self::api($this->client, 'POST', '/api/account/password', $partial);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue(password_verify($this->owner->proof, $this->reloadUser()->getAuthHash()), 'Nothing changed after a refused rotation.');

        $rotatedFirst = self::ciphertext();
        $complete = self::credentials($this->owner->proof, self::newProof()) + ['entries' => [
            ['uuid' => $first, 'ciphertext' => $rotatedFirst],
            ['uuid' => $second, 'ciphertext' => self::ciphertext()],
        ]];
        self::api($this->client, 'POST', '/api/account/password', $complete);
        self::assertResponseIsSuccessful();

        $vault = self::api($this->client, 'GET', '/api/vault');
        self::assertIsArray($vault['entries']);
        $byUuid = array_column($vault['entries'], 'ciphertext', 'uuid');
        self::assertSame($rotatedFirst, $byUuid[$first]);
    }

    public function testOtherSessionsAndTrustedDevicesAreClosed(): void
    {
        $otherBrowser = self::getContainer()->get('test.client');
        $otherBrowser->disableReboot();
        self::mockTime('+60 seconds');
        $this->owner->login($otherBrowser);
        self::api($otherBrowser, 'GET', '/api/vault');
        self::assertSame(200, $otherBrowser->getResponse()->getStatusCode());

        self::api($this->client, 'POST', '/api/account/password', self::credentials($this->owner->proof, self::newProof()));
        self::assertResponseIsSuccessful();

        self::api($otherBrowser, 'GET', '/api/vault');
        self::assertSame(401, $otherBrowser->getResponse()->getStatusCode());
        $devices = self::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne('SELECT COUNT(*) FROM trusted_device');
        self::assertSame(1, (int) $devices, 'Only the device cookie of the current browser remains.');
    }

    public function testABatchImportIsAllOrNothing(): void
    {
        $existing = Uuid::v4()->toRfc4122();
        self::api($this->client, 'POST', '/api/entries', ['uuid' => $existing, 'ciphertext' => self::ciphertext()]);

        self::api($this->client, 'POST', '/api/entries/batch', ['entries' => [
            ['uuid' => Uuid::v4()->toRfc4122(), 'ciphertext' => self::ciphertext()],
            ['uuid' => $existing, 'ciphertext' => self::ciphertext()],
        ]]);
        self::assertResponseStatusCodeSame(409);

        $vault = self::api($this->client, 'GET', '/api/vault');
        self::assertIsArray($vault['entries']);
        self::assertCount(1, $vault['entries']);

        $tooMany = array_fill(0, 201, ['uuid' => Uuid::v4()->toRfc4122(), 'ciphertext' => self::ciphertext()]);
        self::api($this->client, 'POST', '/api/entries/batch', ['entries' => $tooMany]);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<string, mixed>
     */
    private static function credentials(string $currentProof, string $newProof): array
    {
        return [
            'currentAuthProof' => $currentProof,
            'kdf' => ['algorithm' => 'argon2id', 'memoryKib' => 65536, 'iterations' => 4, 'parallelism' => 1],
            'salt' => base64_encode(random_bytes(16)),
            'authProof' => $newProof,
            'wrappedVaultKey' => base64_encode(random_bytes(61)),
        ];
    }

    private function reloadUser(): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'owner@example.com']);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    private static function ciphertext(): string
    {
        return base64_encode("\x01".random_bytes(90));
    }
}

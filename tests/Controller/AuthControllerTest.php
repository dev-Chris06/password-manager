<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use App\Repository\UserRepository;
use App\Tests\Support\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
    use ApiTestTrait;

    private const string EMAIL = 'owner@example.com';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testRegistrationStoresOnlyDerivedValues(): void
    {
        $proof = self::newProof();
        $payload = self::registrationPayload(self::EMAIL, $proof);

        self::api($this->client, 'POST', '/api/auth/register', $payload);

        self::assertResponseStatusCodeSame(201);
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotSame($proof, $user->getAuthHash());
        self::assertTrue(password_verify($proof, $user->getAuthHash()));
        self::assertSame($payload['wrappedVaultKey'], $user->getWrappedVaultKey());
        self::assertSame($payload['salt'], $user->getKdfSalt());
    }

    public function testRegistrationIsClosedOnceAnAccountExists(): void
    {
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, self::newProof()));
        $data = self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload('other@example.com', self::newProof()));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'registration_closed'], $data);
    }

    public function testRegistrationRejectsWeakKdfAndMalformedValues(): void
    {
        $weak = self::registrationPayload(self::EMAIL, self::newProof());
        $weak['kdf'] = ['algorithm' => 'argon2id', 'memoryKib' => 1024, 'iterations' => 1, 'parallelism' => 1];
        self::api($this->client, 'POST', '/api/auth/register', $weak);
        self::assertResponseStatusCodeSame(422);

        $shortProof = self::registrationPayload(self::EMAIL, base64_encode(random_bytes(16)));
        self::api($this->client, 'POST', '/api/auth/register', $shortProof);
        self::assertResponseStatusCodeSame(422);

        $badEmail = self::registrationPayload('not-an-email', self::newProof());
        self::api($this->client, 'POST', '/api/auth/register', $badEmail);
        self::assertResponseStatusCodeSame(422);
    }

    public function testPreloginDoesNotRevealWhetherAnAccountExists(): void
    {
        $payload = self::registrationPayload(self::EMAIL, self::newProof());
        self::api($this->client, 'POST', '/api/auth/register', $payload);

        $known = self::api($this->client, 'POST', '/api/auth/prelogin', ['email' => self::EMAIL]);
        self::assertSame($payload['salt'], $known['salt']);

        $unknown = self::api($this->client, 'POST', '/api/auth/prelogin', ['email' => 'nobody@example.com']);
        $again = self::api($this->client, 'POST', '/api/auth/prelogin', ['email' => 'nobody@example.com']);
        self::assertSame(array_keys($known), array_keys($unknown));
        self::assertSame($known['kdf'], $unknown['kdf']);
        self::assertIsString($unknown['salt']);
        self::assertSame(16, \strlen((string) base64_decode($unknown['salt'], true)));
        self::assertSame($unknown, $again);
    }

    public function testLoginWithTheProofOpensTheVault(): void
    {
        $proof = self::newProof();
        $payload = self::registrationPayload(self::EMAIL, $proof);
        self::api($this->client, 'POST', '/api/auth/register', $payload);

        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseStatusCodeSame(401);

        $data = self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
        self::assertResponseIsSuccessful();
        self::assertSame('2fa-setup', $data['step']);
        self::enrollTwoFactor($this->client, $proof);
        self::assertNotNull($this->client->getCookieJar()->get('pm_device'));

        $vault = self::api($this->client, 'GET', '/api/vault');
        self::assertResponseIsSuccessful();
        self::assertSame($payload['wrappedVaultKey'], $vault['wrappedVaultKey']);
        self::assertSame([], $vault['entries']);

        $session = self::api($this->client, 'GET', '/api/session');
        self::assertTrue($session['authenticated']);
        self::assertSame(self::EMAIL, $session['email']);
    }

    public function testWrongProofAndUnknownEmailFailTheSameWay(): void
    {
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, self::newProof()));

        $wrong = self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => self::newProof()]);
        self::assertResponseStatusCodeSame(401);
        $unknown = self::api($this->client, 'POST', '/api/auth/login', ['email' => 'nobody@example.com', 'authProof' => self::newProof()]);
        self::assertResponseStatusCodeSame(401);
        self::assertSame($wrong, $unknown);
        self::assertSame(['error' => 'invalid_credentials'], $wrong);

        $actions = array_map(static fn ($log) => $log->getAction(), self::getContainer()->get(AuditLogRepository::class)->findAll());
        self::assertContains('login_failure', $actions);
    }

    public function testTheStoredHashCannotBeReplayedAsAProof(): void
    {
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, self::newProof()));
        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);

        self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $user->getAuthHash()]);
        self::assertResponseStatusCodeSame(401);
        self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => base64_encode(hash('sha256', $user->getAuthHash(), true))]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRepeatedFailuresBlockEvenTheRightProof(): void
    {
        $proof = self::newProof();
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, $proof));

        for ($i = 0; $i < 5; ++$i) {
            self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => self::newProof()]);
            self::assertResponseStatusCodeSame(401);
        }

        $data = self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => 'too_many_requests'], $data);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
    }

    public function testATrustedDeviceIsNotLockedOutByAttacksFromElsewhere(): void
    {
        $proof = self::newProof();
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, $proof));
        self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
        self::enrollTwoFactor($this->client, $proof);
        self::api($this->client, 'POST', '/api/auth/logout');
        $deviceCookie = $this->client->getCookieJar()->get('pm_device');
        self::assertNotNull($deviceCookie);

        // An attacker exhausts the per-email limit from many addresses, without the device cookie.
        $this->client->getCookieJar()->expire('pm_device');
        for ($i = 0; $i < 10; ++$i) {
            self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => self::newProof()], ['REMOTE_ADDR' => '198.51.100.'.$i]);
        }
        self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof], ['REMOTE_ADDR' => '198.51.100.99']);
        self::assertResponseStatusCodeSame(429);

        // The owner, from a browser that already logged in, still gets in.
        $this->client->getCookieJar()->set($deviceCookie);
        $data = self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
        self::assertResponseIsSuccessful();
        self::assertSame('2fa', $data['step']);
    }

    public function testLogoutEndsTheSession(): void
    {
        $proof = self::newProof();
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, $proof));
        self::api($this->client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
        self::enrollTwoFactor($this->client, $proof);

        self::api($this->client, 'POST', '/api/auth/logout');
        self::assertResponseIsSuccessful();

        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseStatusCodeSame(401);
    }
}

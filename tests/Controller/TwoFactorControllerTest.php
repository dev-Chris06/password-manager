<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Support\ApiTestTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

final class TwoFactorControllerTest extends WebTestCase
{
    use ApiTestTrait;
    use ClockSensitiveTrait;

    private const string EMAIL = 'owner@example.com';

    private KernelBrowser $client;
    private string $proof;

    protected function setUp(): void
    {
        self::mockTime('2026-10-31 10:00:00');
        $this->client = self::createClient();
        $this->client->disableReboot();
        self::getContainer()->get('cache.rate_limiter')->clear();

        $this->proof = self::newProof();
        self::api($this->client, 'POST', '/api/auth/register', self::registrationPayload(self::EMAIL, $this->proof));
    }

    public function testEnrollmentIsMandatoryBeforeAccessingTheVault(): void
    {
        $login = self::login($this->client, $this->proof);
        self::assertSame('2fa-setup', $login['step']);

        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseStatusCodeSame(403);
        self::assertFalse(self::api($this->client, 'GET', '/api/session')['authenticated']);

        $enrollment = self::enrollTwoFactor($this->client, $this->proof);
        self::assertCount(10, $enrollment['backupCodes']);
        self::assertMatchesRegularExpression('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $enrollment['backupCodes'][0]);

        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseIsSuccessful();
    }

    public function testSetupRequiresAFreshProofAndStoresTheSecretEncrypted(): void
    {
        self::login($this->client, $this->proof);

        self::api($this->client, 'POST', '/api/2fa/setup', ['authProof' => self::newProof()]);
        self::assertResponseStatusCodeSame(403);

        $setup = self::api($this->client, 'POST', '/api/2fa/setup', ['authProof' => $this->proof]);
        self::assertResponseIsSuccessful();
        self::assertIsString($setup['secret']);
        self::assertIsString($setup['qrCode']);
        self::assertStringStartsWith('data:image/svg+xml;base64,', $setup['qrCode']);
        self::assertIsString($setup['uri']);
        self::assertStringStartsWith('otpauth://totp/', $setup['uri']);

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::EMAIL]);
        self::assertInstanceOf(User::class, $user);
        self::assertNotNull($user->getTotpPendingEncrypted());
        self::assertStringNotContainsString($setup['secret'], (string) base64_decode($user->getTotpPendingEncrypted(), true));
    }

    public function testAWrongFirstCodeDoesNotEnableTwoFactor(): void
    {
        self::login($this->client, $this->proof);
        self::api($this->client, 'POST', '/api/2fa/setup', ['authProof' => $this->proof]);

        $data = self::api($this->client, 'POST', '/api/2fa/enable', ['code' => '000000']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['error' => 'invalid_code'], $data);
    }

    public function testLoginNeedsTheSecondFactorAndRefusesAReplayedCode(): void
    {
        $secret = $this->enrollAndLogout();
        Clock::get()->sleep(60);

        self::assertSame('2fa', self::login($this->client, $this->proof)['step']);
        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseStatusCodeSame(403);

        $code = self::totp($secret);
        self::assertSame('done', self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => $code])['step']);
        self::api($this->client, 'GET', '/api/vault');
        self::assertResponseIsSuccessful();
        self::assertNotNull($this->client->getCookieJar()->get('pm_device'));

        self::api($this->client, 'POST', '/api/auth/logout');
        self::login($this->client, $this->proof);
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => $code]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testCodesFromTheAdjacentPeriodsAreAcceptedButNotFurther(): void
    {
        $secret = $this->enrollAndLogout();
        Clock::get()->sleep(120);
        $now = Clock::get()->now()->getTimestamp();

        self::login($this->client, $this->proof);
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => \App\TwoFactor\TotpService::codeAt($secret, $now - 60)]);
        self::assertResponseStatusCodeSame(401);
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => \App\TwoFactor\TotpService::codeAt($secret, $now - 30)]);
        self::assertResponseIsSuccessful();
    }

    public function testABackupCodeWorksOnlyOnce(): void
    {
        self::login($this->client, $this->proof);
        $backupCode = self::enrollTwoFactor($this->client, $this->proof)['backupCodes'][3];
        self::api($this->client, 'POST', '/api/auth/logout');

        self::login($this->client, $this->proof);
        $data = self::api($this->client, 'POST', '/api/auth/2fa/check', ['backupCode' => strtolower(str_replace('-', ' ', $backupCode))]);
        self::assertSame('done', $data['step']);
        self::assertSame(9, self::api($this->client, 'GET', '/api/vault')['backupCodesRemaining']);

        self::api($this->client, 'POST', '/api/auth/logout');
        self::login($this->client, $this->proof);
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['backupCode' => $backupCode]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testFiveWrongCodesDropTheHalfOpenLogin(): void
    {
        $this->enrollAndLogout();
        self::login($this->client, $this->proof);

        for ($i = 0; $i < 4; ++$i) {
            self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => '000000']);
            self::assertResponseStatusCodeSame(401);
        }
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => '000000']);
        self::assertResponseStatusCodeSame(429);

        // The proof alone no longer gives access to the second-factor step.
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => '000000']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTheSecondFactorMustBeCompletedWithinFiveMinutes(): void
    {
        $secret = $this->enrollAndLogout();
        self::login($this->client, $this->proof);

        Clock::get()->sleep(301);
        $session = $this->client->getRequest()->getSession();
        $session->set('_pm_first_factor_at', time() - 301);
        $session->save();

        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => self::totp($secret)]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testBackupCodesCanBeRegeneratedWithProofAndCode(): void
    {
        $secret = $this->enrollAndLogout();
        Clock::get()->sleep(60);
        self::login($this->client, $this->proof);
        self::api($this->client, 'POST', '/api/auth/2fa/check', ['code' => self::totp($secret)]);
        Clock::get()->sleep(60);

        $data = self::api($this->client, 'POST', '/api/2fa/backup-codes', ['authProof' => $this->proof, 'code' => self::totp($secret)]);

        self::assertResponseIsSuccessful();
        self::assertIsArray($data['backupCodes']);
        self::assertCount(10, $data['backupCodes']);
    }

    private function enrollAndLogout(): string
    {
        self::login($this->client, $this->proof);
        $secret = self::enrollTwoFactor($this->client, $this->proof)['secret'];
        self::api($this->client, 'POST', '/api/auth/logout');

        return $secret;
    }

    /**
     * @return array<string, mixed>
     */
    private static function login(KernelBrowser $client, string $proof): array
    {
        return self::api($client, 'POST', '/api/auth/login', ['email' => self::EMAIL, 'authProof' => $proof]);
    }
}

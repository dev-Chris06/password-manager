<?php

declare(strict_types=1);

namespace App\Tests\TwoFactor;

use App\Tests\Support\TestAccount;
use App\TwoFactor\BackupCodeService;
use App\TwoFactor\TotpSecretCipher;
use App\TwoFactor\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A request working on a stale copy of the account (as a parallel request would) must not be
 * able to reuse a code already claimed by another one.
 */
final class ParallelUseTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    public function testATotpCodeCannotBeUsedTwiceInParallel(): void
    {
        self::mockTime('2026-10-31 10:00:00');
        $account = $this->account();
        $code = TotpService::codeAt(TestAccount::TOTP_SECRET, Clock::get()->now()->getTimestamp());

        // Another request already used this code: the database moved on, this entity did not.
        $step = intdiv(Clock::get()->now()->getTimestamp(), 30);
        self::getContainer()->get(EntityManagerInterface::class)->getConnection()
            ->executeStatement('UPDATE app_user SET totp_last_counter = ? WHERE id = ?', [$step, $account->user->getId()]);

        self::assertFalse(self::getContainer()->get(TotpService::class)->verify($account->user, $code));
    }

    public function testABackupCodeCannotBeUsedTwiceInParallel(): void
    {
        $account = $this->account();
        $service = self::getContainer()->get(BackupCodeService::class);
        $code = $service->regenerate($account->user)[0];

        self::assertTrue($service->consume($account->user, $code));
        self::assertFalse($service->consume($account->user, $code));
    }

    private function account(): TestAccount
    {
        return TestAccount::create(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(TotpSecretCipher::class),
            'owner@example.com',
        );
    }
}

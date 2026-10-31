<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\UserFactory;
use App\TwoFactor\BackupCodeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ResetTwoFactorCommandTest extends KernelTestCase
{
    public function testTheOwnerCanResetTwoFactorFromTheServer(): void
    {
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = UserFactory::create($entityManager);
        $user->enableTotp('encrypted');
        $entityManager->flush();
        self::getContainer()->get(BackupCodeService::class)->regenerate($user);

        $tester = new CommandTester((new Application($kernel))->find('app:2fa:reset'));
        $tester->execute(['email' => 'OWNER@example.com']);

        $tester->assertCommandIsSuccessful();
        $entityManager->refresh($user);
        self::assertFalse($user->isTotpEnabled());
        self::assertSame(0, self::getContainer()->get(BackupCodeService::class)->remaining($user));
    }

    public function testAnUnknownEmailFails(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester((new Application($kernel))->find('app:2fa:reset'));

        self::assertSame(1, $tester->execute(['email' => 'nobody@example.com']));
    }
}

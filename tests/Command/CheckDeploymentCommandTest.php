<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Platform\PlatformRequirements;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckDeploymentCommandTest extends KernelTestCase
{
    public function testANonProductionInstallationIsRefused(): void
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:check-deployment'));

        self::assertSame(1, $tester->execute([]));
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('APP_ENV must be "prod"', $output);
        self::assertStringContainsString('APP_SECRET must be a unique random value', $output);
        self::assertStringContainsString('DEFAULT_URI must be the public HTTPS address', $output);
    }

    public function testVulnerablePhpReleasesAreRefused(): void
    {
        self::assertNotSame([], array_filter(PlatformRequirements::problems('8.3.35'), static fn (string $p): bool => str_contains($p, 'security fixes')));
        self::assertNotSame([], array_filter(PlatformRequirements::problems('8.4.26'), static fn (string $p): bool => str_contains($p, 'security fixes')));
        self::assertNotSame([], array_filter(PlatformRequirements::problems('8.2.40'), static fn (string $p): bool => str_contains($p, 'not a supported branch')));
        self::assertSame([], array_filter(PlatformRequirements::problems('8.4.27'), static fn (string $p): bool => str_contains($p, 'PHP 8.4')));
        self::assertSame([], array_filter(PlatformRequirements::problems('8.5.12'), static fn (string $p): bool => str_contains($p, 'PHP 8.5')));
    }
}

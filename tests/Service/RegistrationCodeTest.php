<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\InvalidRegistrationCodeException;
use App\Model\KdfParameters;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Registration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegistrationCodeTest extends KernelTestCase
{
    private function registration(?string $code): Registration
    {
        return new Registration(
            self::getContainer()->get(EntityManagerInterface::class),
            self::getContainer()->get(UserRepository::class),
            self::getContainer()->get(UserPasswordHasherInterface::class),
            self::getContainer()->get(LockFactory::class),
            self::getContainer()->get(AuditLogger::class),
            $code,
        );
    }

    public function testAConfiguredCodeIsRequired(): void
    {
        $registration = $this->registration('s3cret-registration-code');
        self::assertTrue($registration->requiresCode());

        foreach ([null, '', 'wrong'] as $code) {
            try {
                $registration->register('owner@example.com', KdfParameters::defaults(), base64_encode(random_bytes(16)), base64_encode(random_bytes(32)), base64_encode(random_bytes(61)), $code);
                self::fail('A wrong code must be refused.');
            } catch (InvalidRegistrationCodeException) {
            }
        }
        self::assertTrue($registration->isOpen());

        $registration->register('owner@example.com', KdfParameters::defaults(), base64_encode(random_bytes(16)), base64_encode(random_bytes(32)), base64_encode(random_bytes(61)), 's3cret-registration-code');
        self::assertFalse($registration->isOpen());
    }

    public function testWithoutCodeRegistrationStaysOpenToTheFirstComer(): void
    {
        self::assertFalse($this->registration(null)->requiresCode());
        self::assertFalse($this->registration('')->requiresCode());
    }
}

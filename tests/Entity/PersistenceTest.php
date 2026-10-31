<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AuditLog;
use App\Entity\BackupCode;
use App\Entity\TrustedDevice;
use App\Entity\VaultEntry;
use App\Repository\VaultEntryRepository;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class PersistenceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testVaultEntryIsStoredAsAnOpaqueBlob(): void
    {
        $user = UserFactory::create($this->entityManager);
        $uuid = Uuid::v4();
        $blob = base64_encode("\x01".random_bytes(60));

        $this->entityManager->persist(new VaultEntry($uuid, $user, $blob));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $entry = self::getContainer()->get(VaultEntryRepository::class)->find($uuid);
        self::assertInstanceOf(VaultEntry::class, $entry);
        self::assertSame($blob, $entry->getBlob());
        self::assertSame(1, $entry->getVersion());
        self::assertTrue($uuid->equals($entry->getUuid()));
    }

    public function testReplacingABlobBumpsTheVersion(): void
    {
        $entry = new VaultEntry(Uuid::v4(), UserFactory::create($this->entityManager), 'a');
        $entry->replaceBlob('b');

        self::assertSame(2, $entry->getVersion());
        self::assertSame('b', $entry->getBlob());
    }

    public function testDeletingAUserDeletesItsDataAndKeepsTheJournal(): void
    {
        $user = UserFactory::create($this->entityManager);
        $this->entityManager->persist(new VaultEntry(Uuid::v4(), $user, 'blob'));
        $this->entityManager->persist(new BackupCode($user, str_repeat('a', 64)));
        $this->entityManager->persist(new TrustedDevice($user, str_repeat('b', 64)));
        $this->entityManager->persist(new AuditLog($user, 'login_success', '', '127.0.0.1', 'test'));
        $this->entityManager->flush();
        $userId = $user->getId();

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM app_user WHERE id = ?', [$userId]);

        foreach (['vault_entry', 'backup_code', 'trusted_device'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE user_id = ?', $table), [$userId]));
        }
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE user_id IS NULL AND action = ?', ['login_success']));
    }

    public function testEmailIsUnique(): void
    {
        UserFactory::create($this->entityManager, 'same@example.com');

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        UserFactory::create($this->entityManager, 'same@example.com');
    }
}

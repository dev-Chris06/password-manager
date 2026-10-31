<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\AuditLog;
use App\Entity\TrustedDevice;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeCommandTest extends KernelTestCase
{
    public function testExpiredDataIsDeletedAndRecentDataKept(): void
    {
        $kernel = self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = $entityManager->getConnection();
        $user = UserFactory::create($entityManager);

        $entityManager->persist(new AuditLog($user, 'old', '', '127.0.0.1', 'test'));
        $entityManager->persist(new AuditLog($user, 'recent', '', '127.0.0.1', 'test'));
        $entityManager->persist(new TrustedDevice($user, str_repeat('o', 64)));
        $entityManager->persist(new TrustedDevice($user, str_repeat('r', 64)));
        $entityManager->flush();

        $connection->executeStatement("UPDATE audit_log SET created_at = DATE_SUB(NOW(), INTERVAL 91 DAY) WHERE action = 'old'");
        $connection->executeStatement('UPDATE trusted_device SET last_used_at = DATE_SUB(NOW(), INTERVAL 181 DAY) WHERE nonce_hash = ?', [str_repeat('o', 64)]);
        $connection->executeStatement('INSERT INTO sessions (sess_id, sess_data, sess_lifetime, sess_time) VALUES (?, ?, ?, ?), (?, ?, ?, ?)', [
            'expired', '', time() - 10, time() - 1000,
            'alive', '', time() + 900, time(),
        ]);

        $tester = new CommandTester((new Application($kernel))->find('app:purge'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(['recent'], $connection->fetchFirstColumn('SELECT action FROM audit_log'));
        self::assertSame([str_repeat('r', 64)], $connection->fetchFirstColumn('SELECT nonce_hash FROM trusted_device'));
        self::assertSame(['alive'], $connection->fetchFirstColumn('SELECT sess_id FROM sessions'));
    }
}

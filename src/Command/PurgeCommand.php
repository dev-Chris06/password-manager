<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AuditLog;
use App\Entity\TrustedDevice;
use App\Session\DatabaseSessionHandlerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes data past its retention period. Meant to run daily from cron.
 */
#[AsCommand(name: 'app:purge', description: 'Delete expired sessions, old journal entries and inactive trusted devices')]
final class PurgeCommand extends Command
{
    public const int AUDIT_LOG_RETENTION_DAYS = 90;
    public const int TRUSTED_DEVICE_RETENTION_DAYS = 180;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable();

        $auditLogs = $this->entityManager->createQueryBuilder()
            ->delete(AuditLog::class, 'a')
            ->where('a.createdAt < :limit')
            ->setParameter('limit', $now->modify(\sprintf('-%d days', self::AUDIT_LOG_RETENTION_DAYS)))
            ->getQuery()
            ->execute();

        $devices = $this->entityManager->createQueryBuilder()
            ->delete(TrustedDevice::class, 'd')
            ->where('d.lastUsedAt < :limit')
            ->setParameter('limit', $now->modify(\sprintf('-%d days', self::TRUSTED_DEVICE_RETENTION_DAYS)))
            ->getQuery()
            ->execute();

        // sess_lifetime holds the expiry timestamp of each session.
        $sessions = $this->entityManager->getConnection()->executeStatement(
            \sprintf('DELETE FROM %s WHERE sess_lifetime < ?', DatabaseSessionHandlerFactory::TABLE),
            [$now->getTimestamp()],
        );

        $io->success(\sprintf(
            'Purged %d journal entries, %d trusted devices and %d sessions.',
            $auditLogs,
            $devices,
            $sessions,
        ));

        return Command::SUCCESS;
    }
}

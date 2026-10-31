<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BackupCode;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Last resort for the owner of the server who lost both the authenticator app and the backup
 * codes. It does not weaken the encryption: the vault still needs the master password and the
 * Secret Key. Two-factor authentication must be enrolled again at the next login.
 */
#[AsCommand(name: 'app:2fa:reset', description: 'Reset the two-factor authentication of an account (server owner only)')]
final class ResetTwoFactorCommand extends Command
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email of the account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('cli' !== \PHP_SAPI) {
            $io->error('This command can only run from the command line.');

            return Command::FAILURE;
        }

        $email = mb_strtolower(trim($input->getArgument('email')));
        $user = $this->users->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error('No account with this email.');

            return Command::FAILURE;
        }

        $user->disableTotp();
        $this->entityManager->createQueryBuilder()
            ->delete(BackupCode::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
        $this->entityManager->flush();
        $this->auditLogger->log($user, '2fa_reset_cli');

        $io->success('Two-factor authentication reset: it will be enrolled again at the next login.');

        return Command::SUCCESS;
    }
}

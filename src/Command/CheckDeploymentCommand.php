<?php

declare(strict_types=1);

namespace App\Command;

use App\Platform\PlatformRequirements;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Pre-flight check run after every deployment (docs/deploy/). Any error stops the deployment.
 */
#[AsCommand(name: 'app:check-deployment', description: 'Verify that this installation is safe to serve in production')]
final class CheckDeploymentCommand extends Command
{
    /** Secrets that ship with the repository for development and tests only. */
    private const array KNOWN_SECRETS = [
        '88fa3c281a9aedfe5d9a5ec6fa1891cc',
        '$ecretf0rt3st',
        'e2e-only-secret-not-for-production-use-0000000000',
    ];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] private readonly DependencyFactory $migrations,
        #[Autowire('%kernel.environment%')] private readonly string $environment,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(default::DEFAULT_URI)%')] private readonly ?string $defaultUri,
        #[Autowire('%env(default::EXTENSION_ORIGIN)%')] private readonly ?string $extensionOrigin,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $errors = PlatformRequirements::problems();
        $warnings = [];

        if ('prod' !== $this->environment || $this->debug) {
            $errors[] = 'APP_ENV must be "prod" and APP_DEBUG 0.';
        }
        if (\strlen($this->secret) < 32 || \in_array($this->secret, self::KNOWN_SECRETS, true)) {
            $errors[] = 'APP_SECRET must be a unique random value of at least 32 characters (php -r \'echo bin2hex(random_bytes(32));\').';
        }
        if (null === $this->defaultUri || !str_starts_with($this->defaultUri, 'https://')) {
            $errors[] = 'DEFAULT_URI must be the public HTTPS address of the vault.';
        }
        if (null !== $this->extensionOrigin && '' !== $this->extensionOrigin && 1 !== preg_match('#^chrome-extension://[a-p]{32}$#', $this->extensionOrigin)) {
            $errors[] = 'EXTENSION_ORIGIN must look like chrome-extension://<32 letters a-p>, or be empty.';
        }
        if (filter_var(\ini_get('expose_php'), \FILTER_VALIDATE_BOOLEAN)) {
            $warnings[] = 'expose_php should be Off.';
        }
        if (!is_writable($this->projectDir.'/var')) {
            $errors[] = 'The var/ directory must be writable by PHP.';
        }

        try {
            $this->connection->executeQuery('SELECT 1');
            $status = $this->migrations->getMigrationStatusCalculator();
            if (\count($status->getNewMigrations()) > 0) {
                $errors[] = 'Database migrations are pending: run bin/console doctrine:migrations:migrate.';
            }
        } catch (\Throwable) {
            $errors[] = 'The database cannot be reached with DATABASE_URL.';
        }

        foreach ($warnings as $warning) {
            $io->warning($warning);
        }
        if ([] !== $errors) {
            $io->error(['Deployment check failed:', ...$errors]);
            $io->note('PHP settings are those of the CLI: run this command with the php.ini of PHP-FPM (php -c …) to check the web server.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('Ready for production (PHP %s).', \PHP_VERSION));

        return Command::SUCCESS;
    }
}

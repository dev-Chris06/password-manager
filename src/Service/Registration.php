<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Exception\RegistrationClosedException;
use App\Model\KdfParameters;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates the single account of the instance (ADR-011).
 *
 * A lock serializes concurrent registrations so that two simultaneous requests cannot both
 * see an empty user table.
 */
final readonly class Registration
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserRepository $users,
        private UserPasswordHasherInterface $passwordHasher,
        private LockFactory $lockFactory,
        private AuditLogger $auditLogger,
    ) {
    }

    public function isOpen(): bool
    {
        return 0 === $this->users->count([]);
    }

    public function register(string $email, KdfParameters $kdf, string $kdfSalt, string $authProof, string $wrappedVaultKey): User
    {
        $lock = $this->lockFactory->createLock('registration', 30);
        $lock->acquire(true);

        try {
            if (!$this->isOpen()) {
                throw new RegistrationClosedException();
            }

            $user = new User($email, $kdf, $kdfSalt, '', $wrappedVaultKey);
            $user->upgradeAuthHash($this->passwordHasher->hashPassword($user, $authProof));
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } finally {
            $lock->release();
        }

        $this->auditLogger->log($user, 'register');

        return $user;
    }
}

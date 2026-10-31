<?php

declare(strict_types=1);

namespace App\TwoFactor;

use App\Entity\BackupCode;
use App\Entity\User;
use App\Repository\BackupCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ten single-use recovery codes of 60 random bits ("XXXX-XXXX-XXXX", Crockford base32).
 * Only an HMAC of each code is stored: high-entropy codes need no slow hash.
 */
final readonly class BackupCodeService
{
    public const int COUNT = 10;
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private BackupCodeRepository $repository,
        #[Autowire('%kernel.secret%')] private string $secret,
    ) {
    }

    /**
     * Replaces every code of the account. The plain codes are returned once, never stored.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $this->entityManager->createQueryBuilder()
            ->delete(BackupCode::class, 'c')
            ->where('c.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();

        $codes = [];
        for ($i = 0; $i < self::COUNT; ++$i) {
            $code = self::randomCode();
            $codes[] = $code;
            $this->entityManager->persist(new BackupCode($user, $this->hmac($code)));
        }
        $this->entityManager->flush();

        return $codes;
    }

    public function consume(User $user, string $code): bool
    {
        $normalized = self::normalize($code);
        if (12 !== \strlen($normalized)) {
            return false;
        }

        $backupCode = $this->repository->findOneBy(['user' => $user, 'codeHmac' => $this->hmac($normalized), 'usedAt' => null]);
        if (null === $backupCode) {
            return false;
        }

        $backupCode->markUsed();
        $this->entityManager->flush();

        return true;
    }

    public function remaining(User $user): int
    {
        return $this->repository->count(['user' => $user, 'usedAt' => null]);
    }

    private function hmac(string $code): string
    {
        return hash_hmac('sha256', 'pm-backup-code|'.self::normalize($code), $this->secret);
    }

    private static function normalize(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $code) ?? '');

        return strtr($code, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    private static function randomCode(): string
    {
        $chars = '';
        for ($i = 0; $i < 12; ++$i) {
            $chars .= self::ALPHABET[random_int(0, 31)];
        }

        return implode('-', str_split($chars, 4));
    }
}

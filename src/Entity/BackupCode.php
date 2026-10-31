<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\BackupCodeRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Single-use two-factor recovery code, stored as an HMAC (docs/PLAN.md §4.6).
 */
#[ORM\Entity(repositoryClass: BackupCodeRepository::class)]
#[ORM\Table(name: 'backup_code')]
#[ORM\UniqueConstraint(name: 'uniq_backup_code_user_hmac', columns: ['user_id', 'code_hmac'])]
class BackupCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $codeHmac;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    public function __construct(User $user, string $codeHmac)
    {
        $this->user = $user;
        $this->codeHmac = $codeHmac;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCodeHmac(): string
    {
        return $this->codeHmac;
    }

    public function isUsed(): bool
    {
        return null !== $this->usedAt;
    }

    public function markUsed(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TrustedDeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Device that already logged in successfully (OWASP "device cookie").
 *
 * Only a hash of the random nonce held in the cookie is stored. A trusted device is exempt from
 * the per-email and per-IP login limiters, never from two-factor authentication.
 */
#[ORM\Entity(repositoryClass: TrustedDeviceRepository::class)]
#[ORM\Table(name: 'trusted_device')]
#[ORM\UniqueConstraint(name: 'uniq_trusted_device_nonce', columns: ['nonce_hash'])]
class TrustedDevice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $nonceHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    public function __construct(User $user, string $nonceHash)
    {
        $this->user = $user;
        $this->nonceHash = $nonceHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getNonceHash(): string
    {
        return $this->nonceHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}

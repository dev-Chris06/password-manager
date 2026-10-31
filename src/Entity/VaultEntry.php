<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\VaultEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One encrypted vault entry.
 *
 * The blob is opaque to the server: site, username, password, category and notes are all
 * encrypted together on the client, with the UUID as associated data (docs/PLAN.md §4.3).
 */
#[ORM\Entity(repositoryClass: VaultEntryRepository::class)]
#[ORM\Table(name: 'vault_entry')]
#[ORM\Index(name: 'idx_vault_entry_user', columns: ['user_id'])]
#[ORM\HasLifecycleCallbacks]
class VaultEntry
{
    /** Upper bound of a blob, in characters of base64 (≈ 64 KiB of ciphertext). */
    public const int MAX_BLOB_LENGTH = 87_400;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'ciphertext', type: Types::TEXT, length: 16_777_215)]
    private string $blob;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $version = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Uuid $uuid, User $user, string $blob)
    {
        $this->uuid = $uuid;
        $this->user = $user;
        $this->blob = $blob;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBlob(): string
    {
        return $this->blob;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Replaces the blob and bumps the version used for optimistic conflict detection.
     */
    public function replaceBlob(string $blob): void
    {
        $this->blob = $blob;
        ++$this->version;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}

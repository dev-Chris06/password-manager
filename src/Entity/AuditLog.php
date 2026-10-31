<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Security journal. Holds actions and identifiers only, never site names nor secrets:
 * the server does not know them.
 */
#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'idx_audit_log_created_at', columns: ['created_at'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private int|string|null $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 255)]
    private string $detail;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 255)]
    private string $userAgent;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(?User $user, string $action, string $detail, string $ip, string $userAgent)
    {
        $this->user = $user;
        $this->action = mb_substr($action, 0, 64);
        $this->detail = mb_substr($detail, 0, 255);
        $this->ip = mb_substr($ip, 0, 45);
        $this->userAgent = mb_substr($userAgent, 0, 255);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int|string|null
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getDetail(): string
    {
        return $this->detail;
    }

    public function getIp(): string
    {
        return $this->ip;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

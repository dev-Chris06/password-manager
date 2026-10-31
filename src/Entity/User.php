<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\KdfParameters;
use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Account of the vault owner.
 *
 * The server never sees the master password nor the Secret Key: it stores the KDF parameters
 * sent back by the prelogin step, a hash of the derived authentication proof, and the vault key
 * wrapped (encrypted) on the client. See docs/PLAN.md §4.2.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_app_user_email', columns: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 16)]
    private string $kdfAlgorithm;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $kdfMemoryKib;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $kdfIterations;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $kdfParallelism;

    /** Base64 of the 16-byte KDF salt. */
    #[ORM\Column(length: 24)]
    private string $kdfSalt;

    /** Hash of the authentication proof derived on the client (never the master password). */
    #[ORM\Column(length: 255)]
    private string $authHash;

    /** Vault key encrypted on the client with the wrapping key (base64). */
    #[ORM\Column(length: 255)]
    private string $wrappedVaultKey;

    /** TOTP secret encrypted at rest with a server key (base64), null until 2FA is enabled. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecretEncrypted = null;

    /** TOTP secret waiting for confirmation during enrollment (base64), encrypted at rest. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpPendingEncrypted = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $totpEnabledAt = null;

    /** Last accepted TOTP time step, to refuse replays. */
    #[ORM\Column(type: Types::BIGINT, nullable: true, options: ['unsigned' => true])]
    private int|string|null $totpLastCounter = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        string $email,
        KdfParameters $kdf,
        string $kdfSalt,
        string $authHash,
        string $wrappedVaultKey,
    ) {
        $this->email = $email;
        $this->setKdf($kdf, $kdfSalt);
        $this->authHash = $authHash;
        $this->wrappedVaultKey = $wrappedVaultKey;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getKdf(): KdfParameters
    {
        return new KdfParameters($this->kdfAlgorithm, $this->kdfMemoryKib, $this->kdfIterations, $this->kdfParallelism);
    }

    public function getKdfSalt(): string
    {
        return $this->kdfSalt;
    }

    public function getAuthHash(): string
    {
        return $this->authHash;
    }

    public function getWrappedVaultKey(): string
    {
        return $this->wrappedVaultKey;
    }

    /**
     * Replaces every credential derived from the master password at once (password change).
     */
    public function changeCredentials(KdfParameters $kdf, string $kdfSalt, string $authHash, string $wrappedVaultKey): void
    {
        $this->setKdf($kdf, $kdfSalt);
        $this->authHash = $authHash;
        $this->wrappedVaultKey = $wrappedVaultKey;
    }

    public function upgradeAuthHash(string $authHash): void
    {
        $this->authHash = $authHash;
    }

    public function getTotpSecretEncrypted(): ?string
    {
        return $this->totpSecretEncrypted;
    }

    public function getTotpPendingEncrypted(): ?string
    {
        return $this->totpPendingEncrypted;
    }

    public function setTotpPendingEncrypted(?string $totpPendingEncrypted): void
    {
        $this->totpPendingEncrypted = $totpPendingEncrypted;
    }

    public function enableTotp(string $totpSecretEncrypted): void
    {
        $this->totpSecretEncrypted = $totpSecretEncrypted;
        $this->totpPendingEncrypted = null;
        $this->totpEnabledAt = new \DateTimeImmutable();
        $this->totpLastCounter = null;
    }

    public function disableTotp(): void
    {
        $this->totpSecretEncrypted = null;
        $this->totpPendingEncrypted = null;
        $this->totpEnabledAt = null;
        $this->totpLastCounter = null;
    }

    public function isTotpEnabled(): bool
    {
        return null !== $this->totpSecretEncrypted;
    }

    public function getTotpEnabledAt(): ?\DateTimeImmutable
    {
        return $this->totpEnabledAt;
    }

    public function getTotpLastCounter(): ?int
    {
        return null === $this->totpLastCounter ? null : (int) $this->totpLastCounter;
    }

    public function setTotpLastCounter(int $counter): void
    {
        $this->totpLastCounter = $counter;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->email) {
            throw new \LogicException('A user must have an email.');
        }

        return $this->email;
    }

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getPassword(): string
    {
        return $this->authHash;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }

    private function setKdf(KdfParameters $kdf, string $kdfSalt): void
    {
        $this->kdfAlgorithm = $kdf->algorithm;
        $this->kdfMemoryKib = $kdf->memoryKib;
        $this->kdfIterations = $kdf->iterations;
        $this->kdfParallelism = $kdf->parallelism;
        $this->kdfSalt = $kdfSalt;
    }
}

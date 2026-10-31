<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\VaultEntry;
use App\Http\JsonPayload;
use App\Repository\VaultEntryRepository;
use App\Security\ApiRequestSubscriber;
use App\Service\AuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Encrypted vault entries. The server only stores opaque ciphertexts: it validates their size,
 * ownership and version, never their content (docs/PLAN.md §4.5).
 */
#[Route('/api/entries')]
final class EntryController extends AbstractController
{
    public const int MAX_ENTRIES_PER_USER = 5_000;
    public const int MAX_BATCH = 200;
    /** Maximum size of a ciphertext, in bytes once decoded (≈ 64 KiB). */
    private const int MAX_CIPHERTEXT_BYTES = 65_536;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VaultEntryRepository $entries,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: 'api_entries_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = JsonPayload::fromRequest($request, 2 * self::MAX_CIPHERTEXT_BYTES);
        $uuid = self::uuid($payload->string('uuid', 36));
        $ciphertext = $payload->base64('ciphertext', self::MAX_CIPHERTEXT_BYTES, exact: false);

        if ($this->entries->count(['user' => $user]) >= self::MAX_ENTRIES_PER_USER) {
            return ApiRequestSubscriber::error(422, 'quota_exceeded');
        }
        // A UUID belonging to anyone else is indistinguishable from one of ours.
        if (null !== $this->entries->find($uuid)) {
            return ApiRequestSubscriber::error(409, 'conflict');
        }

        $entry = new VaultEntry($uuid, $user, $ciphertext);
        $this->entityManager->persist($entry);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Two simultaneous creations with the same UUID.
            return ApiRequestSubscriber::error(409, 'conflict');
        }
        $this->auditLogger->log($user, 'entry_created', $uuid->toRfc4122());

        return new JsonResponse(self::summary($entry), 201);
    }

    /**
     * Adds several entries at once (import of a backup), in one transaction.
     */
    #[Route('/batch', name: 'api_entries_batch', methods: ['POST'])]
    public function batch(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $payload = JsonPayload::fromRequest($request, self::MAX_BATCH * 2 * self::MAX_CIPHERTEXT_BYTES);
        $items = [];
        foreach ($payload->objects('entries', self::MAX_BATCH) as $item) {
            $items[] = [self::uuid($item->string('uuid', 36)), $item->base64('ciphertext', self::MAX_CIPHERTEXT_BYTES, exact: false)];
        }

        $uuids = array_map(static fn (array $item): string => $item[0]->toRfc4122(), $items);
        if (\count(array_unique($uuids)) !== \count($uuids)) {
            return ApiRequestSubscriber::error(409, 'conflict');
        }
        if ($this->entries->count(['user' => $user]) + \count($items) > self::MAX_ENTRIES_PER_USER) {
            return ApiRequestSubscriber::error(422, 'quota_exceeded');
        }
        foreach ($items as [$uuid]) {
            if (null !== $this->entries->find($uuid)) {
                return ApiRequestSubscriber::error(409, 'conflict');
            }
        }

        $created = [];
        try {
            $this->entityManager->wrapInTransaction(function () use ($items, $user, &$created): void {
                foreach ($items as [$uuid, $ciphertext]) {
                    $entry = new VaultEntry($uuid, $user, $ciphertext);
                    $this->entityManager->persist($entry);
                    $created[] = $entry;
                }
            });
        } catch (UniqueConstraintViolationException) {
            return ApiRequestSubscriber::error(409, 'conflict');
        }
        $this->auditLogger->log($user, 'entries_imported', \sprintf('%d entries', \count($created)));

        return new JsonResponse(['entries' => array_map(self::summary(...), $created)], 201);
    }

    #[Route('/{uuid}', name: 'api_entries_update', requirements: ['uuid' => Requirement::UUID_V4], methods: ['PUT'])]
    public function update(Request $request, #[CurrentUser] User $user, string $uuid): JsonResponse
    {
        $entry = $this->ownedEntry($user, $uuid);
        $payload = JsonPayload::fromRequest($request, 2 * self::MAX_CIPHERTEXT_BYTES);
        $ciphertext = $payload->base64('ciphertext', self::MAX_CIPHERTEXT_BYTES, exact: false);

        // Optimistic locking: the web app and the extension cannot silently overwrite each other.
        if ($payload->int('version') !== $entry->getVersion()) {
            return ApiRequestSubscriber::error(409, 'version_conflict');
        }

        $entry->replaceBlob($ciphertext);
        $this->entityManager->flush();
        $this->auditLogger->log($user, 'entry_updated', $entry->getUuid()->toRfc4122());

        return new JsonResponse(self::summary($entry));
    }

    #[Route('/{uuid}', name: 'api_entries_delete', requirements: ['uuid' => Requirement::UUID_V4], methods: ['DELETE'])]
    public function delete(#[CurrentUser] User $user, string $uuid): JsonResponse
    {
        $entry = $this->ownedEntry($user, $uuid);
        $this->entityManager->remove($entry);
        $this->entityManager->flush();
        $this->auditLogger->log($user, 'entry_deleted', $uuid);

        return new JsonResponse(['deleted' => true]);
    }

    /**
     * @return array{uuid: string, version: int, updatedAt: string}
     */
    public static function summary(VaultEntry $entry): array
    {
        return [
            'uuid' => $entry->getUuid()->toRfc4122(),
            'version' => $entry->getVersion(),
            'updatedAt' => $entry->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    public static function uuid(string $value): Uuid
    {
        if (1 !== preg_match('/^'.Requirement::UUID_V4.'$/', $value)) {
            throw new UnprocessableEntityHttpException('Invalid field "uuid".');
        }

        return Uuid::fromString($value);
    }

    private function ownedEntry(User $user, string $uuid): VaultEntry
    {
        $entry = $this->entries->findOneBy(['uuid' => Uuid::fromString($uuid), 'user' => $user]);
        if (null === $entry) {
            // Same answer for an unknown entry and for someone else's entry.
            throw new NotFoundHttpException();
        }

        return $entry;
    }
}

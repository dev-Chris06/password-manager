<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\VaultEntryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class VaultController extends AbstractController
{
    /**
     * Everything the client needs to unlock and display the vault, all encrypted.
     */
    #[Route('/api/vault', name: 'api_vault', methods: ['GET'])]
    public function __invoke(#[CurrentUser] User $user, VaultEntryRepository $entries): JsonResponse
    {
        $items = [];
        foreach ($entries->findBy(['user' => $user], ['updatedAt' => 'DESC']) as $entry) {
            $items[] = [
                'uuid' => $entry->getUuid()->toRfc4122(),
                'ciphertext' => $entry->getBlob(),
                'version' => $entry->getVersion(),
                'updatedAt' => $entry->getUpdatedAt()->format(\DATE_ATOM),
            ];
        }

        return new JsonResponse([
            'email' => $user->getEmail(),
            'kdf' => $user->getKdf()->toArray(),
            'salt' => $user->getKdfSalt(),
            'wrappedVaultKey' => $user->getWrappedVaultKey(),
            'entries' => $items,
        ]);
    }
}

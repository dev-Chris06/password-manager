<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\JsonPayload;
use App\Model\KdfParameters;
use App\Repository\VaultEntryRepository;
use App\Security\ProofAuthenticator;
use App\Security\ReauthenticationVerifier;
use App\Security\SecondFactorCompletedBadge;
use App\Security\TrustedDeviceManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AccountController extends AbstractController
{
    /**
     * Replaces every credential derived from the master password (docs/PLAN.md phase 6):
     * new KDF salt, new proof, vault key wrapped with the new wrapping key. When the vault key
     * itself is renewed, every entry re-encrypted by the client comes in the same request and
     * everything is replaced in one transaction.
     *
     * Other sessions are closed (the stored proof hash changes) and trusted devices revoked.
     */
    #[Route('/api/account/password', name: 'api_account_password', methods: ['POST'])]
    public function changePassword(
        Request $request,
        #[CurrentUser] User $user,
        ReauthenticationVerifier $reauthentication,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        VaultEntryRepository $entries,
        TrustedDeviceManager $devices,
        AuditLogger $auditLogger,
        Security $security,
    ): JsonResponse {
        // A key rotation carries the whole vault; the real ceiling is PHP's post_max_size.
        $payload = JsonPayload::fromRequest($request, 32 * 1024 * 1024);
        $reauthentication->verify($user, $payload->base64('currentAuthProof', 32));

        $kdfPayload = $payload->object('kdf');
        $kdf = new KdfParameters($kdfPayload->string('algorithm', 16), $kdfPayload->int('memoryKib'), $kdfPayload->int('iterations'), $kdfPayload->int('parallelism'));
        if (!$kdf->isAcceptable()) {
            throw new UnprocessableEntityHttpException('Invalid field "kdf".');
        }
        $salt = $payload->base64('salt', 16);
        $authProof = $payload->base64('authProof', 32);
        $wrappedVaultKey = $payload->base64('wrappedVaultKey', AuthController::WRAPPED_VAULT_KEY_BYTES);

        // Optional key rotation: the complete set of entries, re-encrypted with the new vault key.
        $rotated = null;
        if ($payload->has('entries')) {
            $rotated = [];
            foreach ($payload->objects('entries', EntryController::MAX_ENTRIES_PER_USER) as $item) {
                $rotated[EntryController::uuid($item->string('uuid', 36))->toRfc4122()] = $item->base64('ciphertext', 65_536, exact: false);
            }
        }

        $entityManager->wrapInTransaction(static function () use ($user, $kdf, $salt, $authProof, $wrappedVaultKey, $rotated, $entries, $passwordHasher): void {
            if (null !== $rotated) {
                $current = $entries->findBy(['user' => $user]);
                if (\count($current) !== \count($rotated)) {
                    throw new UnprocessableEntityHttpException('Every entry must be re-encrypted.');
                }
                foreach ($current as $entry) {
                    $ciphertext = $rotated[$entry->getUuid()->toRfc4122()] ?? null;
                    if (null === $ciphertext) {
                        throw new UnprocessableEntityHttpException('Every entry must be re-encrypted.');
                    }
                    $entry->replaceBlob($ciphertext);
                }
            }
            $user->changeCredentials($kdf, $salt, '', $wrappedVaultKey);
            $user->upgradeAuthHash($passwordHasher->hashPassword($user, $authProof));
        });

        $devices->revokeAll($user);
        $auditLogger->log($user, 'master_password_changed');
        if (null !== $rotated) {
            $auditLogger->log($user, 'vault_rekeyed', \sprintf('%d entries', \count($rotated)));
        }

        // The current session is renewed with the new credentials (and gets a new device cookie).
        $response = $security->login($user, ProofAuthenticator::class, 'main', [new SecondFactorCompletedBadge()]);

        return $response instanceof JsonResponse ? $response : new JsonResponse(['step' => 'done']);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\JsonPayload;
use App\Security\ApiRequestSubscriber;
use App\Security\ProofAuthenticator;
use App\Security\ReauthenticationVerifier;
use App\Security\SecondFactorCompletedBadge;
use App\Service\AuditLogger;
use App\TwoFactor\BackupCodeService;
use App\TwoFactor\TotpService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Second factor: verification after the proof, mandatory enrollment, backup codes
 * (docs/PLAN.md §4.4 and phase 4).
 */
final class TwoFactorController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/api/auth/2fa/check', name: 'api_auth_2fa_check', methods: ['POST'])]
    public function check(
        Request $request,
        #[CurrentUser] User $user,
        TotpService $totp,
        BackupCodeService $backupCodes,
        RateLimiterFactoryInterface $secondFactorLimiter,
        TokenStorageInterface $tokenStorage,
    ): JsonResponse {
        $limiter = $secondFactorLimiter->create((string) $user->getId());
        if (0 === $limiter->consume(0)->getRemainingTokens()) {
            return $this->abandonLogin($request, $tokenStorage, $limiter->consume()->getRetryAfter());
        }

        $payload = JsonPayload::fromRequest($request, 4096);
        $code = $payload->optionalString('code', 16);
        $backupCode = $payload->optionalString('backupCode', 32);

        $verified = match (true) {
            null !== $code => $totp->verify($user, $code),
            null !== $backupCode => $backupCodes->consume($user, $backupCode),
            default => false,
        };

        if (!$verified) {
            $this->auditLogger->log($user, '2fa_failure');
            $limit = $limiter->consume();
            if (0 === $limit->getRemainingTokens()) {
                // Too many wrong codes: the half-open login is dropped, the proof must be sent again.
                return $this->abandonLogin($request, $tokenStorage, $limiter->consume()->getRetryAfter());
            }

            return ApiRequestSubscriber::error(401, 'invalid_code');
        }

        if (null !== $backupCode) {
            $this->auditLogger->log($user, 'backup_code_used', \sprintf('%d remaining', $backupCodes->remaining($user)));
        }
        $limiter->reset();

        return $this->completeLogin($user);
    }

    /**
     * Starts the enrollment of an authenticator app. Requires a fresh authentication proof.
     */
    #[Route('/api/2fa/setup', name: 'api_2fa_setup', methods: ['POST'])]
    public function setup(Request $request, #[CurrentUser] User $user, ReauthenticationVerifier $reauthentication, TotpService $totp): JsonResponse
    {
        $reauthentication->verify($user, JsonPayload::fromRequest($request, 4096)->base64('authProof', 32));

        return new JsonResponse($totp->startEnrollment($user));
    }

    /**
     * Confirms the enrollment with a first code and returns the backup codes (shown only once).
     */
    #[Route('/api/2fa/enable', name: 'api_2fa_enable', methods: ['POST'])]
    public function enable(Request $request, #[CurrentUser] User $user, TotpService $totp, BackupCodeService $backupCodes): JsonResponse
    {
        $code = JsonPayload::fromRequest($request, 4096)->string('code', 16);
        if (!$totp->confirmEnrollment($user, $code)) {
            return ApiRequestSubscriber::error(422, 'invalid_code');
        }

        $codes = $backupCodes->regenerate($user);
        $this->auditLogger->log($user, '2fa_enabled');

        $response = $this->isGranted('ROLE_USER') ? new JsonResponse(['step' => 'done']) : $this->completeLogin($user);
        $data = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $response->setData((\is_array($data) ? $data : []) + ['backupCodes' => $codes]);
    }

    /**
     * Replaces the backup codes. Requires a fresh proof and a current TOTP code.
     */
    #[Route('/api/2fa/backup-codes', name: 'api_2fa_backup_codes', methods: ['POST'])]
    public function regenerateBackupCodes(
        Request $request,
        #[CurrentUser] User $user,
        ReauthenticationVerifier $reauthentication,
        TotpService $totp,
        BackupCodeService $backupCodes,
    ): JsonResponse {
        $payload = JsonPayload::fromRequest($request, 4096);
        $reauthentication->verify($user, $payload->base64('authProof', 32));
        if (!$totp->verify($user, $payload->string('code', 16))) {
            return ApiRequestSubscriber::error(422, 'invalid_code');
        }

        $codes = $backupCodes->regenerate($user);
        $this->auditLogger->log($user, 'backup_codes_regenerated');

        return new JsonResponse(['backupCodes' => $codes]);
    }

    private function completeLogin(User $user): JsonResponse
    {
        $response = $this->security->login($user, ProofAuthenticator::class, 'main', [new SecondFactorCompletedBadge()]);

        return $response instanceof JsonResponse ? $response : new JsonResponse(['step' => 'done']);
    }

    private function abandonLogin(Request $request, TokenStorageInterface $tokenStorage, \DateTimeImmutable $retryAfter): JsonResponse
    {
        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        $response = ApiRequestSubscriber::error(429, 'too_many_requests');
        $response->headers->set('Retry-After', (string) max(1, $retryAfter->getTimestamp() - time()));

        return $response;
    }
}

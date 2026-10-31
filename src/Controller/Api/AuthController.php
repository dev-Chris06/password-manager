<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Http\JsonPayload;
use App\Model\KdfParameters;
use App\Repository\UserRepository;
use App\Security\ApiRequestSubscriber;
use App\Service\AuditLogger;
use App\Service\Registration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/api/auth')]
final class AuthController extends AbstractController
{
    /** Size of a wrapped vault key: version (1) + IV (12) + key (32) + tag (16). */
    public const int WRAPPED_VAULT_KEY_BYTES = 61;

    /**
     * Returns the KDF parameters of an account. For an unknown email the answer is derived from
     * a server secret, so it is stable and indistinguishable from a real account.
     */
    #[Route('/prelogin', name: 'api_auth_prelogin', methods: ['POST'])]
    public function prelogin(
        Request $request,
        UserRepository $users,
        RateLimiterFactoryInterface $preloginLimiter,
        #[Autowire('%kernel.secret%')] string $secret,
    ): JsonResponse {
        self::throttle($preloginLimiter, (string) $request->getClientIp());
        $email = JsonPayload::fromRequest($request, 4096)->email();

        $user = $users->findOneBy(['email' => $email]);
        $kdf = $user?->getKdf() ?? KdfParameters::defaults();
        $salt = $user?->getKdfSalt() ?? base64_encode(substr(hash_hmac('sha256', 'prelogin|'.$email, $secret, true), 0, 16));

        return new JsonResponse(['kdf' => $kdf->toArray(), 'salt' => $salt]);
    }

    #[Route('/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request, Registration $registration, RateLimiterFactoryInterface $registrationLimiter): JsonResponse
    {
        self::throttle($registrationLimiter, (string) $request->getClientIp());

        $payload = JsonPayload::fromRequest($request, 8192);
        $kdfPayload = $payload->object('kdf');
        $kdf = new KdfParameters(
            $kdfPayload->string('algorithm', 16),
            $kdfPayload->int('memoryKib'),
            $kdfPayload->int('iterations'),
            $kdfPayload->int('parallelism'),
        );
        if (!$kdf->isAcceptable()) {
            throw new UnprocessableEntityHttpException('Invalid field "kdf".');
        }

        $registration->register(
            $payload->email(),
            $kdf,
            $payload->base64('salt', 16),
            $payload->base64('authProof', 32),
            $payload->base64('wrappedVaultKey', self::WRAPPED_VAULT_KEY_BYTES),
            $payload->optionalString('registrationCode', 128),
        );

        return new JsonResponse(['registered' => true], 201);
    }

    /**
     * Handled by App\Security\ProofAuthenticator; this method is never reached.
     */
    #[Route('/login', name: 'api_auth_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('The login is handled by the authenticator.');
    }

    #[Route('/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(Request $request, TokenStorageInterface $tokenStorage, AuditLogger $auditLogger, CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            $auditLogger->log($user, 'logout');
        }

        $tokenStorage->setToken(null);
        $request->getSession()->invalidate();

        return new JsonResponse(['csrfToken' => $csrfTokenManager->refreshToken(ApiRequestSubscriber::CSRF_TOKEN_ID)->getValue()]);
    }

    private static function throttle(RateLimiterFactoryInterface $factory, string $key): void
    {
        $limit = $factory->create($key)->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()));
        }
    }
}

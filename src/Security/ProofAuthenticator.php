<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\TrustedDevice;
use App\Entity\User;
use App\Http\JsonPayload;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

/**
 * Logs in with the authentication proof derived on the client (docs/PLAN.md §4.4).
 *
 * The proof is checked like a password (hashed with the password hasher); the master password,
 * the Secret Key and the wrapping key never reach the server.
 */
final class ProofAuthenticator extends AbstractAuthenticator
{
    public const string SESSION_AUTHENTICATED_AT = '_pm_authenticated_at';
    public const string SESSION_FIRST_FACTOR_AT = '_pm_first_factor_at';
    /** First factor done, waiting for the TOTP or backup code. */
    public const string ROLE_PENDING = 'ROLE_2FA_PENDING';
    /** First factor done, two-factor authentication must be enrolled before anything else. */
    public const string ROLE_SETUP = 'ROLE_2FA_SETUP';
    private const string ATTRIBUTE_EMAIL = '_pm_login_email';
    private const string ATTRIBUTE_DEVICE = '_pm_login_device';

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hasherFactory,
        private readonly LoginThrottler $throttler,
        private readonly TrustedDeviceManager $devices,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'api_auth_login' === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        try {
            $payload = JsonPayload::fromRequest($request, 4096);
            $email = $payload->email();
            $proof = $payload->base64('authProof', 32);
        } catch (HttpExceptionInterface) {
            throw new BadCredentialsException('Malformed login request.');
        }

        $ip = (string) $request->getClientIp();
        $device = $this->devices->findValid($request, $email);
        $deviceKey = null === $device ? null : TrustedDeviceManager::limiterKey($device);
        $request->attributes->set(self::ATTRIBUTE_EMAIL, $email);
        $request->attributes->set(self::ATTRIBUTE_DEVICE, $device);

        $blockedUntil = $this->throttler->blockedUntil($email, $ip, $deviceKey);
        if (null !== $blockedUntil) {
            throw new TooManyAttemptsException($blockedUntil);
        }

        return new Passport(
            new UserBadge($email, function (string $identifier) use ($proof): ?User {
                $user = $this->users->findOneBy(['email' => $identifier]);
                if (null === $user) {
                    // Same cost as a real check, so response times do not reveal unknown emails.
                    $this->hasherFactory->getPasswordHasher(User::class)->hash($proof);
                }

                return $user;
            }),
            new PasswordCredentials($proof),
            [new PasswordUpgradeBadge($proof)],
        );
    }

    /**
     * Only a login whose second factor was verified gets the user roles; after the proof alone,
     * the token can only complete (or enroll) the second factor (docs/PLAN.md §4.4).
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $user = $passport->getUser();
        if ($passport->hasBadge(SecondFactorCompletedBadge::class)) {
            $roles = $user->getRoles();
        } else {
            $roles = $user instanceof User && $user->isTotpEnabled() ? [self::ROLE_PENDING] : [self::ROLE_SETUP];
        }

        return new PostAuthenticationToken($user, $firewallName, $roles);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Unexpected user class.');
        }
        $session = $request->getSession();

        // The CSRF tokens are cleared when the session is migrated after this method:
        // the client fetches a new one from /api/session.
        if (\in_array(self::ROLE_PENDING, $token->getRoleNames(), true) || \in_array(self::ROLE_SETUP, $token->getRoleNames(), true)) {
            $device = $request->attributes->get(self::ATTRIBUTE_DEVICE);
            $this->throttler->recordSuccess($user->getEmail(), (string) $request->getClientIp(), $device instanceof TrustedDevice ? TrustedDeviceManager::limiterKey($device) : null);
            $session->set(self::SESSION_FIRST_FACTOR_AT, time());
            $this->auditLogger->log($user, 'login_first_factor');

            return new JsonResponse(['step' => $user->isTotpEnabled() ? '2fa' : '2fa-setup']);
        }

        $session->remove(self::SESSION_FIRST_FACTOR_AT);
        $session->set(self::SESSION_AUTHENTICATED_AT, time());
        $this->auditLogger->log($user, 'login_success');

        $response = new JsonResponse(['step' => 'done']);
        // The device cookie is only granted to a fully authenticated browser.
        $device = $this->devices->findValid($request, $user->getEmail());
        if (null === $device) {
            $this->devices->issue($user, $request, $response);
        } else {
            $this->devices->markUsed($device);
        }

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $email = $request->attributes->get(self::ATTRIBUTE_EMAIL);

        if ($exception instanceof TooManyAttemptsException) {
            $this->auditLogger->log(null, 'login_blocked', \is_string($email) ? AuditLogger::maskEmail($email) : '');
            $response = ApiRequestSubscriber::error(429, 'too_many_requests');
            $response->headers->set('Retry-After', (string) max(1, $exception->retryAfter->getTimestamp() - time()));

            return $response;
        }

        if (\is_string($email)) {
            $device = $request->attributes->get(self::ATTRIBUTE_DEVICE);
            $deviceKey = $device instanceof TrustedDevice ? TrustedDeviceManager::limiterKey($device) : null;
            $this->throttler->recordFailure($email, (string) $request->getClientIp(), $deviceKey);
            $this->auditLogger->log($this->users->findOneBy(['email' => $email]), 'login_failure', AuditLogger::maskEmail($email));
        }

        return ApiRequestSubscriber::error(401, 'invalid_credentials');
    }
}

<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ApiErrorCode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Common protections of the JSON API (docs/PLAN.md §4.5).
 *
 * - Only the application itself and the configured browser extension may call it (Origin and
 *   Sec-Fetch-Site checks); CORS headers are only ever sent to the extension origin.
 * - Every call carries the custom X-PM-Client header, which a cross-site form cannot send.
 * - Mutations require a JSON body and the session CSRF token in X-CSRF-Token.
 * - Responses are never cached and errors are uniform JSON without internal details.
 */
final class ApiRequestSubscriber implements EventSubscriberInterface
{
    public const string PATH_PREFIX = '/api/';
    public const string CLIENT_HEADER = 'X-PM-Client';
    public const string CSRF_HEADER = 'X-CSRF-Token';
    public const string CSRF_TOKEN_ID = 'api';

    private const array SAFE_METHODS = ['GET', 'HEAD'];
    private const array ALLOWED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'];

    private const array ERROR_CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        409 => 'conflict',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'invalid_payload',
        429 => 'too_many_requests',
    ];

    public function __construct(
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly string $extensionOrigin,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the session is attached (128), before routing (32).
            KernelEvents::REQUEST => ['onKernelRequest', 64],
            // After the session listener (-1000), which would otherwise rewrite Cache-Control.
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
            // After the exception is logged (priority 0), before the HTML error page (-128).
            KernelEvents::EXCEPTION => ['onKernelException', -64],
        ];
    }

    public static function isApiRequest(Request $request): bool
    {
        return str_starts_with($request->getPathInfo(), self::PATH_PREFIX);
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApiRequest($request)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if (null !== $origin && !$this->isAllowedOrigin($origin, $request)) {
            $event->setResponse(self::error(403, 'origin_not_allowed'));

            return;
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (null === $origin && null !== $fetchSite && !\in_array($fetchSite, ['same-origin', 'none'], true)) {
            $event->setResponse(self::error(403, 'origin_not_allowed'));

            return;
        }

        if ($request->isMethod('OPTIONS')) {
            $event->setResponse($this->isExtensionOrigin($origin) ? $this->preflightResponse() : self::error(403, 'origin_not_allowed'));

            return;
        }

        if ('1' !== $request->headers->get(self::CLIENT_HEADER)) {
            $event->setResponse(self::error(403, 'client_header_missing'));

            return;
        }

        if (\in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        if ('json' !== $request->getContentTypeFormat()) {
            $event->setResponse(self::error(415, 'unsupported_media_type'));

            return;
        }

        $token = $request->headers->get(self::CSRF_HEADER);
        if (null === $token || !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            $event->setResponse(self::error(403, 'invalid_csrf_token'));
        }
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !self::isApiRequest($request)) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Vary', 'Origin', false);

        $origin = $request->headers->get('Origin');
        if ($this->isExtensionOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', (string) $origin);
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Expose-Headers', 'Retry-After');
        }
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->isMainRequest() || !self::isApiRequest($event->getRequest())) {
            return;
        }

        $exception = $event->getThrowable();
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        $code = $exception instanceof ApiErrorCode
            ? $exception->getErrorCode()
            : (self::ERROR_CODES[$status] ?? ($status >= 500 ? 'internal_error' : 'error'));
        $response = self::error($status, $code);

        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        $event->setResponse($response);
    }

    public static function error(int $status, string $code): JsonResponse
    {
        return new JsonResponse(['error' => $code], $status);
    }

    private function isAllowedOrigin(string $origin, Request $request): bool
    {
        return $origin === $request->getSchemeAndHttpHost() || $this->isExtensionOrigin($origin);
    }

    private function isExtensionOrigin(?string $origin): bool
    {
        return '' !== $this->extensionOrigin && $origin === $this->extensionOrigin;
    }

    private function preflightResponse(): Response
    {
        return new Response(null, 204, [
            'Access-Control-Allow-Origin' => $this->extensionOrigin,
            'Access-Control-Allow-Credentials' => 'true',
            'Access-Control-Allow-Methods' => implode(', ', self::ALLOWED_METHODS),
            'Access-Control-Allow-Headers' => implode(', ', ['Content-Type', self::CLIENT_HEADER, self::CSRF_HEADER]),
            'Access-Control-Max-Age' => '600',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Security\ApiRequestSubscriber;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SessionController extends AbstractController
{
    /**
     * Returns the session state and the CSRF token that every mutation must send back.
     */
    #[Route('/api/session', name: 'api_session', methods: ['GET'])]
    public function __invoke(CsrfTokenManagerInterface $csrfTokenManager): JsonResponse
    {
        return new JsonResponse([
            'authenticated' => null !== $this->getUser(),
            'csrfToken' => $csrfTokenManager->getToken(ApiRequestSubscriber::CSRF_TOKEN_ID)->getValue(),
        ]);
    }
}

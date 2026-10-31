<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\AuditLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AuditLogController extends AbstractController
{
    private const int PER_PAGE = 50;

    #[Route('/api/audit-log', name: 'api_audit_log', methods: ['GET'])]
    public function __invoke(Request $request, #[CurrentUser] User $user, AuditLogRepository $logs): JsonResponse
    {
        $total = $logs->count(['user' => $user]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        $items = [];
        foreach ($logs->findBy(['user' => $user], ['createdAt' => 'DESC', 'id' => 'DESC'], self::PER_PAGE, ($page - 1) * self::PER_PAGE) as $log) {
            $items[] = [
                'action' => $log->getAction(),
                'detail' => $log->getDetail(),
                'ip' => $log->getIp(),
                'userAgent' => $log->getUserAgent(),
                'createdAt' => $log->getCreatedAt()->format(\DATE_ATOM),
            ];
        }

        return new JsonResponse(['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total]);
    }
}

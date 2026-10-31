<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Writes the security journal. Callers pass actions and identifiers only, never secrets.
 */
final class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function log(?User $user, string $action, string $detail = ''): void
    {
        $request = $this->requestStack->getMainRequest();

        $this->entityManager->persist(new AuditLog(
            $user,
            $action,
            $detail,
            $request?->getClientIp() ?? '',
            (string) $request?->headers->get('User-Agent', ''),
        ));
        $this->entityManager->flush();
    }
}

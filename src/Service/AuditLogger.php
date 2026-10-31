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

    /**
     * "alice@example.com" → "al***@example.com": enough to recognise one's own address in the journal.
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if (false === $at) {
            return '***';
        }

        return mb_substr($email, 0, min(2, $at)).'***'.substr($email, $at);
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

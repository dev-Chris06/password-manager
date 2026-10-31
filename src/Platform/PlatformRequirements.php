<?php

declare(strict_types=1);

namespace App\Platform;

/**
 * PHP security level required to host the application (ADR-017).
 *
 * Kept free of any dependency: tools/check-php-platform.php loads it before Composer runs.
 */
final class PlatformRequirements
{
    /** Minimum patched release for each supported branch (security fixes of 2026-10-21). */
    public const array MINIMUM_PATCHED = [
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /**
     * @return list<string> blocking problems, empty when the platform is acceptable
     */
    public static function problems(string $version = \PHP_VERSION): array
    {
        $problems = [];
        $parts = explode('.', $version);
        $branch = $parts[0].'.'.($parts[1] ?? '');

        if (!isset(self::MINIMUM_PATCHED[$branch])) {
            $problems[] = \sprintf('PHP %s is not a supported branch (supported: %s).', $version, implode(', ', array_keys(self::MINIMUM_PATCHED)));
        } elseif (version_compare($version, self::MINIMUM_PATCHED[$branch], '<')) {
            $problems[] = \sprintf('PHP %s lacks the 2026-10-21 security fixes: upgrade to %s or later.', $version, self::MINIMUM_PATCHED[$branch]);
        }

        if (filter_var(\ini_get('session.upload_progress.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
            $problems[] = 'session.upload_progress.enabled must be 0 (mitigation for CVE-2026-109619 and CVE-2026-115560).';
        }

        return $problems;
    }
}

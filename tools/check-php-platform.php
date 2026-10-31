<?php

declare(strict_types=1);

/*
 * Verifies that the running PHP is safe to host the application (ADR-017):
 *  - the version includes the security fixes of 2026-10-21 (CVE-2026-109618, CVE-2026-109619);
 *  - session upload progress is disabled (mitigation for CVE-2026-109619 and CVE-2026-115560).
 *
 * Usage: php tools/check-php-platform.php
 * Run it with the same php.ini as the web server (php-fpm), e.g. in CI or right after a deployment.
 * Works before `composer install`: the requirements class is loaded directly.
 */

require_once __DIR__.'/../src/Platform/PlatformRequirements.php';

$problems = App\Platform\PlatformRequirements::problems();

if ([] !== $problems) {
    fwrite(\STDERR, "PHP platform check failed:\n - ".implode("\n - ", $problems)."\n");
    exit(1);
}

echo sprintf("PHP platform check passed (PHP %s).\n", \PHP_VERSION);

<?php

declare(strict_types=1);

/*
 * Verifies that the running PHP is safe to host the application:
 *  - the version includes the security fixes of 2026-10-21 (CVE-2026-109618, CVE-2026-109619);
 *  - session upload progress is disabled (mitigation for CVE-2026-109619 and CVE-2026-115560).
 *
 * Usage: php tools/check-php-platform.php
 * Run it with the same php.ini as the web server (php-fpm), e.g. in CI or right after a deployment.
 */

// Minimum patched release for each supported minor branch.
const MINIMUM_PATCHED = [
    '8.3' => '8.3.36',
    '8.4' => '8.4.27',
    '8.5' => '8.5.12',
];

$errors = [];
$branch = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;

if (!isset(MINIMUM_PATCHED[$branch])) {
    $errors[] = sprintf('PHP %s is not a supported branch (supported: %s).', \PHP_VERSION, implode(', ', array_keys(MINIMUM_PATCHED)));
} elseif (version_compare(\PHP_VERSION, MINIMUM_PATCHED[$branch], '<')) {
    $errors[] = sprintf('PHP %s lacks the 2026-10-21 security fixes: upgrade to %s or later.', \PHP_VERSION, MINIMUM_PATCHED[$branch]);
}

if (filter_var(ini_get('session.upload_progress.enabled'), \FILTER_VALIDATE_BOOLEAN)) {
    $errors[] = 'session.upload_progress.enabled must be 0 (mitigation for CVE-2026-109619 and CVE-2026-115560).';
}

if ([] !== $errors) {
    fwrite(\STDERR, "PHP platform check failed:\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}

echo sprintf("PHP platform check passed (PHP %s).\n", \PHP_VERSION);

<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

define('BASE_PATH', dirname(__DIR__));
load_env(BASE_PATH . '/.env');

define('APP_URL', rtrim((string) env_value('APP_URL', 'http://localhost/password-manager'), '/'));
define('BCRYPT_COST', 12);
define('PBKDF2_ITERATIONS', 100000);
define('LOGIN_MAX_FAILURES', 3);
define('LOGIN_BLOCK_SECONDS', 60);
define('CRYPTO_METHOD_GCM', 'aes-256-gcm');
define('CRYPTO_METHOD_LEGACY_CBC', 'aes-256-cbc');
define('JOURNAL_RETENTION_DAYS', 90);

date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (!headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

function is_https_request(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return $https === 'on' || $https === '1' || strtolower((string) $forwardedProto) === 'https';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function redirect_to(string $path): never
{
    $url = APP_URL . '/' . ltrim($path, '/');
    
    // Validation de la redirection pour éviter les attaques de redirection ouverte
    $parsedUrl = parse_url($url);
    $parsedAppUrl = parse_url(APP_URL);
    
    // Vérifier que le schéma, l'hôte et le port correspondent à l'application
    if ($parsedUrl === false || 
        ($parsedAppUrl['scheme'] ?? '') !== ($parsedUrl['scheme'] ?? '') ||
        ($parsedAppUrl['host'] ?? '') !== ($parsedUrl['host'] ?? '') ||
        ($parsedAppUrl['port'] ?? '') !== ($parsedUrl['port'] ?? '')) {
        // Redirection invalide, rediriger vers l'accueil
        $url = APP_URL;
    }
    
    header('Location: ' . $url);
    exit;
}

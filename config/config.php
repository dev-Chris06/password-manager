<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
load_env(BASE_PATH . '/.env');

if (!defined('APP_URL')) {
    define('APP_URL', rtrim((string) env_value('APP_URL', 'http://localhost/password-manager'), '/'));
}
if (!defined('BCRYPT_COST')) {
    define('BCRYPT_COST', 12);
}
if (!defined('PBKDF2_ITERATIONS')) {
    define('PBKDF2_ITERATIONS', 600000);
}
if (!defined('LOGIN_MAX_FAILURES')) {
    define('LOGIN_MAX_FAILURES', 3);
}
if (!defined('LOGIN_BLOCK_SECONDS')) {
    define('LOGIN_BLOCK_SECONDS', 60);
}
if (!defined('CRYPTO_METHOD_GCM')) {
    define('CRYPTO_METHOD_GCM', 'aes-256-gcm');
}
if (!defined('JOURNAL_RETENTION_DAYS')) {
    define('JOURNAL_RETENTION_DAYS', 90);
}
if (!defined('SESSION_TIMEOUT_INACTIVITE_SEC')) {
    define('SESSION_TIMEOUT_INACTIVITE_SEC', 300);
}
if (!defined('SESSION_TIMEOUT_ABSOLU_SEC')) {
    define('SESSION_TIMEOUT_ABSOLU_SEC', 3600);
}
if (!defined('LOGIN_DUMMY_BCRYPT_HASH')) {
    define('LOGIN_DUMMY_BCRYPT_HASH', '$2y$12$DRjm/5F3C1g3QVtR0k1WGOxhpBpR.G0a1.3nVh19XxZz7f7q5b6Oa');
}
if (!defined('SECURITY_HEADERS')) {
    define('SECURITY_HEADERS', [
    'X-Content-Type-Options'  => 'nosniff',
    'X-Frame-Options'         => 'DENY',
    'Referrer-Policy'         => 'strict-origin-when-cross-origin',
    'Permissions-Policy'      => 'geolocation=(), microphone=(), camera=()',
    'Strict-Transport-Security' => 'max-age=31536000; includeSubDomains',
    ]);
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('html_errors', '0');
error_reporting(E_ALL);

set_exception_handler(function (Throwable $e): void {
    error_log('[gestionnaire-mdp] Exception : ' . (string) $e);
    if (PHP_SAPI === 'cli') {
        echo "Une erreur est survenue.\n";
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo "Une erreur est survenue.\n";
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    error_log(sprintf('[gestionnaire-mdp] PHP erreur [%d] %s dans %s ligne %d', $severity, $message, $file, $line));
    if ($severity === E_USER_ERROR || $severity === E_ERROR || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo "Une erreur est survenue.\n";
        exit(1);
    }
    return true;
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if (is_array($err) && in_array((int) $err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log(sprintf('[gestionnaire-mdp] Fatal : [%d] %s dans %s ligne %d',
            (int) $err['type'], (string) $err['message'], (string) $err['file'], (int) $err['line']));
        if (!headers_sent() && PHP_SAPI !== 'cli') {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        if (PHP_SAPI !== 'cli') {
            echo "Une erreur est survenue.\n";
        }
    }
});

date_default_timezone_set('UTC');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

function is_https_request(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return $https === 'on' || $https === '1' || strtolower((string) $forwardedProto) === 'https';
}

function csp_nonce(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return base64_encode(random_bytes(16));
    }
    if (empty($_SESSION['_csp_nonce']) || !is_string($_SESSION['_csp_nonce'])) {
        $_SESSION['_csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['_csp_nonce'];
}

function appliquer_headers_securite(): void
{
    if (headers_sent()) {
        return;
    }
    foreach (SECURITY_HEADERS as $name => $value) {
        header($name . ': ' . $value, true);
    }
    $nonce = csp_nonce();
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'nonce-" . $nonce . "'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "object-src 'none';",
        true
    );
}

if (!headers_sent()) {
    appliquer_headers_securite();
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

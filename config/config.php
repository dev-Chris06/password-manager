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

// ========================================================================
// Login — Seuils de déclenchement des verrous (axes)
// ========================================================================
// Axe PAIR_IP_EMAIL : 3 échecs <ip,email> dans les 5 dernières minutes.
if (!defined('LOGIN_SEUIL_PAIR_MAX_5MIN')) {
    define('LOGIN_SEUIL_PAIR_MAX_5MIN', 3);
}
// Axe GLOBAL_IP : 20 échecs sur <ip> toutes paires confondues dans 1h.
if (!defined('LOGIN_SEUIL_IP_MAX_1H')) {
    define('LOGIN_SEUIL_IP_MAX_1H', 20);
}
// Axe GLOBAL_EMAIL : 10 échecs sur <email> toutes IP confondues dans 1h.
if (!defined('LOGIN_SEUIL_EMAIL_MAX_1H')) {
    define('LOGIN_SEUIL_EMAIL_MAX_1H', 10);
}
// Durée de mémoire d'une infraction pour l'escalade (24h).
if (!defined('LOGIN_ESCALADE_HISTORIQUE_H')) {
    define('LOGIN_ESCALADE_HISTORIQUE_H', 24);
}

// ========================================================================
// Login — Escalade des durées de blocage (par axe, selon infraction_n)
// ========================================================================
// Table d'escalade : [niveau => durées en secondes].
// Un niveau > le index max retombe sur la durée la plus longue.
// (Lisible via getenv pour permettre un paramétrage conteneurisé.)
if (!defined('LOGIN_DUREE_PAIR_SEC')) {
    define('LOGIN_DUREE_PAIR_SEC',     [1 => 60,    2 => 300,   3 => 3600]);   // 1min / 5min / 1h
}
if (!defined('LOGIN_DUREE_IP_SEC')) {
    define('LOGIN_DUREE_IP_SEC',       [1 => 300,   2 => 1800,  3 => 21600]);  // 5min / 30min / 6h
}
if (!defined('LOGIN_DUREE_EMAIL_SEC')) {
    define('LOGIN_DUREE_EMAIL_SEC',    [1 => 600,   2 => 3600,  3 => 28800]);  // 10min / 1h / 8h
}

// ========================================================================
// Login — Durée de conservation des logs d'échecs
// ========================================================================
if (!defined('RL_RETENTION_DAYS')) {
    define('RL_RETENTION_DAYS', 7);
}

// ========================================================================
// Inscription — Seuils & durées (axe GLOBAL_IP_INSCRIPTION)
// ========================================================================
// Principe : l'axe GLOBAL_IP_INSCRIPTION est indépendant des axes de
// connexion. Il ne sanctionne que les créations de compte (succès ET échecs
// de validation) sur une même IP, afin d'éviter un flood de la table
// utilisateurs ou la création de faux comptes en masse.
//
// Nombre MAX de tentatives d'inscription (valides ou non) par IP dans
// la fenêtre donnée, avant déclenchement d'un verrou.
if (!defined('INSCRIPTION_SEUIL_IP_MAX_1H')) {
    define('INSCRIPTION_SEUIL_IP_MAX_1H', 5);
}
// Fenêtre glissante pour comptage des inscriptions (minutes).
if (!defined('INSCRIPTION_FENETRE_COMPTAGE_MIN')) {
    define('INSCRIPTION_FENETRE_COMPTAGE_MIN', 60);
}
// Table d'escalade : [niveau => durées en secondes].
if (!defined('INSCRIPTION_DUREE_IP_SEC')) {
    define('INSCRIPTION_DUREE_IP_SEC',   [1 => 300,   2 => 3600,  3 => 86400]); // 5min / 1h / 24h
}
// Durée de mémoire d'une infraction pour l'escalade (heures).
if (!defined('INSCRIPTION_ESCALADE_HISTORIQUE_H')) {
    define('INSCRIPTION_ESCALADE_HISTORIQUE_H', 48);
}

// (Alias vers la nouvelle convention, pour compatibilité ascendante — safe.)
if (!defined('RL_IP_EMAIL_MAX_5MIN')) {
    define('RL_IP_EMAIL_MAX_5MIN', LOGIN_SEUIL_PAIR_MAX_5MIN);
}
if (!defined('RL_IP_EMAIL_BLOCK_SEC')) {
    define('RL_IP_EMAIL_BLOCK_SEC', LOGIN_DUREE_PAIR_SEC[1]);
}
if (!defined('RL_IP_MAX_1H')) {
    define('RL_IP_MAX_1H', LOGIN_SEUIL_IP_MAX_1H);
}
if (!defined('RL_IP_BLOCK_SEC')) {
    define('RL_IP_BLOCK_SEC', LOGIN_DUREE_IP_SEC[1]);
}
if (!defined('RL_EMAIL_MAX_1H')) {
    define('RL_EMAIL_MAX_1H', LOGIN_SEUIL_EMAIL_MAX_1H);
}
if (!defined('RL_EMAIL_BLOCK_SEC')) {
    define('RL_EMAIL_BLOCK_SEC', LOGIN_DUREE_EMAIL_SEC[1]);
}
if (!defined('LOGIN_LOCK_MAX_FAILURES')) {
    define('LOGIN_LOCK_MAX_FAILURES', RL_IP_EMAIL_MAX_5MIN);
}
if (!defined('LOGIN_LOCK_SECONDS')) {
    define('LOGIN_LOCK_SECONDS', RL_IP_EMAIL_BLOCK_SEC);
}
// (Aliases plus anciens — cohérence globale.)
if (!defined('LOGIN_MAX_FAILURES')) {
    define('LOGIN_MAX_FAILURES', LOGIN_SEUIL_PAIR_MAX_5MIN);
}
if (!defined('LOGIN_BLOCK_SECONDS')) {
    define('LOGIN_BLOCK_SECONDS', LOGIN_DUREE_PAIR_SEC[1]);
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

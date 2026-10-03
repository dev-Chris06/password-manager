<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/qrcode.php';

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if (!utilisateur_connecte()) {
    http_response_code(401);
    exit;
}

$secret = $_SESSION['totp_temp_secret'] ?? null;
if (!is_string($secret) || $secret === '') {
    http_response_code(404);
    exit;
}

try {
    $issuer = 'Gestionnaire MDP';
    $account = email_utilisateur_connecte();
    $png = QRCodeGenerator::generatePng(
        generer_url_totp($secret, $issuer, $account)
    );
    header('Content-Length: ' . strlen($png));
    echo $png;
} catch (Throwable $e) {
    error_log('[gestionnaire-mdp] Génération QR TOTP impossible : ' . $e->getMessage());
    http_response_code(500);
}

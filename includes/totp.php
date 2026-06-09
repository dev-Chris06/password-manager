<?php
declare(strict_types=1);

function generer_secret_totp(): string
{
    $bytes = random_bytes(20);
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $secret .= $chars[ord($bytes[$i]) & 0x1F];
        $secret .= $chars[(ord($bytes[$i]) >> 5) & 0x1F];
    }
    return substr($secret, 0, 32);
}

function generer_url_totp(string $secret, string $issuer, string $account): string
{
    $encodedSecret = rawurlencode($secret);
    $encodedIssuer = rawurlencode($issuer);
    $encodedAccount = rawurlencode($account);
    
    return "otpauth://totp/{$encodedAccount}?secret={$encodedSecret}&issuer={$encodedIssuer}";
}

function generer_code_totp(string $secret, int $time = null): string
{
    if ($time === null) {
        $time = time();
    }
    
    $timeStep = 30;
    $counter = floor($time / $timeStep);
    
    $counterBytes = pack('J', $counter);
    $counterBytes = str_pad(substr($counterBytes, -8), 8, "\x00", STR_PAD_LEFT);
    
    // Base32 decoding
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper($secret);
    $secretBytes = '';
    $buffer = 0;
    $bitsLeft = 0;
    
    for ($i = 0; $i < strlen($secret); $i++) {
        $char = $secret[$i];
        if ($char === '=') break;
        $val = strpos($chars, $char);
        if ($val === false) continue;
        
        $buffer = ($buffer << 5) | $val;
        $bitsLeft += 5;
        
        if ($bitsLeft >= 8) {
            $secretBytes .= chr(($buffer >> ($bitsLeft - 8)) & 0xFF);
            $bitsLeft -= 8;
        }
    }
    
    if ($secretBytes === '') {
        throw new RuntimeException('Secret TOTP invalide.');
    }
    
    $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    
    $binary = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    );
    
    $otp = $binary % pow(10, 6);
    return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
}

function verifier_code_totp(string $secret, string $code, int $window = 1): bool
{
    $time = time();
    $timeStep = 30;
    
    for ($i = -$window; $i <= $window; $i++) {
        $testTime = $time + ($i * $timeStep);
        $expectedCode = generer_code_totp($secret, $testTime);
        
        if (hash_equals($expectedCode, $code)) {
            return true;
        }
    }
    
    return false;
}

function totp_actif_pour_utilisateur(int $userId): bool
{
    $stmt = get_pdo()->prepare('SELECT totp_active FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $result = $stmt->fetch();
    
    return is_array($result) && (int) $result['totp_active'] === 1;
}

function obtenir_secret_totp(int $userId): ?string
{
    $stmt = get_pdo()->prepare('SELECT totp_secret FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $result = $stmt->fetch();
    
    if (!is_array($result) || $result['totp_secret'] === null || $result['totp_secret'] === '') {
        return null;
    }
    
    return (string) $result['totp_secret'];
}

function activer_totp_pour_utilisateur(int $userId, string $secret): bool
{
    $stmt = get_pdo()->prepare(
        'UPDATE utilisateurs 
         SET totp_secret = :secret, totp_active = 1 
         WHERE id = :id'
    );
    
    return $stmt->execute([
        'secret' => $secret,
        'id' => $userId,
    ]);
}

function desactiver_totp_pour_utilisateur(int $userId): bool
{
    $stmt = get_pdo()->prepare(
        'UPDATE utilisateurs 
         SET totp_secret = NULL, totp_active = 0 
         WHERE id = :id'
    );
    
    return $stmt->execute(['id' => $userId]);
}

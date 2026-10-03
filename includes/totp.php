<?php
declare(strict_types=1);

function _decoder_base32_totp(string $secret): string
{
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

    return $secretBytes;
}

function _choisir_algo_hmac_totp(int $longueurSecretOctets): string
{
    if ($longueurSecretOctets >= 32) {
        return 'sha512';
    }
    if ($longueurSecretOctets >= 28) {
        return 'sha256';
    }
    return 'sha1';
}

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
    // Le libellé doit reprendre l'émetteur pour respecter le format otpauth.
    $encodedLabel = rawurlencode($issuer . ':' . $account);

    $secretBytes = _decoder_base32_totp($secret);
    $algo = _choisir_algo_hmac_totp(strlen($secretBytes));
    $algoParam = '';
    if ($algo !== 'sha1') {
        $algoParam = '&algorithm=' . strtoupper($algo);
    }

    return "otpauth://totp/{$encodedLabel}?secret={$encodedSecret}&issuer={$encodedIssuer}{$algoParam}";
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

    $secretBytes = _decoder_base32_totp($secret);

    if ($secretBytes === '') {
        throw new RuntimeException('Secret TOTP invalide.');
    }

    $algo = _choisir_algo_hmac_totp(strlen($secretBytes));
    $hash = hash_hmac($algo, $counterBytes, $secretBytes, true);
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

function verifier_code_totp(string $secret, string $code, ?int &$slotUtilise = null, int $window = 1): bool
{
    $time = time();
    $timeStep = 30;
    $slotUtilise = null;
    
    for ($i = -$window; $i <= $window; $i++) {
        $testTime = $time + ($i * $timeStep);
        $slot = (int) floor($testTime / $timeStep);
        $expectedCode = generer_code_totp($secret, $testTime);
        
        if (hash_equals($expectedCode, $code)) {
            $slotUtilise = $slot;
            return true;
        }
    }
    
    return false;
}

function obtenir_last_totp_slot(int $userId): int
{
    $stmt = get_pdo()->prepare('SELECT last_totp_slot FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return 0;
    }
    return (int) ($row['last_totp_slot'] ?? 0);
}

function mettre_a_jour_last_totp_slot(int $userId, int $slot): bool
{
    $pdo = get_pdo();
    try {
        $pdo->beginTransaction();
        $stmtLock = $pdo->prepare('SELECT last_totp_slot FROM utilisateurs WHERE id = :id FOR UPDATE');
        $stmtLock->execute(['id' => $userId]);
        $row = $stmtLock->fetch();
        if (!is_array($row)) {
            $pdo->rollBack();
            return false;
        }
        $actuel = (int) ($row['last_totp_slot'] ?? 0);
        if ($slot <= $actuel) {
            $pdo->rollBack();
            return false;
        }
        $stmtUpd = $pdo->prepare('UPDATE utilisateurs SET last_totp_slot = :slot WHERE id = :id');
        $ok = $stmtUpd->execute(['slot' => $slot, 'id' => $userId]);
        $pdo->commit();
        return $ok;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function generer_codes_recuperation_totp(int $nombre = 10): array
{
    $codes = [];
    for ($i = 0; $i < $nombre; $i++) {
        $blocs = [];
        for ($b = 0; $b < 4; $b++) {
            $blocs[] = strtoupper(bin2hex(random_bytes(2)));
        }
        $codes[] = implode('-', $blocs);
    }
    return $codes;
}

function enregistrer_codes_recuperation_totp(int $userId, array $clairs): bool
{
    $pdo = get_pdo();
    try {
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare('DELETE FROM totp_recovery_codes WHERE user_id = :user_id');
        $stmtDel->execute(['user_id' => $userId]);
        $stmtIns = $pdo->prepare(
            'INSERT INTO totp_recovery_codes (user_id, code_hash, created_at)
             VALUES (:user_id, :code_hash, NOW())'
        );
        foreach ($clairs as $code) {
            $hash = password_hash(str_replace('-', '', $code), PASSWORD_BCRYPT, ['cost' => 12]);
            $stmtIns->execute([
                'user_id' => $userId,
                'code_hash' => $hash,
            ]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function utiliser_code_recuperation_totp(int $userId, string $codeSaisi): bool
{
    $codeNormalise = str_replace(['-', ' '], '', strtoupper($codeSaisi));
    if (strlen($codeNormalise) < 8) {
        return false;
    }
    $pdo = get_pdo();
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT id, code_hash FROM totp_recovery_codes
             WHERE user_id = :user_id AND used_at IS NULL
             FOR UPDATE'
        );
        $stmt->execute(['user_id' => $userId]);
        $candidats = $stmt->fetchAll();
        $trouveId = null;
        foreach ($candidats as $c) {
            if (password_verify($codeNormalise, (string) $c['code_hash'])) {
                $trouveId = (int) $c['id'];
                break;
            }
        }
        if ($trouveId === null) {
            $pdo->rollBack();
            return false;
        }
        $stmtUpd = $pdo->prepare(
            'UPDATE totp_recovery_codes SET used_at = NOW() WHERE id = :id'
        );
        $stmtUpd->execute(['id' => $trouveId]);
        $pdo->commit();
        journaliser_action($userId, 'totp_code_recuperation_utilise', '#' . $trouveId);
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function supprimer_tous_codes_recuperation_totp(int $userId): void
{
    $stmt = get_pdo()->prepare('DELETE FROM totp_recovery_codes WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
}

function totp_actif_pour_utilisateur(int $userId): bool
{
    $stmt = get_pdo()->prepare('SELECT totp_active FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $result = $stmt->fetch();
    
    return is_array($result) && (int) $result['totp_active'] === 1;
}

function cle_chiffrement_totp_session(): string
{
    $encoded = $_SESSION['cle_chiffrement'] ?? null;
    if (!is_string($encoded)) {
        throw new RuntimeException('Clé de session TOTP indisponible.');
    }
    return decoder_cle_session($encoded);
}

function obtenir_secret_totp(int $userId): ?string
{
    $stmt = get_pdo()->prepare(
        'SELECT totp_secret, totp_secret_iv, totp_secret_tag FROM utilisateurs WHERE id = :id'
    );
    $stmt->execute(['id' => $userId]);
    $result = $stmt->fetch();

    if (
        !is_array($result)
        || empty($result['totp_secret'])
        || empty($result['totp_secret_iv'])
        || empty($result['totp_secret_tag'])
    ) {
        return null;
    }

    return dechiffrer_mdp_gcm(
        (string) $result['totp_secret'],
        (string) $result['totp_secret_iv'],
        (string) $result['totp_secret_tag'],
        cle_chiffrement_totp_session()
    );
}

function obtenir_hash_mdp_utilisateur(int $userId): ?string
{
    $stmt = get_pdo()->prepare('SELECT hash_mdp FROM utilisateurs WHERE id = :id');
    $stmt->execute(['id' => $userId]);
    $row = $stmt->fetch();
    if (!is_array($row) || !isset($row['hash_mdp'])) {
        return null;
    }
    return (string) $row['hash_mdp'];
}

function activer_totp_pour_utilisateur(int $userId, string $secret, ?int $slotInitial = null): bool
{
    $pdo = get_pdo();
    try {
        $encrypted = chiffrer_mdp_gcm($secret, cle_chiffrement_totp_session());
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE utilisateurs 
             SET totp_secret = :secret, totp_secret_iv = :iv, totp_secret_tag = :tag, totp_active = 1'
             . ($slotInitial !== null ? ', last_totp_slot = :slot ' : ' ')
             . 'WHERE id = :id'
        );
        $params = [
            'secret' => $encrypted['mdp_chiffre'],
            'iv' => $encrypted['iv'],
            'tag' => $encrypted['auth_tag'],
            'id' => $userId,
        ];
        if ($slotInitial !== null) {
            $params['slot'] = $slotInitial;
        }
        $ok = $stmt->execute($params);
        if (!$ok) {
            $pdo->rollBack();
            return false;
        }
        $codes = generer_codes_recuperation_totp(10);
        $okCodes = enregistrer_codes_recuperation_totp($userId, $codes);
        if (!$okCodes) {
            $pdo->rollBack();
            return false;
        }
        $_SESSION['totp_recovery_codes_affichage'] = $codes;
        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

function desactiver_totp_pour_utilisateur(int $userId): bool
{
    $pdo = get_pdo();
    try {
        $pdo->beginTransaction();
        $stmt = get_pdo()->prepare(
            'UPDATE utilisateurs 
             SET totp_secret = NULL, totp_secret_iv = NULL, totp_secret_tag = NULL, totp_active = 0, last_totp_slot = 0
             WHERE id = :id'
        );
        $ok = $stmt->execute(['id' => $userId]);
        if (!$ok) {
            $pdo->rollBack();
            return false;
        }
        supprimer_tous_codes_recuperation_totp($userId);
        $pdo->commit();
        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

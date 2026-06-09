<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function generer_sel_pbkdf2(): string
{
    return bin2hex(random_bytes(16));
}

function normaliser_sel_pbkdf2(string $salt): string
{
    $salt = trim($salt);

    if ($salt !== '' && ctype_xdigit($salt) && strlen($salt) % 2 === 0) {
        $decoded = hex2bin($salt);
        if ($decoded !== false) {
            return $decoded;
        }
    }

    $decoded = base64_decode($salt, true);
    if ($decoded !== false) {
        return $decoded;
    }

    return $salt;
}

function deriver_cle_chiffrement(string $motDePasseMaitre, string $selPbKdf2): string
{
    return hash_pbkdf2(
        'sha256',
        $motDePasseMaitre,
        normaliser_sel_pbkdf2($selPbKdf2),
        PBKDF2_ITERATIONS,
        32,
        true
    );
}

function encoder_cle_session(string $cleBinaire): string
{
    return base64_encode($cleBinaire);
}

function decoder_cle_session(string $cleEncodee): string
{
    $cle = base64_decode($cleEncodee, true);

    if ($cle === false || strlen($cle) !== 32) {
        throw new RuntimeException('Clé de session invalide.');
    }

    return $cle;
}

function decoder_base64_strict(string $value, string $label): string
{
    $decoded = base64_decode($value, true);

    if ($decoded === false) {
        throw new RuntimeException($label . ' invalide.');
    }

    return $decoded;
}

/**
 * @return array{mdp_chiffre:string, iv:string, auth_tag:string}
 */
function chiffrer_mdp_gcm(string $motDePasse, string $cleBinaire): array
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $motDePasse,
        CRYPTO_METHOD_GCM,
        $cleBinaire,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false || $tag === '') {
        throw new RuntimeException('Échec du chiffrement.');
    }

    return [
        'mdp_chiffre' => base64_encode($ciphertext),
        'iv' => base64_encode($iv),
        'auth_tag' => base64_encode($tag),
    ];
}

function dechiffrer_mdp_gcm(string $mdpChiffre, string $ivEncode, string $authTagEncode, string $cleBinaire): string
{
    $ciphertext = decoder_base64_strict($mdpChiffre, 'Mot de passe chiffré');
    $iv = decoder_base64_strict($ivEncode, 'IV');
    $tag = decoder_base64_strict($authTagEncode, 'Tag');

    $plain = openssl_decrypt(
        $ciphertext,
        CRYPTO_METHOD_GCM,
        $cleBinaire,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plain === false) {
        throw new RuntimeException('Échec du déchiffrement.');
    }

    return $plain;
}

function dechiffrer_mdp_legacy_cbc(string $mdpChiffre, string $ivEncode, string $cleBinaire): string
{
    $ciphertext = decoder_base64_strict($mdpChiffre, 'Mot de passe legacy');
    $iv = decoder_base64_strict($ivEncode, 'IV legacy');

    if (strlen($iv) !== 16) {
        throw new RuntimeException('IV legacy invalide.');
    }

    $plain = openssl_decrypt(
        $ciphertext,
        CRYPTO_METHOD_LEGACY_CBC,
        $cleBinaire,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($plain === false) {
        throw new RuntimeException('Échec du déchiffrement legacy.');
    }

    return $plain;
}

function hash_mot_de_passe_maitre(string $motDePasseMaitre): string
{
    return password_hash($motDePasseMaitre, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
}

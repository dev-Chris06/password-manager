<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/crypto.php';

function utiliser_session_extension_si_presente(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionId = $_SERVER['HTTP_X_GESTIONNAIRE_SESSION'] ?? '';
    if (!is_string($sessionId) || preg_match('/^[A-Za-z0-9,-]{16,128}$/', $sessionId) !== 1) {
        return;
    }

    session_name('gestionnaire_mdp_session');
    session_id($sessionId);
}

function demarrer_session_securisee(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');

    session_name('gestionnaire_mdp_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => is_https_request(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function csrf_token(): string
{
    demarrer_session_securisee();

    return (string) $_SESSION['csrf_token'];
}

function verifier_csrf(?string $token): bool
{
    demarrer_session_securisee();

    return is_string($token) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function normaliser_email(string $email): string
{
    return strtolower(trim($email));
}

function mot_de_passe_valide(string $motDePasse): bool
{
    return strlen($motDePasse) >= 12;
}

function utilisateur_connecte(): bool
{
    demarrer_session_securisee();

    return isset($_SESSION['user_id'], $_SESSION['email'], $_SESSION['cle_chiffrement']);
}

function exiger_authentification(): void
{
    if (!utilisateur_connecte()) {
        redirect_to('pages/login.php');
    }
    
    if (($_SESSION['totp_pending'] ?? false) === true) {
        redirect_to('pages/totp_verifier.php');
    }
}

function id_utilisateur_connecte(): int
{
    exiger_authentification();

    return (int) $_SESSION['user_id'];
}

function email_utilisateur_connecte(): string
{
    exiger_authentification();

    return (string) $_SESSION['email'];
}

function cle_chiffrement_session(): string
{
    exiger_authentification();

    return decoder_cle_session((string) $_SESSION['cle_chiffrement']);
}

function definir_flash(string $type, string $message): void
{
    demarrer_session_securisee();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function recuperer_flash(): ?array
{
    demarrer_session_securisee();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return is_array($flash) ? $flash : null;
}

function url_app(string $path = ''): string
{
    return APP_URL . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function afficher_debut_page(string $titre, bool $navigation = true): void
{
    demarrer_session_securisee();
    $connecte = utilisateur_connecte();
    ?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titre) ?> - Gestionnaire sécurisé</title>
    <link rel="stylesheet" href="<?= e(url_app('assets/css/style.css')) ?>">
</head>
<body>
<?php if ($navigation): ?>
    <header class="site-header">
        <a class="brand" href="<?= e(url_app('index.php')) ?>">Gestionnaire sécurisé</a>
        <nav class="nav">
            <?php if ($connecte): ?>
                <a href="<?= e(url_app('pages/dashboard.php')) ?>">Dashboard</a>
                <a href="<?= e(url_app('pages/ajouter.php')) ?>">Ajouter</a>
                <a href="<?= e(url_app('pages/backup.php')) ?>">Sauvegarde</a>
                <a href="<?= e(url_app('pages/journal.php')) ?>">Journal</a>
                <a href="<?= e(url_app('pages/totp_activer.php')) ?>">TOTP</a>
                <a href="<?= e(url_app('pages/changer_mdp.php')) ?>">Mot de passe maître</a>
                <a href="<?= e(url_app('pages/logout.php')) ?>">Déconnexion</a>
            <?php else: ?>
                <a href="<?= e(url_app('pages/login.php')) ?>">Connexion</a>
                <a href="<?= e(url_app('pages/register.php')) ?>">Inscription</a>
            <?php endif; ?>
        </nav>
    </header>
<?php endif; ?>
<main class="container">
    <?php
}

function afficher_fin_page(?string ...$scripts): void
{
    ?>
</main>
<?php foreach ($scripts as $script): ?>
    <?php if ($script !== null): ?>
        <script src="<?= e(url_app($script)) ?>"></script>
    <?php endif; ?>
<?php endforeach; ?>
</body>
</html>
    <?php
}

function afficher_flash(?array $flash): void
{
    if (!is_array($flash)) {
        return;
    }

    $type = (string) ($flash['type'] ?? 'info');
    $message = (string) ($flash['message'] ?? '');

    if ($message === '') {
        return;
    }
    ?>
    <div class="alert alert-<?= e($type) ?>"><?= e($message) ?></div>
    <?php
}

function inscrire_utilisateur(string $email, string $motDePasse): bool
{
    $email = normaliser_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !mot_de_passe_valide($motDePasse)) {
        return false;
    }

    $sel = generer_sel_pbkdf2();
    $hash = hash_mot_de_passe_maitre($motDePasse);
    $pdo = get_pdo();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO utilisateurs (email, hash_mdp, sel_pbkdf2) VALUES (:email, :hash_mdp, :sel_pbkdf2)'
        );
        $result = $stmt->execute([
            'email' => $email,
            'hash_mdp' => $hash,
            'sel_pbkdf2' => $sel,
        ]);
        if ($result) {
            $userId = (int) $pdo->lastInsertId();
            $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
            journaliser_action($userId, 'inscription', $emailMasque);
        }
        return $result;
    } catch (PDOException) {
        return false;
    }
}

function trouver_utilisateur_par_email(string $email): ?array
{
    $stmt = get_pdo()->prepare('SELECT * FROM utilisateurs WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => normaliser_email($email)]);
    $user = $stmt->fetch();

    return is_array($user) ? $user : null;
}

/**
 * @return array{bloque:bool, secondes_restantes:int}
 */
function statut_blocage_login(string $email): array
{
    $pdo = get_pdo();
    $email = normaliser_email($email);
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    
    // Vérifier le blocage par email
    $stmt = $pdo->prepare(
        "SELECT nb_tentatives, bloque_jusqu_a,
            CASE
                WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a > NOW()
                THEN TIMESTAMPDIFF(SECOND, NOW(), bloque_jusqu_a)
                ELSE 0
            END AS secondes_restantes,
            CASE
                WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a <= NOW()
                THEN 1
                ELSE 0
            END AS blocage_expire
        FROM tentatives_login
        WHERE email = :email
        LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $row = $stmt->fetch();

    if (!is_array($row)) {
        return ['bloque' => false, 'secondes_restantes' => 0];
    }

    if ((int) $row['blocage_expire'] === 1) {
        reinitialiser_tentatives_login($email);
        return ['bloque' => false, 'secondes_restantes' => 0];
    }

    $seconds = max(0, (int) $row['secondes_restantes']);

    // Vérifier le blocage par IP (rate limiting global par IP)
    $stmtIp = $pdo->prepare(
        "SELECT COUNT(*) as count
         FROM tentatives_login
         WHERE ip = :ip
           AND derniere_tentative > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $stmtIp->execute(['ip' => $ip]);
    $ipCount = (int) $stmtIp->fetch()['count'];
    
    // Si plus de 20 tentatives depuis la même IP dans la dernière heure, bloquer
    if ($ipCount >= 20) {
        return ['bloque' => true, 'secondes_restantes' => 3600];
    }

    return ['bloque' => $seconds > 0, 'secondes_restantes' => $seconds];
}

function enregistrer_echec_login(string $email): void
{
    $email = normaliser_email($email);
    $pdo = get_pdo();
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $maxTentatives = (int) LOGIN_MAX_FAILURES;
    $blocageSecondes = (int) LOGIN_BLOCK_SECONDS;
    
    // Tenter d'insérer avec IP (si la colonne existe), sinon insérer sans IP
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO tentatives_login (email, ip, nb_tentatives, derniere_tentative, bloque_jusqu_a)
            VALUES (:email, :ip, 1, NOW(), NULL)
            ON DUPLICATE KEY UPDATE
                nb_tentatives = CASE
                    WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a <= NOW() THEN 1
                    ELSE nb_tentatives + 1
                END,
                derniere_tentative = NOW(),
                ip = :ip,
                bloque_jusqu_a = CASE
                    WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a > NOW() THEN bloque_jusqu_a
                    WHEN (CASE
                        WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a <= NOW() THEN 1
                        ELSE nb_tentatives + 1
                    END) >= {$maxTentatives} THEN DATE_ADD(NOW(), INTERVAL {$blocageSecondes} SECOND)
                    ELSE NULL
                END"
        );
        $stmt->execute(['email' => $email, 'ip' => $ip]);
    } catch (PDOException $e) {
        // Si la colonne ip n'existe pas, utiliser l'ancienne requête sans IP
        if (str_contains($e->getMessage(), 'Unknown column')) {
            $stmt = $pdo->prepare(
                "INSERT INTO tentatives_login (email, nb_tentatives, derniere_tentative, bloque_jusqu_a)
                VALUES (:email, 1, NOW(), NULL)
                ON DUPLICATE KEY UPDATE
                    nb_tentatives = CASE
                        WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a <= NOW() THEN 1
                        ELSE nb_tentatives + 1
                    END,
                    derniere_tentative = NOW(),
                    bloque_jusqu_a = CASE
                        WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a > NOW() THEN bloque_jusqu_a
                        WHEN (CASE
                            WHEN bloque_jusqu_a IS NOT NULL AND bloque_jusqu_a <= NOW() THEN 1
                            ELSE nb_tentatives + 1
                        END) >= {$maxTentatives} THEN DATE_ADD(NOW(), INTERVAL {$blocageSecondes} SECOND)
                        ELSE NULL
                    END"
            );
            $stmt->execute(['email' => $email]);
        } else {
            throw $e;
        }
    }

    $stmtCheck = $pdo->prepare('SELECT nb_tentatives FROM tentatives_login WHERE email = :email');
    $stmtCheck->execute(['email' => $email]);
    $row = $stmtCheck->fetch();
    if (is_array($row) && (int) $row['nb_tentatives'] >= $maxTentatives) {
        $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
        journaliser_action(null, 'compte_bloque', $emailMasque);
    }
}

function reinitialiser_tentatives_login(string $email): void
{
    $stmt = get_pdo()->prepare(
        'UPDATE tentatives_login
         SET nb_tentatives = 0, bloque_jusqu_a = NULL, derniere_tentative = NOW()
         WHERE email = :email'
    );
    $stmt->execute(['email' => normaliser_email($email)]);
}

function connecter_utilisateur(string $email, string $motDePasse): array
{
    demarrer_session_securisee();
    $email = normaliser_email($email);
    $statut = statut_blocage_login($email);

    if (rand(1, 100) === 1) {
        purger_journal_ancien();
    }

    if ($statut['bloque']) {
        $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
        journaliser_action(null, 'connexion_echec', $emailMasque . ' (bloqué)');
        return [
            'ok' => false,
            'bloque' => true,
            'message' => 'Compte temporairement bloqué. Réessayez dans ' . $statut['secondes_restantes'] . ' seconde(s).',
        ];
    }

    $user = trouver_utilisateur_par_email($email);

    if (!is_array($user) || !password_verify($motDePasse, (string) $user['hash_mdp'])) {
        enregistrer_echec_login($email);
        $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
        journaliser_action(null, 'connexion_echec', $emailMasque);
        return ['ok' => false, 'bloque' => false, 'message' => 'Identifiants invalides.'];
    }

    reinitialiser_tentatives_login($email);
    session_regenerate_id(true);

    $cle = deriver_cle_chiffrement($motDePasse, (string) $user['sel_pbkdf2']);
    $_SESSION['cle_chiffrement'] = encoder_cle_session($cle);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    migrer_entrees_legacy_cbc_vers_gcm((int) $user['id'], $cle);

    if ((int) $user['totp_active'] === 1) {
        $_SESSION['totp_pending'] = true;
        $_SESSION['totp_user_id'] = (int) $user['id'];
        $_SESSION['email'] = (string) $user['email'];
        $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
        journaliser_action((int) $user['id'], 'connexion_mdp_ok_totp_attendu', $emailMasque);
        return ['ok' => true, 'bloque' => false, 'totp_required' => true, 'message' => 'Mot de passe correct. Vérification TOTP requise.'];
    }

    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['email'] = (string) $user['email'];

    $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
    journaliser_action((int) $user['id'], 'connexion_ok', $emailMasque);

    return ['ok' => true, 'bloque' => false, 'message' => 'Connexion réussie.'];
}

function deconnecter_utilisateur(): void
{
    demarrer_session_securisee();
    $userId = $_SESSION['user_id'] ?? null;
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => (bool) $params['secure'],
                'httponly' => (bool) $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]
        );
    }

    if ($userId !== null) {
        journaliser_action((int) $userId, 'deconnexion', '');
    }

    session_destroy();
}

function migrer_entrees_legacy_cbc_vers_gcm(int $userId, string $cleBinaire): void
{
    $pdo = get_pdo();
    $select = $pdo->prepare(
        "SELECT id, mdp_chiffre, iv
         FROM entrees
         WHERE user_id = :user_id AND (auth_tag IS NULL OR auth_tag = '')"
    );
    $select->execute(['user_id' => $userId]);
    $update = $pdo->prepare(
        'UPDATE entrees
         SET mdp_chiffre = :mdp_chiffre, iv = :iv, auth_tag = :auth_tag
         WHERE id = :id AND user_id = :user_id'
    );

    foreach ($select->fetchAll() as $entry) {
        try {
            $plain = dechiffrer_mdp_legacy_cbc((string) $entry['mdp_chiffre'], (string) $entry['iv'], $cleBinaire);
            $encrypted = chiffrer_mdp_gcm($plain, $cleBinaire);
            $update->execute([
                'mdp_chiffre' => $encrypted['mdp_chiffre'],
                'iv' => $encrypted['iv'],
                'auth_tag' => $encrypted['auth_tag'],
                'id' => (int) $entry['id'],
                'user_id' => $userId,
            ]);
        } catch (Throwable) {
            continue;
        }
    }
}

function journaliser_action(
    ?int $userId,
    string $action,
    string $detail = ''
): void {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    try {
        $stmt = get_pdo()->prepare(
            'INSERT INTO journal_actions (user_id, action, detail, ip, user_agent)
             VALUES (:user_id, :action, :detail, :ip, :user_agent)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action'  => substr($action, 0, 64),
            'detail'  => substr($detail, 0, 512),
            'ip'      => $ip,
            'user_agent' => $ua,
        ]);
    } catch (Throwable) {
    }
}

function purger_journal_ancien(): void
{
    if (!defined('JOURNAL_RETENTION_DAYS')) {
        return;
    }
    try {
        $stmt = get_pdo()->prepare(
            'DELETE FROM journal_actions
             WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->execute(['days' => (int) JOURNAL_RETENTION_DAYS]);
    } catch (Throwable) {
    }
}

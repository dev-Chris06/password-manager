<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/crypto.php';

function utiliser_session_extension_si_presente(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $ajaxToken = $_SERVER['HTTP_X_AJAX_TOKEN'] ?? '';
    if (!is_string($ajaxToken) || $ajaxToken === '') {
        return;
    }

    session_name('gestionnaire_mdp_session');
}

function appliquer_timeout_session(): void
{
    $now = time();

    if (defined('SESSION_TIMEOUT_ABSOLU_SEC') && isset($_SESSION['_session_created'])) {
        if (($now - (int) $_SESSION['_session_created']) > (int) SESSION_TIMEOUT_ABSOLU_SEC) {
            $wasLogged = !empty($_SESSION['user_id']);
            $_SESSION = [];
            @session_regenerate_id(true);
            session_start();
            if ($wasLogged) {
                header('Location: ' . APP_URL . '/pages/login.php?e=session_timeout');
                exit;
            }
            return;
        }
    }
    if (!isset($_SESSION['_session_created'])) {
        $_SESSION['_session_created'] = $now;
    }

    if (defined('SESSION_TIMEOUT_INACTIVITE_SEC') && isset($_SESSION['_last_activity'])) {
        if (($now - (int) $_SESSION['_last_activity']) > (int) SESSION_TIMEOUT_INACTIVITE_SEC) {
            $wasLogged = !empty($_SESSION['user_id']);
            $_SESSION = [];
            @session_regenerate_id(true);
            session_start();
            if ($wasLogged) {
                header('Location: ' . APP_URL . '/pages/login.php?e=inactivite');
                exit;
            }
            return;
        }
    }
    $_SESSION['_last_activity'] = $now;
}

function demarrer_session_securisee(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        appliquer_timeout_session();
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
    if (empty($_SESSION['ajax_token']) || !is_string($_SESSION['ajax_token'])) {
        $_SESSION['ajax_token'] = bin2hex(random_bytes(32));
    }

    appliquer_timeout_session();
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
 * @deprecated Défaut 3 — Utiliser fusionner_statuts_verrous() + la table login_blocages (source unique de vérité).
 *             Cette fonction est conservée pour compatibilité ascendante et les tests historiques.
 *             Elle lit l'ancienne table tentatives_login (seulement axe <email>).
 *
 * @return array{bloque:bool, secondes_restantes:int}
 */
function statut_blocage_login(string $email): array
{
    try {
        $pdo = get_pdo();
        $email = normaliser_email($email);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

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

        return ['bloque' => $seconds > 0, 'secondes_restantes' => $seconds];
    } catch (Throwable) {
        return ['bloque' => false, 'secondes_restantes' => 0];
    }
}

function enregistrer_echec_login(string $email): void
{
    try {
        $email = normaliser_email($email);
        $pdo = get_pdo();
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $maxTentatives = (int) LOGIN_MAX_FAILURES;
        $blocageSecondes = (int) LOGIN_BLOCK_SECONDS;

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

        $stmtCheck = $pdo->prepare('SELECT nb_tentatives FROM tentatives_login WHERE email = :email');
        $stmtCheck->execute(['email' => $email]);
        $row = $stmtCheck->fetch();
        if (is_array($row) && (int) $row['nb_tentatives'] >= $maxTentatives) {
            $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
            journaliser_action(null, 'compte_bloque', $emailMasque);
        }
    } catch (Throwable) {
    }
}

function reinitialiser_tentatives_login(string $email): void
{
    try {
        $stmt = get_pdo()->prepare(
            'UPDATE tentatives_login
             SET nb_tentatives = 0, bloque_jusqu_a = NULL, derniere_tentative = NOW()
             WHERE email = :email'
        );
        $stmt->execute(['email' => normaliser_email($email)]);
    } catch (Throwable) {
    }
}

function enregistrer_tentative_login_detail(string $email, string $ip, bool $succes): void
{
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO tentatives_login_detail (ip, email, tentative_at, succes)
             VALUES (:ip, :email, NOW(), :succes)'
        );
        $stmt->execute([
            'ip'     => substr($ip, 0, 45),
            'email'  => normaliser_email($email),
            'succes' => $succes ? 1 : 0,
        ]);
    } catch (Throwable) {
    }
}

function purger_tentatives_login_anciennes(): void
{
    if (!defined('RL_RETENTION_DAYS')) {
        return;
    }
    try {
        $pdo = get_pdo();
        $jours = (int) RL_RETENTION_DAYS;
        $stmt1 = $pdo->prepare(
            'DELETE FROM tentatives_login_detail
             WHERE tentative_at < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt1->execute(['days' => $jours]);

        $stmt2 = $pdo->prepare(
            'DELETE FROM tentatives_login
             WHERE derniere_tentative < DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt2->execute(['days' => $jours]);
    } catch (Throwable) {
    }
}

/**
 * Migration idempotente login_blocages V2 → V3.
 *
 * Historique :
 *  - V2 (avant Défaut 3) : UNIQUE(ip, email), pour la seule paire <IP,Email>.
 *  - V3 (Défaut 3 — escalade) : ajout colonne `axe` ENUM + `infraction_n`
 *       + `created_at`, UNIQUE composite (axe, ip, email). Permet les axes
 *       GLOBAL_IP (email='') et GLOBAL_EMAIL (ip='').
 *
 * Si la table n'existe pas → CREATE.
 * Si la table est V2 (pas de colonne `axe`) → DROP+CREATE puis réinsertion des
 * lignes existantes avec axe='PAIR_IP_EMAIL'.
 * Si déjà V3 → no-op (rapide, peut être appelé à chaque hit sans coût).
 */
function migrer_login_blocages_si_besoin(): void
{
    // Les migrations sont exécutées explicitement au déploiement. Ne jamais
    // modifier le schéma depuis une requête HTTP : l'ancienne implémentation
    // pouvait détruire les verrous actifs par un DROP TABLE.
}

// ========================================================================
// Escalade : helpers partagés
// ========================================================================

/**
 * Durée de blocage pour un axe donné, en fonction du niveau d'infraction.
 * Clamp sur la valeur max si $n dépasse la table.
 *
 * @param string $axe 'PAIR_IP_EMAIL' | 'GLOBAL_IP' | 'GLOBAL_EMAIL'
 * @param int $niveau Numéro d'infraction (>=1)
 */
function duree_blocage_escalade(string $axe, int $niveau): int
{
    $niveau = max(1, $niveau);
    switch ($axe) {
        case 'PAIR_IP_EMAIL':
            $t = LOGIN_DUREE_PAIR_SEC;
            break;
        case 'GLOBAL_IP':
            $t = LOGIN_DUREE_IP_SEC;
            break;
        case 'GLOBAL_EMAIL':
            $t = LOGIN_DUREE_EMAIL_SEC;
            break;
        case 'GLOBAL_IP_INSCRIPTION':
            $t = defined('INSCRIPTION_DUREE_IP_SEC') ? INSCRIPTION_DUREE_IP_SEC : [1 => 300, 2 => 3600, 3 => 86400];
            break;
        default:
            $t = LOGIN_DUREE_PAIR_SEC;
    }
    $t = is_array($t) ? $t : [1 => (int) $t];
    ksort($t);
    $cleMax = max(array_keys($t));
    $choisie = min($niveau, $cleMax);
    return (int) ($t[$choisie] ?? end($t));
}

/**
 * Prochain niveau d'infraction pour une clé (axe, ip, email).
 * Compte le nombre de verrous (même expirés) créés dans la fenêtre
 * LOGIN_ESCALADE_HISTORIQUE_H sur la même clé, +1 pour la nouvelle
 * infraction.
 */
function prochain_niveau_infraction(string $axe, string $ip, string $email): int
{
    migrer_login_blocages_si_besoin();
    try {
        $pdo = get_pdo();
        $heures = (int) LOGIN_ESCALADE_HISTORIQUE_H;
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(infraction_n), 0) AS n
             FROM login_blocages
             WHERE axe = :axe AND ip = :ip AND email = :email
               AND created_at > DATE_SUB(NOW(), INTERVAL {$heures} HOUR)"
        );
        $stmt->execute(compact('axe', 'ip', 'email'));
        $n = (int) $stmt->fetchColumn();
        return max(1, $n + 1);
    } catch (Throwable) {
        return 1;
    }
}

function prochain_niveau_infraction_inscription(string $ip): int
{
    migrer_login_blocages_si_besoin();
    try {
        $pdo = get_pdo();
        $heures = defined('INSCRIPTION_ESCALADE_HISTORIQUE_H')
            ? (int) INSCRIPTION_ESCALADE_HISTORIQUE_H
            : 48;
        $stmt = $pdo->prepare(
            "SELECT COALESCE(MAX(infraction_n), 0) AS n
             FROM login_blocages
             WHERE axe = 'GLOBAL_IP_INSCRIPTION' AND ip = :ip AND email = ''
               AND created_at > DATE_SUB(NOW(), INTERVAL {$heures} HOUR)"
        );
        $stmt->execute(['ip' => substr($ip, 0, 45)]);
        $n = (int) $stmt->fetchColumn();
        return max(1, $n + 1);
    } catch (Throwable) {
        return 1;
    }
}

// ========================================================================
// Comptage des tentatives d'inscription (fenêtre glissante, IP seule)
// ========================================================================

function enregistrer_tentative_inscription(string $ip): void
{
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO tentatives_login_detail (ip, email, tentative_at, succes)
             VALUES (:ip, '', NOW(), 0)"
        );
        $stmt->execute(['ip' => substr($ip, 0, 45)]);
    } catch (Throwable) {
    }
}

/**
 * @return int Nombre de tentatives d'inscription (POST sur register.php)
 *             déjà observées sur l'IP dans la fenêtre configurée.
 */
function compter_tentatives_inscription_dans_fenetre(string $ip): int
{
    try {
        $pdo = get_pdo();
        $minutes = defined('INSCRIPTION_FENETRE_COMPTAGE_MIN')
            ? (int) INSCRIPTION_FENETRE_COMPTAGE_MIN
            : 60;
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM tentatives_login_detail
             WHERE ip = :ip AND email = '' AND succes = 0
               AND tentative_at > DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
        );
        $stmt->execute(['ip' => substr($ip, 0, 45)]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Pose ou remplace un verrou.
 *
 * Règles d'ergonomie sécurité :
 *  - La durée est toujours celle du NOUVEAU niveau escaladé (pas max avec l'ancien).
 *  - Si un verrou plus long est DÉJÀ en cours (GREATEST), on ne LE RACCOURCIT PAS
 *    (évite qu'une attaque rapide en remplace un long par un plus court).
 *  - Pour les axes globaux :
 *      GLOBAL_IP     → email=''
 *      GLOBAL_EMAIL  → ip=''
 *
 * @param int $niveau Infraction_n à inscrire sur le verrou posé.
 */
function poser_verrou_axe(string $axe, string $ip, string $email, int $niveau): void
{
    migrer_login_blocages_si_besoin();
    try {
        $duree = duree_blocage_escalade($axe, $niveau);
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO login_blocages (axe, ip, email, bloque_jusqu_a, infraction_n)
             VALUES (:axe, :ip, :email, DATE_ADD(NOW(), INTERVAL :d SECOND), :niv)
             ON DUPLICATE KEY UPDATE
                 bloque_jusqu_a = GREATEST(
                     bloque_jusqu_a,
                     DATE_ADD(NOW(), INTERVAL :d2 SECOND)
                 ),
                 infraction_n = GREATEST(infraction_n, :niv2)"
        );
        $stmt->execute([
            'axe'  => $axe,
            'ip'   => substr($ip, 0, 45),
            'email'=> normaliser_email($email),
            'd'    => $duree,
            'd2'   => $duree,
            'niv'  => $niveau,
            'niv2' => $niveau,
        ]);
    } catch (Throwable) {
    }
}

/**
 * Récupère le statut d'un verrou d'un axe donné.
 *
 * @return array{bloque:bool, secondes_restantes:int, axe:string, infraction_n:int}
 */
function statut_verrou_axe(string $axe, string $ip, string $email): array
{
    migrer_login_blocages_si_besoin();
    $vide = ['bloque' => false, 'secondes_restantes' => 0, 'axe' => $axe, 'infraction_n' => 0];
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "SELECT bloque_jusqu_a, infraction_n FROM login_blocages
             WHERE axe = :axe AND ip = :ip AND email = :email LIMIT 1"
        );
        $stmt->execute([
            'axe'   => $axe,
            'ip'    => substr($ip, 0, 45),
            'email' => normaliser_email($email),
        ]);
        $row = $stmt->fetch();
        if (!is_array($row) || empty($row['bloque_jusqu_a'])) {
            return $vide;
        }
        $dateFin = new DateTimeImmutable((string) $row['bloque_jusqu_a']);
        $maintenant = new DateTimeImmutable();
        $s = $dateFin->getTimestamp() - $maintenant->getTimestamp();
        if ($s <= 0) {
            return $vide;
        }
        return [
            'bloque'              => true,
            'secondes_restantes'  => $s,
            'axe'                 => $axe,
            'infraction_n'        => (int) $row['infraction_n'],
        ];
    } catch (Throwable) {
        return $vide;
    }
}

// ========================================================================
// Axes individuels (wrappers typés)
// ========================================================================

function poser_verrou_pair(string $email, string $ip): void
{
    $niv = prochain_niveau_infraction('PAIR_IP_EMAIL', $ip, normaliser_email($email));
    poser_verrou_axe('PAIR_IP_EMAIL', $ip, normaliser_email($email), $niv);
}

function poser_verrou_ip(string $ip): void
{
    $niv = prochain_niveau_infraction('GLOBAL_IP', substr($ip, 0, 45), '');
    poser_verrou_axe('GLOBAL_IP', substr($ip, 0, 45), '', $niv);
}

function poser_verrou_email(string $email): void
{
    $e = normaliser_email($email);
    $niv = prochain_niveau_infraction('GLOBAL_EMAIL', '', $e);
    poser_verrou_axe('GLOBAL_EMAIL', '', $e, $niv);
}

/**
 * @return array{bloque:bool, secondes_restantes:int, axe:string, infraction_n:int}
 */
function statut_verrou_pair(string $email, string $ip): array
{
    return statut_verrou_axe('PAIR_IP_EMAIL', $ip, normaliser_email($email));
}

/**
 * @return array{bloque:bool, secondes_restantes:int, axe:string, infraction_n:int}
 */
function statut_verrou_ip(string $ip): array
{
    return statut_verrou_axe('GLOBAL_IP', substr($ip, 0, 45), '');
}

/**
 * @return array{bloque:bool, secondes_restantes:int, axe:string, infraction_n:int}
 */
function statut_verrou_email(string $email): array
{
    return statut_verrou_axe('GLOBAL_EMAIL', '', normaliser_email($email));
}

function poser_verrou_inscription_ip(string $ip): void
{
    $i = substr($ip, 0, 45);
    $niv = prochain_niveau_infraction_inscription($i);
    poser_verrou_axe('GLOBAL_IP_INSCRIPTION', $i, '', $niv);
}

/**
 * @return array{bloque:bool, secondes_restantes:int, axe:string, infraction_n:int}
 */
function statut_verrou_inscription_ip(string $ip): array
{
    return statut_verrou_axe('GLOBAL_IP_INSCRIPTION', substr($ip, 0, 45), '');
}

function supprimer_verrou_inscription_ip(string $ip): void
{
    migrer_login_blocages_si_besoin();
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "DELETE FROM login_blocages
             WHERE axe = 'GLOBAL_IP_INSCRIPTION' AND ip = :ip AND email = ''"
        );
        $stmt->execute(['ip' => substr($ip, 0, 45)]);
    } catch (Throwable) {
    }
}

/**
 * Supprime TOUS les verrous qui concernent un couple <ip, email> — à savoir
 * le verrou PAIR_IP_EMAIL exact, et aussi (le cas échéant) les verrous
 * globaux GLOBAL_IP / GLOBAL_EMAIL associés. Ne supprime PAS les autres
 * paires de l'IP globale ni les autres IP d'un email global.
 */
function supprimer_verrou_couple(string $email, string $ip): void
{
    migrer_login_blocages_si_besoin();
    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare(
            "DELETE FROM login_blocages
             WHERE (axe = 'PAIR_IP_EMAIL' AND ip = :ip AND email = :email)
                OR (axe = 'GLOBAL_IP'    AND ip = :ip AND email = '')
                OR (axe = 'GLOBAL_EMAIL' AND ip = ''  AND email = :email)"
        );
        $stmt->execute([
            'ip'    => substr($ip, 0, 45),
            'email' => normaliser_email($email),
        ]);
    } catch (Throwable) {
    }
}

// ========================================================================
// Fusion multi-axes — UNIQUE POINT D'ENTRÉE pour le pre-check
// ========================================================================

/**
 * Fusionne le statut des 3 axes. Retourne :
 *  - bloque              : TRUE si au moins 1 axe est bloqué
 *  - secondes_restantes  : MAX() des délais restants (le + restrictif)
 *  - axes_declenches     : liste détaillée pour logs/j post (pas affichée à l'utilisateur)
 *
 * @return array{bloque:bool, secondes_restantes:int, axes_declenches:list<array>}
 */
function fusionner_statuts_verrous(string $ip, string $email): array
{
    $sPair  = statut_verrou_pair($email, $ip);
    $sIp    = statut_verrou_ip($ip);
    $sEmail = statut_verrou_email($email);

    $tous = [$sPair, $sIp, $sEmail];
    $axes = array_values(array_filter($tous, static fn (array $s): bool => !empty($s['bloque'])));

    $max = 0;
    foreach ($tous as $s) {
        if (!empty($s['secondes_restantes']) && (int)$s['secondes_restantes'] > $max) {
            $max = (int) $s['secondes_restantes'];
        }
    }
    return [
        'bloque'             => $axes !== [],
        'secondes_restantes' => $max,
        'axes_declenches'    => $axes,
    ];
}

// ========================================================================
// Compatibilité ascendante (anciens noms appelés depuis d'autres scripts)
// ========================================================================
function statut_verrou_login(string $email, string $ip): array
{
    $s = statut_verrou_pair($email, $ip);
    return ['bloque' => $s['bloque'], 'secondes_restantes' => $s['secondes_restantes']];
}

function poser_verrou_login(string $email, string $ip): void
{
    poser_verrou_pair($email, $ip);
}

function supprimer_verrou_login(string $email, string $ip): void
{
    supprimer_verrou_couple($email, $ip);
}

/**
 * @deprecated Défaut 3 — Utiliser fusionner_statuts_verrous() depuis login_blocages.
 *             Ancienne logique de fallback en fenêtre glissante sur tentatives_login_detail
 *             (seulement l'axe <IP, Email>). Conservée pour sécurité si appelée depuis
 *             un module externe, mais connecter_utilisateur() ne l'utilise plus.
 *
 * @return array{bloque:bool, secondes_restantes:int}
 */
function statut_blocage_login_detail(string $email): array
{
    try {
        $pdo = get_pdo();
        $email = normaliser_email($email);
        $ip    = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM tentatives_login_detail
             WHERE ip = :ip AND email = :email AND succes = 0
               AND tentative_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        );
        $stmt->execute(['ip' => $ip, 'email' => $email]);
        if ((int) $stmt->fetchColumn() >= (int) RL_IP_EMAIL_MAX_5MIN) {
            return ['bloque' => true, 'secondes_restantes' => (int) RL_IP_EMAIL_BLOCK_SEC];
        }

        return ['bloque' => false, 'secondes_restantes' => 0];
    } catch (Throwable) {
        return ['bloque' => false, 'secondes_restantes' => 0];
    }
}

function ralentir_exponentiel(int $nbEchecsPrecedents): void
{
    $delaisMicro = [0, 2_000_000, 5_000_000, 15_000_000, 30_000_000, 60_000_000];
    $index = min(max($nbEchecsPrecedents, 0), count($delaisMicro) - 1);
    $delai = $delaisMicro[$index] + random_int(0, 500_000);
    usleep($delai);
}

function connecter_utilisateur(string $email, string $motDePasse): array
{
    try {
        demarrer_session_securisee();
        $email = normaliser_email($email);
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        migrer_login_blocages_si_besoin();

        // ============================================================
        // PRE-CHECK UNIQUE : fusion des 3 axes + MAX des durées.
        // La table login_blocages est LA SEULE source de vérité pour
        // l'affichage du délai restant (décrémenté chaque seconde).
        // Aucun fallback à seuil fenêtre, aucun plafonnement.
        // ============================================================
        $statut = fusionner_statuts_verrous($ip, $email);
        if ($statut['bloque']) {
            if (rand(1, 100) === 1) {
                purger_journal_ancien();
                purger_tentatives_login_anciennes();
                try {
                    get_pdo()->exec('DELETE FROM login_blocages WHERE bloque_jusqu_a <= NOW()');
                } catch (Throwable) {
                }
            }
            ralentir_exponentiel(LOGIN_MAX_FAILURES);
            $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);

            $axesDetails = '';
            if (!empty($statut['axes_declenches'])) {
                $axes = [];
                foreach ($statut['axes_declenches'] as $a) {
                    $axes[] = sprintf(
                        '%s(n=%d)',
                        (string) $a['axe'],
                        (int) ($a['infraction_n'] ?? 1)
                    );
                }
                $axesDetails = ' [' . implode(', ', $axes) . ']';
            }
            journaliser_action(null, 'connexion_echec', $emailMasque . ' (bloqué)' . $axesDetails);

            $secondes = max(0, (int) $statut['secondes_restantes']);
            return [
                'ok' => false,
                'bloque' => true,
                'secondes_restantes' => $secondes,
                'message' => 'Compte temporairement bloqué. Réessayez dans ' . $secondes . ' seconde(s).',
                '_axes' => $statut['axes_declenches'],
            ];
        }

        if (rand(1, 100) === 1) {
            purger_journal_ancien();
            purger_tentatives_login_anciennes();
            try {
                get_pdo()->exec('DELETE FROM login_blocages WHERE bloque_jusqu_a <= NOW()');
            } catch (Throwable) {
            }
        }

        $nbEchecsPrecedents = 0;
        try {
            $pdo = get_pdo();
            $stmtFail = $pdo->prepare('SELECT nb_tentatives FROM tentatives_login WHERE email = :email LIMIT 1');
            $stmtFail->execute(['email' => $email]);
            $row = $stmtFail->fetch();
            if (is_array($row)) {
                $nbEchecsPrecedents = (int) $row['nb_tentatives'];
            }
        } catch (Throwable) {
        }

        $user = trouver_utilisateur_par_email($email);
        $mpOk = false;

        if (is_array($user)) {
            $mpOk = password_verify($motDePasse, (string) $user['hash_mdp']);
        } else {
            $fakeHash = defined('LOGIN_DUMMY_BCRYPT_HASH')
                ? (string) LOGIN_DUMMY_BCRYPT_HASH
                : '$2y$12$DRjm/5F3C1g3QVtR0k1WGOxhpBpR.G0a1.3nVh19XxZz7f7q5b6Oa';
            password_verify($motDePasse, $fakeHash);
        }

        if (!is_array($user) || !$mpOk) {
            enregistrer_echec_login($email);
            enregistrer_tentative_login_detail($email, $ip, false);

            // ============================================================
            // Pose des VERROUS EXPLICITES multi-axes (escalade).
            // Chaque seuil est évalué via COUNT() sur tentatives_login_detail.
            // Les durées sont choisies via l'historique d'infraction_n sur
            // les 24 dernières heures de la clé.
            // ============================================================
            try {
                $pdo = get_pdo();

                $stmtPair = $pdo->prepare(
                    "SELECT COUNT(*) FROM tentatives_login_detail
                     WHERE ip = :ip AND email = :email AND succes = 0
                       AND tentative_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
                );
                $stmtPair->execute(['ip' => $ip, 'email' => $email]);
                $cntPair = (int) $stmtPair->fetchColumn();
                if ($cntPair >= (int) LOGIN_SEUIL_PAIR_MAX_5MIN) {
                    poser_verrou_pair($email, $ip);
                }

                $stmtIp = $pdo->prepare(
                    "SELECT COUNT(*) FROM tentatives_login_detail
                     WHERE ip = :ip AND succes = 0
                       AND tentative_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                );
                $stmtIp->execute(['ip' => $ip]);
                $cntIp = (int) $stmtIp->fetchColumn();
                if ($cntIp >= (int) LOGIN_SEUIL_IP_MAX_1H) {
                    poser_verrou_ip($ip);
                }

                $stmtEmail = $pdo->prepare(
                    "SELECT COUNT(*) FROM tentatives_login_detail
                     WHERE email = :email AND succes = 0
                       AND tentative_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
                );
                $stmtEmail->execute(['email' => $email]);
                $cntEmail = (int) $stmtEmail->fetchColumn();
                if ($cntEmail >= (int) LOGIN_SEUIL_EMAIL_MAX_1H) {
                    poser_verrou_email($email);
                }
            } catch (Throwable) {
            }

            ralentir_exponentiel($nbEchecsPrecedents + 1);
            $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
            journaliser_action(null, 'connexion_echec', $emailMasque);
            return ['ok' => false, 'bloque' => false, 'message' => 'Identifiants invalides.'];
        }

        reinitialiser_tentatives_login($email);
        supprimer_verrou_couple($email, $ip);
        enregistrer_tentative_login_detail($email, $ip, true);
        session_regenerate_id(true);

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        if (empty($_SESSION['ajax_token']) || !is_string($_SESSION['ajax_token'])) {
            $_SESSION['ajax_token'] = bin2hex(random_bytes(32));
        }

        $cle = deriver_cle_chiffrement($motDePasse, (string) $user['sel_pbkdf2']);

        if ((int) $user['totp_active'] === 1) {
            $_SESSION['totp_pending'] = true;
            $_SESSION['totp_user_id'] = (int) $user['id'];
            $_SESSION['totp_email'] = (string) $user['email'];
            $_SESSION['totp_fail_count'] = 0;
            $_SESSION['cle_chiffrement'] = encoder_cle_session($cle);
            unset($_SESSION['user_id'], $_SESSION['email']);
            $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
            journaliser_action((int) $user['id'], 'connexion_mdp_ok_totp_attendu', $emailMasque);
            return ['ok' => true, 'bloque' => false, 'totp_required' => true, 'message' => 'Mot de passe correct. Vérification TOTP requise.'];
        }

        $_SESSION['cle_chiffrement'] = encoder_cle_session($cle);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['email'] = (string) $user['email'];

        $emailMasque = substr($email, 0, 3) . '***' . substr(strrchr($email, '@'), 0);
        journaliser_action((int) $user['id'], 'connexion_ok', $emailMasque);

        return ['ok' => true, 'bloque' => false, 'message' => 'Connexion réussie.'];
    } catch (Throwable) {
        return ['ok' => false, 'bloque' => false, 'message' => 'Service temporairement indisponible. Réessayez dans quelques instants.'];
    }
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

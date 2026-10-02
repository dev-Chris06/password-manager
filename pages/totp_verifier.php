<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';

demarrer_session_securisee();

$erreur = '';

if (!isset($_SESSION['totp_pending']) || $_SESSION['totp_pending'] !== true || !isset($_SESSION['totp_user_id'])) {
    definir_flash('error', 'Aucune vérification TOTP en attente.');
    redirect_to('pages/login.php');
}

$userId = (int) $_SESSION['totp_user_id'];
$failCount = (int) ($_SESSION['totp_fail_count'] ?? 0);

if ($failCount >= 3) {
    journaliser_action($userId, 'totp_echec_trop_essais', '3 échecs TOTP, session détruite');
    $_SESSION = [];
    @session_regenerate_id(true);
    session_destroy();
    session_start();
    definir_flash('error', 'Trop de tentatives échouées. Veuillez vous reconnecter.');
    redirect_to('pages/login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $code = (string) ($_POST['code'] ?? '');
        $modeRecovery = isset($_POST['mode_recovery']) && $_POST['mode_recovery'] === '1';
        
        if ($code === '') {
            $erreur = $modeRecovery
                ? 'Veuillez entrer un code de récupération.'
                : 'Veuillez entrer le code TOTP.';
        } elseif ($modeRecovery) {
            $ok = utiliser_code_recuperation_totp($userId, $code);
            if (!$ok) {
                unset($_SESSION['cle_chiffrement']);
                $failCount++;
                $_SESSION['totp_fail_count'] = $failCount;
                journaliser_action($userId, 'totp_echec_code_recuperation', (string) $failCount);
                if ($failCount >= 3) {
                    $_SESSION = [];
                    @session_regenerate_id(true);
                    session_destroy();
                    session_start();
                    definir_flash('error', 'Trop de tentatives échouées. Veuillez vous reconnecter.');
                    redirect_to('pages/login.php');
                }
                $erreur = 'Code de récupération invalide. Il vous reste ' . (3 - $failCount) . ' tentative(s).';
            } else {
                $cleChiffre = $_SESSION['cle_chiffrement'] ?? null;
                unset($_SESSION['totp_pending'], $_SESSION['totp_user_id'], $_SESSION['totp_email'],
                    $_SESSION['totp_fail_count']);
                if ($cleChiffre !== null) {
                    $_SESSION['cle_chiffrement'] = $cleChiffre;
                }
                $_SESSION['user_id'] = $userId;
                $_SESSION['email'] = (string) ($_SESSION['totp_email'] ?? '');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['ajax_token'] = bin2hex(random_bytes(32));
                journaliser_action($userId, 'login_totp_par_code_recuperation', '');
                definir_flash('success', 'Connexion réussie. Pensez à générer de nouveaux codes de récupération.');
                redirect_to('pages/dashboard.php');
            }
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $erreur = 'Le code doit être composé de 6 chiffres.';
        } else {
            $secret = obtenir_secret_totp($userId);
            $slot = null;
            
            if ($secret === null) {
                $erreur = 'Secret TOTP introuvable.';
            } elseif (!verifier_code_totp($secret, $code, $slot)) {
                unset($_SESSION['cle_chiffrement']);
                $failCount++;
                $_SESSION['totp_fail_count'] = $failCount;
                journaliser_action($userId, 'totp_echec_code', (string) $failCount);
                if ($failCount >= 3) {
                    $_SESSION = [];
                    @session_regenerate_id(true);
                    session_destroy();
                    session_start();
                    definir_flash('error', 'Trop de tentatives échouées. Veuillez vous reconnecter.');
                    redirect_to('pages/login.php');
                }
                $erreur = 'Code TOTP invalide. Il vous reste ' . (3 - $failCount) . ' tentative(s).';
            } elseif ($slot !== null && !mettre_a_jour_last_totp_slot($userId, $slot)) {
                unset($_SESSION['cle_chiffrement']);
                $failCount++;
                $_SESSION['totp_fail_count'] = $failCount;
                journaliser_action($userId, 'totp_echec_anti_rejeu', 'slot ' . $slot);
                if ($failCount >= 3) {
                    $_SESSION = [];
                    @session_regenerate_id(true);
                    session_destroy();
                    session_start();
                    definir_flash('error', 'Trop de tentatives échouées. Veuillez vous reconnecter.');
                    redirect_to('pages/login.php');
                }
                $erreur = 'Code TOTP déjà utilisé (anti-rejeu). Attendez le prochain code (30s).';
            } else {
                $cleChiffre = $_SESSION['cle_chiffrement'] ?? null;
                unset($_SESSION['totp_pending'], $_SESSION['totp_user_id'], $_SESSION['totp_email'],
                    $_SESSION['totp_fail_count']);
                if ($cleChiffre !== null) {
                    $_SESSION['cle_chiffrement'] = $cleChiffre;
                }
                $_SESSION['user_id'] = $userId;
                $_SESSION['email'] = (string) ($_SESSION['totp_email'] ?? '');
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_SESSION['ajax_token'] = bin2hex(random_bytes(32));
                journaliser_action($userId, 'login_totp', '');
                definir_flash('success', 'Connexion réussie.');
                redirect_to('pages/dashboard.php');
            }
        }
    }
}

afficher_debut_page('Vérification TOTP');
?>
<section class="form-card">
    <h1>Vérification à deux facteurs</h1>

    <div class="alert alert-warning">
        Veuillez entrer le code TOTP de votre application d'authentification pour terminer la connexion.
    </div>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <p style="font-size:0.9rem;color:var(--muted);margin-bottom:12px;">
        Tentatives restantes : <strong><?= (3 - $failCount) ?></strong>
    </p>

    <form method="post" class="form" id="form-totp">
        <?= csrf_input() ?>
        <input type="hidden" name="mode_recovery" id="mode-recovery" value="0">
        
        <label for="code" id="label-code">Code TOTP</label>
        <input type="text" id="code" name="code" maxlength="24" pattern="[0-9A-Za-z\- ]+" placeholder="123456" required autocomplete="one-time-code" autofocus>

        <div class="form-actions">
            <button type="button" class="btn btn-secondary" id="btn-toggle-recovery">Utiliser un code de récupération</button>
            <button type="submit" class="btn btn-primary">Vérifier</button>
        </div>
    </form>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('code').addEventListener('input', function(e) {
    const mode = document.getElementById('mode-recovery').value;
    if (mode === '1') {
        this.value = this.value.replace(/[^0-9A-Za-z\- ]/g, '').toUpperCase().slice(0, 24);
    } else {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    }
});
document.getElementById('btn-toggle-recovery').addEventListener('click', function() {
    const mode = document.getElementById('mode-recovery');
    const label = document.getElementById('label-code');
    const code = document.getElementById('code');
    if (mode.value === '0') {
        mode.value = '1';
        this.textContent = 'Revenir au code TOTP';
        label.textContent = 'Code de récupération';
        code.placeholder = 'XXXX-XXXX-XXXX-XXXX';
        code.setAttribute('pattern', '[0-9A-Za-z\\- ]+');
        code.value = '';
        code.removeAttribute('maxlength');
        code.setAttribute('maxlength', '24');
    } else {
        mode.value = '0';
        this.textContent = 'Utiliser un code de récupération';
        label.textContent = 'Code TOTP';
        code.placeholder = '123456';
        code.setAttribute('pattern', '[0-9]{6}');
        code.value = '';
        code.setAttribute('maxlength', '6');
    }
});
</script>

<?php afficher_fin_page(); ?>

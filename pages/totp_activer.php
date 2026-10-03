<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';
require_once __DIR__ . '/../includes/qrcode.php';

exiger_authentification();

$userId = id_utilisateur_connecte();
$erreur = '';
$secret = '';
$qrCodeUrl = '';
$qrCodeImage = '';
$codesRecovery = $_SESSION['totp_recovery_codes_affichage'] ?? null;
$activationSucces = $codesRecovery !== null;

if ($activationSucces && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    unset($_SESSION['totp_recovery_codes_affichage']);
}

if (totp_actif_pour_utilisateur($userId) && !$activationSucces) {
    redirect_to('pages/totp_desactiver.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } elseif (isset($_POST['confirmer_codes'])) {
        $coche = isset($_POST['j_ai_sauvegarde']) && $_POST['j_ai_sauvegarde'] === '1';
        if (!$coche) {
            $erreur = 'Vous devez confirmer avoir sauvegardé les codes.';
        } else {
            definir_flash('success', 'TOTP activé avec succès.');
            redirect_to('pages/dashboard.php');
        }
    } else {
        $code = (string) ($_POST['code'] ?? '');
        
        if ($code === '') {
            $erreur = 'Veuillez entrer le code TOTP.';
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $erreur = 'Le code doit être composé de 6 chiffres.';
        } else {
            $tempSecret = $_SESSION['totp_temp_secret'] ?? '';
            
            if ($tempSecret === '') {
                $erreur = 'Session expirée. Veuillez recommencer.';
            } else {
                $slot = null;
                if (!verifier_code_totp($tempSecret, $code, $slot, 0)) {
                    $erreur = 'Code TOTP invalide.';
                } else {
                    if (activer_totp_pour_utilisateur($userId, $tempSecret, $slot)) {
                        unset($_SESSION['totp_temp_secret']);
                        journaliser_action($userId, 'totp_active', '');
                        $codesRecovery = $_SESSION['totp_recovery_codes_affichage'] ?? null;
                        if ($codesRecovery !== null) {
                            $activationSucces = true;
                            unset($_SESSION['totp_recovery_codes_affichage']);
                        } else {
                            definir_flash('success', 'TOTP activé avec succès.');
                            redirect_to('pages/dashboard.php');
                        }
                    } else {
                        $erreur = 'Erreur lors de l\'activation du TOTP.';
                    }
                }
            }
        }
    }
} elseif (!$activationSucces) {
    $secret = generer_secret_totp();
    $_SESSION['totp_temp_secret'] = $secret;
    $issuer = 'Gestionnaire MDP';
    $account = email_utilisateur_connecte();
    $qrCodeUrl = generer_url_totp($secret, $issuer, $account);
    $qrCodeImage = 'totp_qr.php';
}

if (!$activationSucces && !empty($_SESSION['totp_temp_secret'])) {
    $qrCodeImage = 'totp_qr.php';
}

afficher_debut_page('Activer TOTP');
?>

<?php if ($activationSucces && is_array($codesRecovery)): ?>
<section class="form-card">
    <h1>Codes de récupération TOTP</h1>

    <div class="alert alert-error">
        <strong>⚠️ Sauvegardez ces codes MAINTENANT !</strong>
        Ils ne seront affichés <strong>qu'une seule fois</strong>. Si vous les perdez, plus aucun moyen
        de vous connecter si vous perdez votre téléphone.
    </div>

    <div id="codes-liste" style="background:var(--panel-soft);padding:16px;border-radius:8px;font-family:monospace;display:grid;grid-template-columns:repeat(2,1fr);gap:8px 24px;margin:16px 0;">
        <?php foreach ($codesRecovery as $i => $c): ?>
            <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--muted);width:30px;"><?= ($i + 1) ?>.</span>
                <code style="letter-spacing:0.5px;"><?= e($c) ?></code>
            </div>
        <?php endforeach; ?>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin:16px 0;">
        <button type="button" class="btn btn-secondary" id="btn-copier-codes">📋 Copier tous les codes</button>
        <button type="button" class="btn btn-secondary" id="btn-telecharger-codes">⬇ Télécharger .txt</button>
    </div>

    <form method="post" class="form">
        <?= csrf_input() ?>
        <input type="hidden" name="confirmer_codes" value="1">

        <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
            <input type="checkbox" name="j_ai_sauvegarde" value="1" id="j_ai_sauvegarde" required style="margin-top:4px;">
            <span>J'ai sauvegardé ces codes dans un endroit sûr (gestionnaire de mots de passe, papier, etc.) et je comprends qu'ils ne seront plus réaffichés.</span>
        </label>

        <div class="form-actions" style="margin-top:20px;">
            <button type="submit" class="btn btn-primary" id="btn-confirmer" disabled>J'ai sauvegardé, continuer</button>
        </div>
    </form>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
(function() {
    const codes = <?= json_encode($codesRecovery, JSON_UNESCAPED_UNICODE) ?>;
    const texte = codes.map((c, i) => ((i+1) + '. ' + c)).join('\n');
    document.getElementById('btn-copier-codes').addEventListener('click', async function() {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(texte);
            } else {
                const ta = document.createElement('textarea');
                ta.value = texte; ta.style.position='fixed'; ta.style.left='-9999px';
                document.body.appendChild(ta); ta.select();
                document.execCommand('copy'); ta.remove();
            }
            const orig = this.textContent;
            this.textContent = '✅ Copié !';
            setTimeout(() => this.textContent = orig, 2000);
        } catch (e) {
            alert('Copie impossible');
        }
    });
    document.getElementById('btn-telecharger-codes').addEventListener('click', function() {
        const blob = new Blob([texte], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = 'totp-codes-recuperation.txt';
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    document.getElementById('j_ai_sauvegarde').addEventListener('change', function() {
        document.getElementById('btn-confirmer').disabled = !this.checked;
    });
})();
</script>
<?php else: ?>
<section class="form-card">
    <h1>Activer l'authentification à deux facteurs (TOTP)</h1>

    <div class="alert alert-warning">
        <strong>Important :</strong> Scannez ce QR code avec votre application d'authentification (Google Authenticator, Authy, etc.) avant de continuer.
    </div>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <?php if ($qrCodeUrl !== ''): ?>
        <div style="text-align: center; margin: 20px 0;">
            <?php if ($qrCodeImage !== ''): ?>
                <img src="<?= e($qrCodeImage) ?>" width="245" height="245" alt="QR Code TOTP" style="background:#fff;border:1px solid var(--line);border-radius:8px;padding:10px;image-rendering:pixelated;">
            <?php else: ?>
                <div class="alert alert-warning">QR code indisponible localement. Utilisez le secret ci-dessous.</div>
            <?php endif; ?>
            <p style="margin-top: 10px; color: var(--muted); font-size: 0.9rem;">
                Secret (en cas de problème de scan) : <code style="background: var(--panel-soft); padding: 4px 8px; border-radius: 4px;"><?= e($secret) ?></code>
            </p>
        </div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>
        
        <label for="code">Code TOTP</label>
        <input type="text" id="code" name="code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required autocomplete="one-time-code">
        <small>Entrez le code à 6 chiffres affiché par votre application d'authentification.</small>

        <div class="form-actions">
            <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary">Activer TOTP</button>
        </div>
    </form>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('code').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
});
</script>
<?php endif; ?>

<?php afficher_fin_page(); ?>

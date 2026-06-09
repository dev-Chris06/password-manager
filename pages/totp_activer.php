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

if (totp_actif_pour_utilisateur($userId)) {
    redirect_to('pages/totp_desactiver.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
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
            } elseif (!verifier_code_totp($tempSecret, $code)) {
                $erreur = 'Code TOTP invalide.';
            } else {
                if (activer_totp_pour_utilisateur($userId, $tempSecret)) {
                    unset($_SESSION['totp_temp_secret']);
                    journaliser_action($userId, 'totp_active', '');
                    definir_flash('success', 'TOTP activé avec succès.');
                    redirect_to('pages/dashboard.php');
                }
                $erreur = 'Erreur lors de l\'activation du TOTP.';
            }
        }
    }
} else {
    $secret = generer_secret_totp();
    $_SESSION['totp_temp_secret'] = $secret;
    $issuer = 'Gestionnaire MDP';
    $account = email_utilisateur_connecte();
    $qrCodeUrl = generer_url_totp($secret, $issuer, $account);
    try {
        $qrCodeImage = QRCodeGenerator::generateSvgDataUri($qrCodeUrl);
    } catch (Throwable) {
        $qrCodeImage = '';
    }
}

afficher_debut_page('Activer TOTP');
?>
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
                <img src="<?= e($qrCodeImage) ?>" alt="QR Code TOTP" style="border: 1px solid var(--line); border-radius: 8px; padding: 10px;">
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

<script>
document.getElementById('code').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
});
</script>

<?php afficher_fin_page(); ?>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/totp.php';

exiger_authentification();

$userId = id_utilisateur_connecte();
$erreur = '';

if (!totp_actif_pour_utilisateur($userId)) {
    definir_flash('error', 'TOTP n\'est pas activé pour votre compte.');
    redirect_to('pages/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $code = (string) ($_POST['code'] ?? '');
        
        if ($code === '') {
            $erreur = 'Veuillez entrer le code TOTP pour confirmer la désactivation.';
        } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
            $erreur = 'Le code doit être composé de 6 chiffres.';
        } else {
            $secret = obtenir_secret_totp($userId);
            
            if ($secret === null) {
                $erreur = 'Secret TOTP introuvable.';
            } elseif (!verifier_code_totp($secret, $code)) {
                $erreur = 'Code TOTP invalide.';
            } else {
                if (desactiver_totp_pour_utilisateur($userId)) {
                    journaliser_action($userId, 'totp_desactive', '');
                    definir_flash('success', 'TOTP désactivé avec succès.');
                    redirect_to('pages/dashboard.php');
                }
                $erreur = 'Erreur lors de la désactivation du TOTP.';
            }
        }
    }
}

afficher_debut_page('Désactiver TOTP');
?>
<section class="form-card">
    <h1>Désactiver l'authentification à deux facteurs (TOTP)</h1>

    <div class="alert alert-warning">
        <strong>Attention :</strong> La désactivation du TOTP réduira la sécurité de votre compte. Vous devrez confirmer avec votre code TOTP actuel.
    </div>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>
        
        <label for="code">Code TOTP actuel</label>
        <input type="text" id="code" name="code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required autocomplete="one-time-code">
        <small>Entrez le code à 6 chiffres de votre application d'authentification pour confirmer la désactivation.</small>

        <div class="form-actions">
            <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary">Désactiver TOTP</button>
        </div>
    </form>
</section>

<script>
document.getElementById('code').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
});
</script>

<?php afficher_fin_page(); ?>

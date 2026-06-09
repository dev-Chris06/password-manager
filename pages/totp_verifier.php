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

$userId = $_SESSION['totp_user_id'];

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
            $secret = obtenir_secret_totp($userId);
            
            if ($secret === null) {
                $erreur = 'Secret TOTP introuvable.';
            } elseif (!verifier_code_totp($secret, $code)) {
                $erreur = 'Code TOTP invalide.';
            } else {
                unset($_SESSION['totp_pending']);
                unset($_SESSION['totp_user_id']);
                $_SESSION['user_id'] = $userId;
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

    <form method="post" class="form">
        <?= csrf_input() ?>
        
        <label for="code">Code TOTP</label>
        <input type="text" id="code" name="code" maxlength="6" pattern="[0-9]{6}" placeholder="123456" required autocomplete="one-time-code" autofocus>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Vérifier</button>
        </div>
    </form>
</section>

<script>
document.getElementById('code').addEventListener('input', function(e) {
    this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
});
</script>

<?php afficher_fin_page(); ?>

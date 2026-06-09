<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (utilisateur_connecte()) {
    redirect_to('pages/dashboard.php');
}

$erreur = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $email = normaliser_email((string) ($_POST['email'] ?? ''));
        $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
        $resultat = connecter_utilisateur($email, $motDePasse);

        if ((bool) $resultat['ok']) {
            if (($resultat['totp_required'] ?? false) === true) {
                redirect_to('pages/totp_verifier.php');
            }
            redirect_to('pages/dashboard.php');
        }

        $erreur = (string) $resultat['message'];
    }
}

$flash = recuperer_flash();
afficher_debut_page('Connexion');
?>
<section class="auth-card">
    <h1>Connexion</h1>
    <p class="muted">Accédez à votre coffre chiffré.</p>

    <?php afficher_flash($flash); ?>
    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>

        <label for="mot_de_passe">Mot de passe maître</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="current-password" required>

        <button type="submit" class="btn btn-primary">Se connecter</button>
    </form>
</section>
<?php afficher_fin_page(); ?>

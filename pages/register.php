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
        $confirmation = (string) ($_POST['confirmation'] ?? '');

        if ($motDePasse !== $confirmation || !inscrire_utilisateur($email, $motDePasse)) {
            $erreur = 'Inscription impossible. Vérifiez les informations saisies.';
        } else {
            definir_flash('success', 'Compte créé. Vous pouvez vous connecter.');
            redirect_to('pages/login.php');
        }
    }
}

afficher_debut_page('Inscription');
?>
<section class="auth-card">
    <h1>Inscription</h1>
    <p class="muted">Créez votre coffre avec un mot de passe maître robuste.</p>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>

        <label for="email">Email</label>
        <input type="email" id="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>

        <label for="mot_de_passe">Mot de passe maître</label>
        <input type="password" id="mot_de_passe" name="mot_de_passe" minlength="12" autocomplete="new-password" required>
        <small>Minimum 12 caractères.</small>

        <label for="confirmation">Confirmation</label>
        <input type="password" id="confirmation" name="confirmation" minlength="12" autocomplete="new-password" required>

        <button type="submit" class="btn btn-primary">Créer le compte</button>
    </form>
</section>
<?php afficher_fin_page(); ?>

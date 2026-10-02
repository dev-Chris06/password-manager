<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (utilisateur_connecte()) {
    redirect_to('pages/dashboard.php');
}

$erreur = '';
$email = '';
$blocageSecondes = 0;

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
        if (!empty($resultat['bloque'])) {
            $blocageSecondes = (int) ($resultat['secondes_restantes'] ?? 0);
        }
    }
}

// Si pas de blocage remonté dans le résultat (ex: GET avant échec),
// on prévient quand même en vérifiant le verrou explicite actuel pour l'email éventuel.
if ($blocageSecondes === 0 && $email !== '' && function_exists('statut_verrou_login')) {
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $st = statut_verrou_login($email, $ip);
    if ($st['bloque']) {
        $blocageSecondes = (int) $st['secondes_restantes'];
        if ($erreur === '') {
            $erreur = 'Compte temporairement bloqué. Réessayez dans ' . $blocageSecondes . ' seconde(s).';
        }
    }
}

$champsDesactives = $blocageSecondes > 0;

$flash = recuperer_flash();
afficher_debut_page('Connexion');
?>
<section
    class="auth-card"
    data-login-blocked-seconds="<?= (int) $blocageSecondes ?>"
>
    <h1>Connexion</h1>
    <p class="muted">Accédez à votre coffre chiffré.</p>

    <?php afficher_flash($flash); ?>
    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error" id="login-error-message"><?= e($erreur) ?></div>
    <?php else: ?>
        <div class="alert alert-error" id="login-error-message" hidden></div>
    <?php endif; ?>

    <form method="post" class="form" id="login-form">
        <?= csrf_input() ?>

        <label for="email">Email</label>
        <input
            type="email"
            id="email"
            name="email"
            value="<?= e($email) ?>"
            autocomplete="email"
            required
            <?= $champsDesactives ? 'disabled aria-disabled="true"' : '' ?>
        >

        <label for="mot_de_passe">Mot de passe maître</label>
        <input
            type="password"
            id="mot_de_passe"
            name="mot_de_passe"
            autocomplete="current-password"
            required
            <?= $champsDesactives ? 'disabled aria-disabled="true"' : '' ?>
        >

        <button
            type="submit"
            class="btn btn-primary"
            id="login-submit"
            <?= $champsDesactives ? 'disabled aria-disabled="true"' : '' ?>
        >Se connecter</button>
    </form>
</section>
<?php afficher_fin_page('assets/js/login_lock.js'); ?>

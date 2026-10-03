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
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        $statut = statut_verrou_inscription_ip($ip);
        if ($statut['bloque']) {
            $secondes = max(0, (int) $statut['secondes_restantes']);
            $erreur = 'Inscription temporairement indisponible depuis votre réseau. Réessayez dans ' . $secondes . ' seconde(s).';
        } elseif ($motDePasse !== $confirmation || !inscrire_utilisateur($email, $motDePasse)) {
            enregistrer_tentative_inscription($ip);
            $cpt = compter_tentatives_inscription_dans_fenetre($ip);
            $seuil = defined('INSCRIPTION_SEUIL_IP_MAX_1H') ? (int) INSCRIPTION_SEUIL_IP_MAX_1H : 5;
            if ($cpt >= $seuil) {
                poser_verrou_inscription_ip($ip);
            }
            $erreur = 'Inscription impossible. Vérifiez les informations saisies.';
        } else {
            supprimer_verrou_inscription_ip($ip);
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

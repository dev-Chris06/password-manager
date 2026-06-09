<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

exiger_authentification();

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $ancien = (string) ($_POST['ancien_mdp'] ?? '');
        $nouveau = (string) ($_POST['nouveau_mdp'] ?? '');
        $confirmation = (string) ($_POST['confirmation'] ?? '');

        if ($nouveau !== $confirmation || !changer_mot_de_passe_maitre(id_utilisateur_connecte(), $ancien, $nouveau)) {
            $erreur = 'Changement impossible. Vérifiez les informations saisies.';
        } else {
            definir_flash('success', 'Mot de passe maître changé et entrées rechiffrées.');
            redirect_to('pages/dashboard.php');
        }
    }
}

afficher_debut_page('Changer le mot de passe maître');
?>
<section class="form-card">
    <h1>Mot de passe maître</h1>
    <p class="muted">Toutes les entrées sont rechiffrées avec une nouvelle clé dérivée.</p>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>

        <label for="ancien_mdp">Mot de passe actuel</label>
        <input type="password" id="ancien_mdp" name="ancien_mdp" autocomplete="current-password" required>

        <label for="nouveau_mdp">Nouveau mot de passe maître</label>
        <input type="password" id="nouveau_mdp" name="nouveau_mdp" minlength="12" autocomplete="new-password" required>

        <label for="confirmation">Confirmation</label>
        <input type="password" id="confirmation" name="confirmation" minlength="12" autocomplete="new-password" required>

        <div class="form-actions">
            <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary">Changer</button>
        </div>
    </form>
</section>
<?php afficher_fin_page(); ?>

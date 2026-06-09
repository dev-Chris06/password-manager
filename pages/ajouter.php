<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

exiger_authentification();

$erreur = '';
$categorie = 'Autre';
$site = '';
$identifiant = '';
$confirmationDoublon = '';
$forcerDoublon = false;
$confirmationToken = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $forcerDoublon = (bool) ($_POST['forcer_doublon'] ?? '0');
        $confirmationToken = (string) ($_POST['confirmation_token'] ?? '');

        if ($forcerDoublon) {
            $pending = $_SESSION['confirmation_doublon_ajout'] ?? null;
            if (
                !is_array($pending) ||
                !hash_equals((string) ($pending['token'] ?? ''), $confirmationToken)
            ) {
                $erreur = 'Confirmation expirée. Veuillez recommencer.';
            } else {
                $categorie = (string) $pending['categorie'];
                $site = (string) $pending['site'];
                $identifiant = (string) $pending['identifiant'];
                $motDePasse = (string) $pending['mot_de_passe'];
            }
        } else {
            $categorie = (string) ($_POST['categorie'] ?? 'Autre');
            $site = (string) ($_POST['site'] ?? '');
            $identifiant = (string) ($_POST['identifiant'] ?? '');
            $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
        }

        if ($erreur === '') {
            $resultat = ajouter_entree(id_utilisateur_connecte(), $categorie, $site, $identifiant, $motDePasse, $forcerDoublon);

            if ($resultat['ok']) {
                unset($_SESSION['confirmation_doublon_ajout']);
                definir_flash('success', 'Entrée ajoutée.');
                redirect_to('pages/dashboard.php');
            }

            if (($resultat['doublon'] ?? false) === true) {
                $confirmationDoublon = $resultat['warning'];
                $confirmationToken = bin2hex(random_bytes(16));
                $_SESSION['confirmation_doublon_ajout'] = [
                    'token' => $confirmationToken,
                    'categorie' => $categorie,
                    'site' => $site,
                    'identifiant' => $identifiant,
                    'mot_de_passe' => $motDePasse,
                ];
            } else {
                $erreur = 'Ajout impossible. Vérifiez les informations saisies.';
            }
        }
    }
}

afficher_debut_page('Ajouter');
?>
<section class="form-card">
    <h1>Ajouter une entrée</h1>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <?php if ($confirmationDoublon !== ''): ?>
        <div class="alert alert-warning"><?= e($confirmationDoublon) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>

        <?php if ($confirmationDoublon !== ''): ?>
            <input type="hidden" name="forcer_doublon" value="1">
            <input type="hidden" name="confirmation_token" value="<?= e($confirmationToken) ?>">

            <label class="checkbox-label">
                <input type="checkbox" name="confirmer_doublon" required>
                Je comprends le risque de réutilisation et je confirme l'enregistrement.
            </label>

            <div class="form-actions">
                <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
                <button type="submit" class="btn btn-warning">Confirmer l'enregistrement</button>
            </div>
        <?php else: ?>
            <label for="categorie">Catégorie</label>
            <select id="categorie" name="categorie" required>
                <?php foreach (categories_autorisees() as $option): ?>
                    <option value="<?= e($option) ?>" <?= $categorie === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="site">Site</label>
            <input type="text" id="site" name="site" value="<?= e($site) ?>" maxlength="255" required>

            <label for="identifiant">Identifiant</label>
            <input type="text" id="identifiant" name="identifiant" value="<?= e($identifiant) ?>" maxlength="255" required>

            <div class="password-row">
                <label for="mot_de_passe">Mot de passe</label>
                <button type="button" class="btn btn-small btn-secondary" id="generer-mdp">Générer</button>
            </div>
            <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="new-password" data-strength="strength-container" required>
            <div id="strength-container"></div>

            <div class="form-actions">
                <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        <?php endif; ?>
    </form>
</section>
<?php afficher_fin_page('assets/js/generateur.js', 'assets/js/force_mdp.js'); ?>

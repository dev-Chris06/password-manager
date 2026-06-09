<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

exiger_authentification();

$userId = id_utilisateur_connecte();
$entryId = (int) ($_GET['id'] ?? $_POST['entry_id'] ?? 0);
$entry = $entryId > 0 ? trouver_entree($userId, $entryId) : null;

if (!is_array($entry)) {
    definir_flash('error', 'Entrée introuvable.');
    redirect_to('pages/dashboard.php');
}

$erreur = '';
$confirmationDoublon = '';
$forcerDoublon = false;
$confirmationToken = '';
$categorie = (string) $entry['categorie'];
$site = (string) $entry['site'];
$identifiant = (string) $entry['identifiant'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        $erreur = 'Requête invalide.';
    } else {
        $forcerDoublon = (bool) ($_POST['forcer_doublon'] ?? '0');
        $confirmationToken = (string) ($_POST['confirmation_token'] ?? '');

        if ($forcerDoublon) {
            $pending = $_SESSION['confirmation_doublon_modif'][$entryId] ?? null;
            if (
                !is_array($pending) ||
                !hash_equals((string) ($pending['token'] ?? ''), $confirmationToken)
            ) {
                $erreur = 'Confirmation expirée. Veuillez recommencer.';
            } else {
                $categorie = (string) $pending['categorie'];
                $site = (string) $pending['site'];
                $identifiant = (string) $pending['identifiant'];
                $nouveauMotDePasse = (string) $pending['mot_de_passe'];
            }
        } else {
            $categorie = (string) ($_POST['categorie'] ?? 'Autre');
            $site = (string) ($_POST['site'] ?? '');
            $identifiant = (string) ($_POST['identifiant'] ?? '');
            $nouveauMotDePasse = (string) ($_POST['mot_de_passe'] ?? '');
        }

        if ($erreur === '') {
            $resultat = modifier_entree($userId, $entryId, $categorie, $site, $identifiant, $nouveauMotDePasse, $forcerDoublon);

            if ($resultat['ok']) {
                unset($_SESSION['confirmation_doublon_modif'][$entryId]);
                definir_flash('success', 'Entrée modifiée.');
                redirect_to('pages/dashboard.php');
            }

            if (($resultat['doublon'] ?? false) === true) {
                $confirmationDoublon = $resultat['warning'];
                $confirmationToken = bin2hex(random_bytes(16));
                $_SESSION['confirmation_doublon_modif'][$entryId] = [
                    'token' => $confirmationToken,
                    'categorie' => $categorie,
                    'site' => $site,
                    'identifiant' => $identifiant,
                    'mot_de_passe' => $nouveauMotDePasse,
                ];
            } else {
                $erreur = 'Modification impossible.';
            }
        }
    }
}

afficher_debut_page('Modifier');
?>
<section class="form-card">
    <h1>Modifier une entrée</h1>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <?php if ($confirmationDoublon !== ''): ?>
        <div class="alert alert-warning"><?= e($confirmationDoublon) ?></div>
    <?php endif; ?>

    <form method="post" class="form">
        <?= csrf_input() ?>
        <input type="hidden" name="entry_id" value="<?= $entryId ?>">

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
                <label for="mot_de_passe">Nouveau mot de passe</label>
                <button type="button" class="btn btn-small btn-secondary" id="generer-mdp">Générer</button>
            </div>
            <input type="password" id="mot_de_passe" name="mot_de_passe" autocomplete="new-password" data-strength="strength-container">
            <div id="strength-container"></div>
            <small>Laissez vide pour conserver le mot de passe chiffré actuel.</small>

            <div class="form-actions">
                <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
        <?php endif; ?>
    </form>
</section>
<?php afficher_fin_page('assets/js/generateur.js', 'assets/js/force_mdp.js'); ?>

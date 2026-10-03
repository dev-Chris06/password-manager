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
        $mdpActuel = (string) ($_POST['mot_de_passe_actuel'] ?? '');
        $code = (string) ($_POST['code'] ?? '');
        $modeRecovery = isset($_POST['mode_recovery']) && $_POST['mode_recovery'] === '1';

        if ($mdpActuel === '') {
            $erreur = 'Veuillez saisir votre mot de passe maître actuel.';
        } else {
            $hashMdp = obtenir_hash_mdp_utilisateur($userId);
            if ($hashMdp === null || !password_verify($mdpActuel, $hashMdp)) {
                $erreur = 'Mot de passe maître incorrect.';
                journaliser_action($userId, 'totp_desactivation_echec', 'mdp_incorrect');
            } elseif ($code === '') {
                $erreur = $modeRecovery
                    ? 'Veuillez entrer un code de récupération.'
                    : 'Veuillez entrer le code TOTP pour confirmer la désactivation.';
            } elseif ($modeRecovery) {
                $ok = utiliser_code_recuperation_totp($userId, $code);
                if (!$ok) {
                    $erreur = 'Code de récupération invalide.';
                    journaliser_action($userId, 'totp_desactivation_echec', 'code_recuperation_invalide');
                } else {
                    if (desactiver_totp_pour_utilisateur($userId)) {
                        journaliser_action($userId, 'totp_desactive', 'via_code_recuperation');
                        definir_flash('success', 'TOTP désactivé avec succès.');
                        redirect_to('pages/dashboard.php');
                    }
                    $erreur = 'Erreur lors de la désactivation du TOTP.';
                }
            } elseif (strlen($code) !== 6 || !ctype_digit($code)) {
                $erreur = 'Le code doit être composé de 6 chiffres.';
            } else {
                $secret = obtenir_secret_totp($userId);

                if ($secret === null) {
                    $erreur = 'Secret TOTP introuvable.';
                } else {
                    $slot = null;
                    if (!verifier_code_totp($secret, $code, $slot)) {
                        $erreur = 'Code TOTP invalide.';
                        journaliser_action($userId, 'totp_desactivation_echec', 'code_totp_invalide');
                    } elseif ($slot !== null && !mettre_a_jour_last_totp_slot($userId, $slot)) {
                        $erreur = 'Code TOTP déjà utilisé (anti-rejeu). Attendez le prochain code (30s).';
                        journaliser_action($userId, 'totp_desactivation_echec', 'anti_rejeu slot=' . $slot);
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
    }
}

afficher_debut_page('Désactiver TOTP');
?>
<section class="form-card">
    <h1>Désactiver l'authentification à deux facteurs (TOTP)</h1>

    <div class="alert alert-warning">
        <strong>Attention :</strong> La désactivation du TOTP réduira la sécurité de votre compte.
        Pour confirmer, vous devrez saisir <strong>à la fois</strong> votre mot de passe maître actuel
        <em>et</em> un code TOTP (ou un code de récupération).
    </div>

    <?php if ($erreur !== ''): ?>
        <div class="alert alert-error"><?= e($erreur) ?></div>
    <?php endif; ?>

    <form method="post" class="form" id="form-totp-desactiver">
        <?= csrf_input() ?>
        <input type="hidden" name="mode_recovery" id="mode-recovery" value="0">

        <label for="mot_de_passe_actuel">Mot de passe maître actuel</label>
        <input type="password" id="mot_de_passe_actuel" name="mot_de_passe_actuel" minlength="12" placeholder="Votre mot de passe maître" required autocomplete="current-password">
        <small>Requis pour toute action sensible sur votre compte.</small>

        <label for="code" id="label-code" style="margin-top:12px;">Code TOTP actuel</label>
        <input type="text" id="code" name="code" maxlength="24" pattern="[0-9A-Za-z\- ]+" placeholder="123456" required autocomplete="one-time-code">
        <small id="code-help">Entrez le code à 6 chiffres de votre application d'authentification pour confirmer la désactivation.</small>

        <div class="form-actions">
            <button type="button" class="btn btn-secondary" id="btn-toggle-recovery">Utiliser un code de récupération</button>
            <a class="btn btn-secondary" href="<?= e(url_app('pages/dashboard.php')) ?>">Annuler</a>
            <button type="submit" class="btn btn-primary">Désactiver TOTP</button>
        </div>
    </form>
</section>

<script nonce="<?= e(csp_nonce()) ?>">
document.getElementById('code').addEventListener('input', function(e) {
    const mode = document.getElementById('mode-recovery').value;
    if (mode === '1') {
        this.value = this.value.replace(/[^0-9A-Za-z\- ]/g, '').toUpperCase().slice(0, 24);
    } else {
        this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6);
    }
});
document.getElementById('btn-toggle-recovery').addEventListener('click', function() {
    const mode = document.getElementById('mode-recovery');
    const label = document.getElementById('label-code');
    const code = document.getElementById('code');
    const help = document.getElementById('code-help');
    if (mode.value === '0') {
        mode.value = '1';
        this.textContent = 'Revenir au code TOTP';
        label.textContent = 'Code de récupération';
        help.textContent = 'Entrez un des 10 codes de récupération affichés lors de l\'activation du TOTP.';
        code.placeholder = 'XXXX-XXXX-XXXX-XXXX';
        code.setAttribute('pattern', '[0-9A-Za-z\\- ]+');
        code.value = '';
        code.removeAttribute('maxlength');
        code.setAttribute('maxlength', '24');
    } else {
        mode.value = '0';
        this.textContent = 'Utiliser un code de récupération';
        label.textContent = 'Code TOTP actuel';
        help.textContent = 'Entrez le code à 6 chiffres de votre application d\'authentification pour confirmer la désactivation.';
        code.placeholder = '123456';
        code.setAttribute('pattern', '[0-9]{6}');
        code.value = '';
        code.setAttribute('maxlength', '6');
    }
});
</script>

<?php afficher_fin_page(); ?>

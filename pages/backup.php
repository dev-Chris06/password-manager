<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

exiger_authentification();

afficher_debut_page('Sauvegarde du coffre');
?>
<section class="form-card">
    <h1>Sauvegarde du coffre</h1>

    <div class="alert alert-warning">
        <strong>Important :</strong> Le fichier de sauvegarde est chiffré avec votre mot de passe maître actuel. 
        Seul le mot de passe maître correct permet de le déchiffrer.
    </div>

    <h2>Exporter le coffre</h2>
    <form id="export-form" class="form">
        <?= csrf_input() ?>
        <button type="submit" class="btn btn-primary" id="btn-export">Télécharger la sauvegarde</button>
    </form>

    <hr>

    <h2>Importer une sauvegarde</h2>
    <form id="import-form" class="form">
        <?= csrf_input() ?>
        <label for="backup-file">Fichier de sauvegarde (.json)</label>
        <div class="file-upload-wrapper">
            <input type="file" id="backup-file" name="backup_file" accept=".json" required hidden>
            <label for="backup-file" class="file-upload-label">
                <span class="file-upload-btn">Choisir un fichier</span>
                <span class="file-upload-name" id="backup-file-name">Aucun fichier choisi</span>
            </label>
        </div>
        
        <div class="form-actions">
            <button type="submit" class="btn btn-primary" id="btn-import">Importer</button>
        </div>
    </form>

    <div id="import-result" class="alert alert-hidden"></div>
</section>

<script>
window.APP_BASE_URL = '<?= e(APP_URL) ?>';
</script>

<?php afficher_fin_page('assets/js/backup.js'); ?>

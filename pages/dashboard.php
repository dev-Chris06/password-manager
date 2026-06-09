<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

exiger_authentification();

$userId = id_utilisateur_connecte();
$categorieFiltre = isset($_GET['categorie']) ? (string) $_GET['categorie'] : '';
$categorieActive = categorie_valide($categorieFiltre) ? $categorieFiltre : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
        definir_flash('error', 'Requête invalide.');
        redirect_to('pages/dashboard.php');
    }

    $action = (string) ($_POST['action'] ?? '');
    $entryId = (int) ($_POST['entry_id'] ?? 0);

    if ($action === 'supprimer' && $entryId > 0 && supprimer_entree($userId, $entryId)) {
        definir_flash('success', 'Entrée supprimée.');
    } else {
        definir_flash('error', 'Action impossible.');
    }

    redirect_to('pages/dashboard.php');
}

$entrees = lister_entrees($userId, $categorieActive);
$flash = recuperer_flash();

afficher_debut_page('Dashboard');
?>
<section class="dashboard-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted">Connecté avec <?= e(email_utilisateur_connecte()) ?></p>
    </div>
    <a class="btn btn-primary" href="<?= e(url_app('pages/ajouter.php')) ?>">Ajouter une entrée</a>
</section>

<?php afficher_flash($flash); ?>

<section class="panel">
    <form method="get" class="filter-bar">
        <label for="categorie">Catégorie</label>
        <select id="categorie" name="categorie">
            <option value="">Toutes</option>
            <?php foreach (categories_autorisees() as $categorie): ?>
                <option value="<?= e($categorie) ?>" <?= $categorieActive === $categorie ? 'selected' : '' ?>>
                    <?= e($categorie) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary">Filtrer</button>
    </form>

    <div class="table-wrap">
        <table class="entries-table">
            <thead>
                <tr>
                    <th>Catégorie</th>
                    <th>Site</th>
                    <th>Identifiant</th>
                    <th>Mis à jour</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($entrees === []): ?>
                <tr>
                    <td colspan="5" class="empty">Aucune entrée.</td>
                </tr>
            <?php endif; ?>
            <?php foreach ($entrees as $entry): ?>
                <tr>
                    <td><span class="badge"><?= e((string) $entry['categorie']) ?></span></td>
                    <td><?= e((string) $entry['site']) ?></td>
                    <td><?= e((string) $entry['identifiant']) ?></td>
                    <td><?= e((string) $entry['updated_at']) ?></td>
                    <td class="actions">
                        <button
                            type="button"
                            class="btn btn-small btn-copy"
                            data-entry-id="<?= (int) $entry['id'] ?>"
                            data-csrf="<?= e(csrf_token()) ?>"
                        >Copier</button>
                        <a class="btn btn-small btn-secondary" href="<?= e(url_app('pages/modifier.php?id=' . (int) $entry['id'])) ?>">Modifier</a>
                        <form method="post" class="inline-form" data-confirm="Supprimer cette entrée ?">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="supprimer">
                            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
                            <button type="submit" class="btn btn-small btn-danger">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php afficher_fin_page('assets/js/dashboard.js'); ?>

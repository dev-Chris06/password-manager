<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

exiger_authentification();

$userId = id_utilisateur_connecte();
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$parPage = 50;
$offset = ($page - 1) * $parPage;

$pdo = get_pdo();
$stmtCount = $pdo->prepare('SELECT COUNT(*) as total FROM journal_actions WHERE user_id = :user_id');
$stmtCount->execute(['user_id' => $userId]);
$total = (int) $stmtCount->fetch()['total'];
$totalPages = (int) ceil($total / $parPage);

$stmt = $pdo->prepare(
    'SELECT id, action, detail, ip, user_agent, created_at
     FROM journal_actions
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT :limit OFFSET :offset'
);
$stmt->bindValue('user_id', $userId, PDO::PARAM_INT);
$stmt->bindValue('limit', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll();

afficher_debut_page('Journal des actions');

$flash = recuperer_flash();
afficher_flash($flash);
?>

<h1>Journal des actions</h1>

<?php if ($total === 0): ?>
    <p>Aucune action journalisée.</p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Date/heure</th>
                    <th>Action</th>
                    <th>Détail</th>
                    <th>IP</th>
                    <th>Navigateur</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($entries as $entry): ?>
                    <tr>
                        <td><?= e($entry['created_at']) ?></td>
                        <td><?= e($entry['action']) ?></td>
                        <td><?= e($entry['detail']) ?></td>
                        <td><?= e($entry['ip']) ?></td>
                        <td><?= e(substr($entry['user_agent'], 0, 50)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>" class="btn">← Précédent</a>
            <?php endif; ?>
            
            <span>Page <?= $page ?> sur <?= $totalPages ?></span>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>" class="btn">Suivant →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<p class="mt-4">
    <small>Les entrées sont conservées pendant <?= JOURNAL_RETENTION_DAYS ?> jours.</small>
</p>

<?php afficher_fin_page(); ?>

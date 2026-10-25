<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';
require_once __DIR__ . '/../includes/domain.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function reponse_json_remplissage(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    reponse_json_remplissage(['ok' => false, 'message' => 'Méthode invalide.'], 405);
}

utiliser_session_extension_si_presente();

if (!utilisateur_connecte()) {
    reponse_json_remplissage(['ok' => false, 'message' => 'Session expirée.'], 401);
}

$domaine = normaliser_domaine_site((string) ($_GET['domaine'] ?? ''));

if ($domaine === '') {
    reponse_json_remplissage(['ok' => false, 'message' => 'Domaine invalide.'], 400);
}

$pdo = get_pdo();
$stmt = $pdo->prepare(
    "SELECT id, categorie, site, identifiant, updated_at
     FROM entrees
     WHERE user_id = :user_id
     ORDER BY updated_at DESC, id DESC"
);
$stmt->execute(['user_id' => id_utilisateur_connecte()]);

$entries = [];
foreach ($stmt->fetchAll() as $entry) {
    if (!site_correspond_au_domaine((string) $entry['site'], $domaine)) {
        continue;
    }

    $entries[] = [
        'id' => (int) $entry['id'],
        'categorie' => (string) $entry['categorie'],
        'site' => (string) $entry['site'],
        'identifiant' => (string) $entry['identifiant'],
        'updated_at' => (string) $entry['updated_at'],
    ];

    if (count($entries) >= 20) {
        break;
    }
}

reponse_json_remplissage([
    'ok' => true,
    'domain' => $domaine,
    'csrf_token' => csrf_token(),
    'ajax_token' => (string) ($_SESSION['ajax_token'] ?? ''),
    'entries' => $entries,
]);

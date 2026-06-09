<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function reponse_json_remplissage(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function normaliser_domaine_extension(string $value): string
{
    $value = strtolower(trim($value));

    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '://')) {
        $value = 'http://' . $value;
    }

    $host = parse_url($value, PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        return '';
    }

    $host = preg_replace('/:\d+$/', '', $host) ?? '';
    $host = preg_replace('/^www\./', '', $host) ?? '';
    $host = trim($host, ". \t\n\r\0\x0B");

    if ($host === '' || strlen($host) > 253) {
        return '';
    }

    return preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : '';
}

function echapper_like_sql(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    reponse_json_remplissage(['ok' => false, 'message' => 'Méthode invalide.'], 405);
}

utiliser_session_extension_si_presente();

if (!utilisateur_connecte()) {
    reponse_json_remplissage(['ok' => false, 'message' => 'Session expirée.'], 401);
}

$domaine = normaliser_domaine_extension((string) ($_GET['domaine'] ?? ''));

if ($domaine === '') {
    reponse_json_remplissage(['ok' => false, 'message' => 'Domaine invalide.'], 400);
}

$pdo = get_pdo();
$likeDomaine = '%' . echapper_like_sql($domaine) . '%';
$stmt = $pdo->prepare(
    "SELECT id, categorie, site, identifiant, updated_at,
        CASE
            WHEN LOWER(site) = :domaine_exact THEN 100
            WHEN LOWER(site) LIKE :like_domaine ESCAPE '\\\\' THEN 50
            WHEN :domaine LIKE CONCAT('%', LOWER(site), '%') THEN 30
            ELSE 0
        END AS pertinence
     FROM entrees
     WHERE user_id = :user_id
       AND (
           LOWER(site) LIKE :like_domaine ESCAPE '\\\\'
           OR :domaine LIKE CONCAT('%', LOWER(site), '%')
       )
     ORDER BY pertinence DESC, updated_at DESC, id DESC
     LIMIT 20"
);
$stmt->execute([
    'user_id' => id_utilisateur_connecte(),
    'like_domaine' => $likeDomaine,
    'domaine' => $domaine,
    'domaine_exact' => $domaine,
]);

$entries = [];
foreach ($stmt->fetchAll() as $entry) {
    $entries[] = [
        'id' => (int) $entry['id'],
        'categorie' => (string) $entry['categorie'],
        'site' => (string) $entry['site'],
        'identifiant' => (string) $entry['identifiant'],
        'updated_at' => (string) $entry['updated_at'],
    ];
}

reponse_json_remplissage([
    'ok' => true,
    'domain' => $domaine,
    'csrf_token' => csrf_token(),
    'entries' => $entries,
]);

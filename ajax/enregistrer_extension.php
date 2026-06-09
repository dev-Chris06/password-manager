<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function reponse_json_extension(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function normaliser_site_extension(string $value): string
{
    $value = strtolower(trim($value));

    if ($value === '') {
        return '';
    }

    if (!str_contains($value, '://')) {
        $value = 'https://' . $value;
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

    if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
        return '';
    }

    return $host;
}

function categorie_extension_valide(string $categorie): string
{
    return categorie_valide($categorie) ? $categorie : 'Autre';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reponse_json_extension(['ok' => false, 'message' => 'Méthode invalide.'], 405);
}

utiliser_session_extension_si_presente();

if (!utilisateur_connecte()) {
    reponse_json_extension(['ok' => false, 'message' => 'Session expirée.'], 401);
}

if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
    reponse_json_extension(['ok' => false, 'message' => 'Requête invalide.'], 403);
}

$site = normaliser_site_extension((string) ($_POST['site'] ?? ''));
$identifiant = nettoyer_texte((string) ($_POST['identifiant'] ?? ''), 255);
$motDePasse = (string) ($_POST['password'] ?? '');
$categorie = categorie_extension_valide((string) ($_POST['categorie'] ?? 'Autre'));

if ($site === '' || $identifiant === '' || strlen($motDePasse) < 8) {
    reponse_json_extension(['ok' => false, 'message' => 'Données invalides.'], 400);
}

$resultat = ajouter_entree(
    id_utilisateur_connecte(),
    $categorie,
    $site,
    $identifiant,
    $motDePasse
);

if (!($resultat['ok'] ?? false)) {
    $message = (string) ($resultat['warning'] ?? 'Enregistrement impossible.');
    reponse_json_extension(['ok' => false, 'message' => $message], 500);
}

reponse_json_extension([
    'ok' => true,
    'message' => 'Entrée enregistrée.',
    'entry' => [
        'site' => $site,
        'identifiant' => $identifiant,
        'categorie' => $categorie,
    ],
], 201);

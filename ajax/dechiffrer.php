<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/entrees.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function reponse_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reponse_json(['ok' => false, 'message' => 'Méthode invalide.'], 405);
}

utiliser_session_extension_si_presente();

if (!utilisateur_connecte()) {
    reponse_json(['ok' => false, 'message' => 'Session expirée.'], 401);
}

if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
    reponse_json(['ok' => false, 'message' => 'Requête invalide.'], 403);
}

$entryId = (int) ($_POST['entry_id'] ?? 0);

if ($entryId <= 0) {
    reponse_json(['ok' => false, 'message' => 'Entrée invalide.'], 400);
}

try {
    $password = dechiffrer_entree_utilisateur(id_utilisateur_connecte(), $entryId);
    if ($password === null) {
        reponse_json(['ok' => false, 'message' => 'Entrée introuvable.'], 404);
    }

    reponse_json(['ok' => true, 'password' => $password]);
} catch (Throwable) {
    reponse_json(['ok' => false, 'message' => 'Déchiffrement impossible.'], 500);
}

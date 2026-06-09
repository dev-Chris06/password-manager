<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/entrees.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

if (!verifier_csrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Requête invalide.']);
    exit;
}

exiger_authentification();

$userId = id_utilisateur_connecte();
$cle = cle_chiffrement_session();

try {
    $stmt = get_pdo()->prepare(
        'SELECT id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag 
         FROM entrees 
         WHERE user_id = :user_id'
    );
    $stmt->execute(['user_id' => $userId]);
    $entrees = $stmt->fetchAll();

    $entriesDecrypted = [];
    foreach ($entrees as $entree) {
        try {
            $mdp = dechiffrer_mdp_gcm(
                (string) $entree['mdp_chiffre'],
                (string) $entree['iv'],
                (string) $entree['auth_tag'],
                $cle
            );
            $entriesDecrypted[] = [
                'categorie' => $entree['categorie'],
                'site' => $entree['site'],
                'identifiant' => $entree['identifiant'],
                'password' => $mdp,
            ];
        } catch (Throwable) {
            continue;
        }
    }

    $payload = [
        'version' => 1,
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'entries' => $entriesDecrypted,
    ];

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($jsonPayload === false) {
        throw new RuntimeException('Erreur d\'encodage JSON.');
    }

    $encrypted = chiffrer_mdp_gcm($jsonPayload, $cle);

    journaliser_action($userId, 'export_coffre', count($entriesDecrypted) . ' entrées');

    echo json_encode([
        'ok' => true,
        'format' => 'gestionnaire-mdp-backup-v1',
        'payload' => $encrypted['mdp_chiffre'],
        'iv' => $encrypted['iv'],
        'auth_tag' => $encrypted['auth_tag'],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Erreur lors de l\'export.']);
}

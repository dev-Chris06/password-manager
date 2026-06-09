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

$backup = $_POST['backup'] ?? '';
if ($backup === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Fichier de sauvegarde manquant.']);
    exit;
}

try {
    $data = json_decode($backup, true);
    if (!is_array($data) || !isset($data['format']) || $data['format'] !== 'gestionnaire-mdp-backup-v1') {
        throw new RuntimeException('Format de fichier invalide.');
    }

    if (!isset($data['payload'], $data['iv'], $data['auth_tag'])) {
        throw new RuntimeException('Données de sauvegarde incomplètes.');
    }

    $cle = cle_chiffrement_session();
    $jsonDecrypted = dechiffrer_mdp_gcm(
        (string) $data['payload'],
        (string) $data['iv'],
        (string) $data['auth_tag'],
        $cle
    );

    $payload = json_decode($jsonDecrypted, true);
    if (!is_array($payload) || !isset($payload['entries']) || !is_array($payload['entries'])) {
        throw new RuntimeException('Contenu de sauvegarde invalide.');
    }

    $userId = id_utilisateur_connecte();
    $importees = 0;
    $doublons = 0;

    foreach ($payload['entries'] as $entry) {
        if (!isset($entry['categorie'], $entry['site'], $entry['identifiant'], $entry['password'])) {
            continue;
        }

        $categorie = categorie_valide((string) $entry['categorie']) ? (string) $entry['categorie'] : 'Autre';
        $site = nettoyer_texte((string) $entry['site']);
        $identifiant = nettoyer_texte((string) $entry['identifiant']);
        $motDePasse = (string) $entry['password'];

        if ($site === '' || $identifiant === '' || $motDePasse === '') {
            continue;
        }

        $stmt = get_pdo()->prepare(
            'SELECT id FROM entrees WHERE user_id = :user_id AND site = :site AND identifiant = :identifiant LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'site' => $site,
            'identifiant' => $identifiant,
        ]);

        if ($stmt->fetch() !== false) {
            $doublons++;
            continue;
        }

        try {
            $encrypted = chiffrer_mdp_gcm($motDePasse, $cle);
            $stmtInsert = get_pdo()->prepare(
                'INSERT INTO entrees (user_id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag)
                 VALUES (:user_id, :categorie, :site, :identifiant, :mdp_chiffre, :iv, :auth_tag)'
            );
            $stmtInsert->execute([
                'user_id' => $userId,
                'categorie' => $categorie,
                'site' => $site,
                'identifiant' => $identifiant,
                'mdp_chiffre' => $encrypted['mdp_chiffre'],
                'iv' => $encrypted['iv'],
                'auth_tag' => $encrypted['auth_tag'],
            ]);
            $importees++;
        } catch (Throwable) {
            continue;
        }
    }

    journaliser_action($userId, 'import_coffre', $importees . ' importées, ' . $doublons . ' doublons ignorés');

    echo json_encode([
        'ok' => true,
        'message' => sprintf('%d entrée(s) importée(s). %d doublon(s) ignoré(s).', $importees, $doublons),
        'importees' => $importees,
        'doublons' => $doublons,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    journaliser_action(null, 'import_erreur', substr($e->getMessage(), 0, 200));
    echo json_encode(['ok' => false, 'message' => 'Erreur lors de l\'import.']);
}

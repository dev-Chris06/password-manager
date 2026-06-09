<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function categories_autorisees(): array
{
    return ['Réseaux sociaux', 'Email', 'Banque', 'École', 'Autre'];
}

/**
 * @return array{score:int, niveau:string, alertes:string[]}
 * score : 0-100, niveau : 'faible'|'moyen'|'fort'|'très fort'
 */
function evaluer_force_mdp(string $motDePasse): array
{
    $alertes = [];
    $score   = 0;
    $len     = strlen($motDePasse);

    if ($len < 8)  { $alertes[] = 'Moins de 8 caractères.'; }
    if ($len >= 12) { $score += 20; }
    if ($len >= 16) { $score += 10; }
    if (preg_match('/[A-Z]/', $motDePasse)) { $score += 15; } else { $alertes[] = 'Aucune majuscule.'; }
    if (preg_match('/[a-z]/', $motDePasse)) { $score += 15; } else { $alertes[] = 'Aucune minuscule.'; }
    if (preg_match('/[0-9]/', $motDePasse)) { $score += 15; } else { $alertes[] = 'Aucun chiffre.'; }
    if (preg_match('/[^A-Za-z0-9]/', $motDePasse)) { $score += 25; } else { $alertes[] = 'Aucun caractère spécial.'; }

    $niveau = match(true) {
        $score >= 80 => 'très fort',
        $score >= 60 => 'fort',
        $score >= 40 => 'moyen',
        default      => 'faible',
    };

    return ['score' => $score, 'niveau' => $niveau, 'alertes' => $alertes];
}

function categorie_valide(string $categorie): bool
{
    return in_array($categorie, categories_autorisees(), true);
}

function nettoyer_texte(string $value, int $maxLength = 255): string
{
    $value = trim($value);
    $value = preg_replace('/[[:cntrl:]]/u', '', $value) ?? '';

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function lister_entrees(int $userId, ?string $categorie = null): array
{
    $params = ['user_id' => $userId];
    $sql = 'SELECT id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag, created_at, updated_at
            FROM entrees
            WHERE user_id = :user_id';

    if ($categorie !== null && categorie_valide($categorie)) {
        $sql .= ' AND categorie = :categorie';
        $params['categorie'] = $categorie;
    }

    $sql .= ' ORDER BY updated_at DESC, id DESC';
    $stmt = get_pdo()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function trouver_entree(int $userId, int $entryId): ?array
{
    $stmt = get_pdo()->prepare('SELECT * FROM entrees WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute(['id' => $entryId, 'user_id' => $userId]);
    $entry = $stmt->fetch();

    return is_array($entry) ? $entry : null;
}

function ajouter_entree(int $userId, string $categorie, string $site, string $identifiant, string $motDePasse, bool $forcer = false): array
{
    $categorie = categorie_valide($categorie) ? $categorie : 'Autre';
    $site = nettoyer_texte($site);
    $identifiant = nettoyer_texte($identifiant);

    if ($site === '' || $identifiant === '' || $motDePasse === '') {
        return ['ok' => false, 'warning' => ''];
    }

    $warning = '';
    $cle = cle_chiffrement_session();
    $entrees = lister_entrees($userId);
    foreach ($entrees as $entree) {
        try {
            $mdpExistant = dechiffrer_mdp_gcm(
                (string) $entree['mdp_chiffre'],
                (string) $entree['iv'],
                (string) $entree['auth_tag'],
                $cle
            );
            if ($mdpExistant === $motDePasse) {
                $warning = 'Ce mot de passe est déjà utilisé sur le site "' . $entree['site'] . '".';
                break;
            }
        } catch (Throwable) {
            continue;
        }
    }

    if ($warning !== '' && !$forcer) {
        return ['ok' => false, 'warning' => $warning, 'doublon' => true];
    }

    try {
        $encrypted = chiffrer_mdp_gcm($motDePasse, $cle);
    } catch (Throwable) {
        return ['ok' => false, 'warning' => ''];
    }
    $stmt = get_pdo()->prepare(
        'INSERT INTO entrees (user_id, categorie, site, identifiant, mdp_chiffre, iv, auth_tag)
         VALUES (:user_id, :categorie, :site, :identifiant, :mdp_chiffre, :iv, :auth_tag)'
    );

    $result = $stmt->execute([
        'user_id' => $userId,
        'categorie' => $categorie,
        'site' => $site,
        'identifiant' => $identifiant,
        'mdp_chiffre' => $encrypted['mdp_chiffre'],
        'iv' => $encrypted['iv'],
        'auth_tag' => $encrypted['auth_tag'],
    ]);

    if ($result) {
        $entryId = (int) get_pdo()->lastInsertId();
        journaliser_action($userId, 'entree_ajoutee', 'id:' . $entryId . ' site:' . $site);
    }

    return ['ok' => $result, 'warning' => $warning];
}

function modifier_entree(
    int $userId,
    int $entryId,
    string $categorie,
    string $site,
    string $identifiant,
    ?string $nouveauMotDePasse,
    bool $forcer = false
): array {
    $entry = trouver_entree($userId, $entryId);
    if (!is_array($entry)) {
        return ['ok' => false, 'warning' => ''];
    }

    $categorie = categorie_valide($categorie) ? $categorie : 'Autre';
    $site = nettoyer_texte($site);
    $identifiant = nettoyer_texte($identifiant);

    if ($site === '' || $identifiant === '') {
        return ['ok' => false, 'warning' => ''];
    }

    $warning = '';
    $cle = cle_chiffrement_session();

    try {
        $motDePasseAStocker = $nouveauMotDePasse !== null && $nouveauMotDePasse !== ''
            ? $nouveauMotDePasse
            : dechiffrer_mdp_gcm(
                (string) $entry['mdp_chiffre'],
                (string) $entry['iv'],
                (string) $entry['auth_tag'],
                $cle
            );
        
        if ($nouveauMotDePasse !== null && $nouveauMotDePasse !== '') {
            $entrees = lister_entrees($userId);
            foreach ($entrees as $entree) {
                if ((int) $entree['id'] === $entryId) {
                    continue;
                }
                try {
                    $mdpExistant = dechiffrer_mdp_gcm(
                        (string) $entree['mdp_chiffre'],
                        (string) $entree['iv'],
                        (string) $entree['auth_tag'],
                        $cle
                    );
                    if ($mdpExistant === $nouveauMotDePasse) {
                        $warning = 'Ce mot de passe est déjà utilisé sur le site "' . $entree['site'] . '".';
                        break;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        if ($warning !== '' && !$forcer) {
            return ['ok' => false, 'warning' => $warning, 'doublon' => true];
        }

        $encrypted = chiffrer_mdp_gcm($motDePasseAStocker, $cle);
    } catch (Throwable) {
        return ['ok' => false, 'warning' => ''];
    }
    $stmt = get_pdo()->prepare(
        'UPDATE entrees
         SET categorie = :categorie, site = :site, identifiant = :identifiant,
             mdp_chiffre = :mdp_chiffre, iv = :iv, auth_tag = :auth_tag
         WHERE id = :id AND user_id = :user_id'
    );

    $result = $stmt->execute([
        'id' => $entryId,
        'user_id' => $userId,
        'categorie' => $categorie,
        'site' => $site,
        'identifiant' => $identifiant,
        'mdp_chiffre' => $encrypted['mdp_chiffre'],
        'iv' => $encrypted['iv'],
        'auth_tag' => $encrypted['auth_tag'],
    ]);

    if ($result) {
        journaliser_action($userId, 'entree_modifiee', 'id:' . $entryId . ' site:' . $site);
    }

    return ['ok' => $result, 'warning' => $warning];
}

function supprimer_entree(int $userId, int $entryId): bool
{
    $entry = trouver_entree($userId, $entryId);
    $stmt = get_pdo()->prepare('DELETE FROM entrees WHERE id = :id AND user_id = :user_id');
    $stmt->execute(['id' => $entryId, 'user_id' => $userId]);

    $result = $stmt->rowCount() === 1;
    if ($result && is_array($entry)) {
        journaliser_action($userId, 'entree_supprimee', 'id:' . $entryId . ' site:' . $entry['site']);
    }

    return $result;
}

function dechiffrer_entree_utilisateur(int $userId, int $entryId): ?string
{
    $entry = trouver_entree($userId, $entryId);
    if (!is_array($entry)) {
        return null;
    }

    return dechiffrer_mdp_gcm(
        (string) $entry['mdp_chiffre'],
        (string) $entry['iv'],
        (string) $entry['auth_tag'],
        cle_chiffrement_session()
    );
}

function changer_mot_de_passe_maitre(int $userId, string $ancienMdp, string $nouveauMdp): bool
{
    if (!mot_de_passe_valide($nouveauMdp)) {
        return false;
    }

    $pdo = get_pdo();
    $stmt = $pdo->prepare('SELECT * FROM utilisateurs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $userId]);
    $user = $stmt->fetch();

    if (!is_array($user) || !password_verify($ancienMdp, (string) $user['hash_mdp'])) {
        return false;
    }

    $ancienneCle = deriver_cle_chiffrement($ancienMdp, (string) $user['sel_pbkdf2']);
    $nouveauSel = generer_sel_pbkdf2();
    $nouvelleCle = deriver_cle_chiffrement($nouveauMdp, $nouveauSel);
    $nouveauHash = hash_mot_de_passe_maitre($nouveauMdp);

    try {
        $pdo->beginTransaction();

        $entriesStmt = $pdo->prepare('SELECT id, mdp_chiffre, iv, auth_tag FROM entrees WHERE user_id = :user_id FOR UPDATE');
        $entriesStmt->execute(['user_id' => $userId]);
        $updateEntry = $pdo->prepare(
            'UPDATE entrees
             SET mdp_chiffre = :mdp_chiffre, iv = :iv, auth_tag = :auth_tag
             WHERE id = :id AND user_id = :user_id'
        );

        foreach ($entriesStmt->fetchAll() as $entry) {
            $plain = dechiffrer_mdp_gcm(
                (string) $entry['mdp_chiffre'],
                (string) $entry['iv'],
                (string) $entry['auth_tag'],
                $ancienneCle
            );
            $encrypted = chiffrer_mdp_gcm($plain, $nouvelleCle);
            $updateEntry->execute([
                'mdp_chiffre' => $encrypted['mdp_chiffre'],
                'iv' => $encrypted['iv'],
                'auth_tag' => $encrypted['auth_tag'],
                'id' => (int) $entry['id'],
                'user_id' => $userId,
            ]);
        }

        $updateUser = $pdo->prepare(
            'UPDATE utilisateurs
             SET hash_mdp = :hash_mdp, sel_pbkdf2 = :sel_pbkdf2
             WHERE id = :id'
        );
        $updateUser->execute([
            'hash_mdp' => $nouveauHash,
            'sel_pbkdf2' => $nouveauSel,
            'id' => $userId,
        ]);

        $pdo->commit();
        $_SESSION['cle_chiffrement'] = encoder_cle_session($nouvelleCle);

        journaliser_action($userId, 'mdp_maitre_change', '');

        return true;
    } catch (Throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return false;
    }
}

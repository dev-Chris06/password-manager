-- ========================================================================
-- MIGRATION N°003 — Défaut 3 : login_blocages multi-axes + escalade
-- ========================================================================
-- Date cible : Octobre 2026
-- Idempotent : rejouable sans danger.
--
-- Historique :
--   V1 (oubliée)  : pas de verrous explicites
--   V2 (avant Défaut 3) : login_blocages avec UNIQUE(ip, email) — seulement la paire
--   V3 (Défaut 3)   : colonne `axe` ENUM + `infraction_n` + `created_at`
--                      + UNIQUE composite (axe, ip, email).
--
-- Permet :
--   * PAIR_IP_EMAIL — la paire <ip, email> (existant, V2)
--   * GLOBAL_IP     — 1 IP, tous les comptes (axe 2)
--   * GLOBAL_EMAIL  — 1 email, toutes les IP (axe 3)
--   * infraction_n  — 1/2/3 → escalade des durées (voir LOGIN_DUREE_*_SEC)
-- ========================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Sauvegarder ce qui existe (si V2) dans une table temporaire
DROP TEMPORARY TABLE IF EXISTS _tmp_login_blocages_v2;
CREATE TEMPORARY TABLE _tmp_login_blocages_v2
(
    ip             VARCHAR(45)  NOT NULL,
    email          VARCHAR(255) NOT NULL,
    bloque_jusqu_a DATETIME     NOT NULL
);

-- On ne peut SELECT+INSERT que si la table V2 existe (sinon le bloc est no-op).
-- (On utilise un PROCEDURE stocké éphémère pour l'idempotence, car MySQL n'a
--  pas de "IF EXISTS ... THEN ..." en SQL natif hors stored programs.)

DROP PROCEDURE IF EXISTS _migrate_login_blocages_v3;
DELIMITER $$
CREATE PROCEDURE _migrate_login_blocages_v3()
BEGIN
    DECLARE v_exist INT DEFAULT 0;
    SELECT COUNT(*) INTO v_exist
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'login_blocages';

    IF v_exist = 1 THEN
        -- Colonnes V2 ou V3 ?
        SET @cols = (
            SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION SEPARATOR ',')
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'login_blocages'
        );

        -- V2 = exactement [bloque_jusqu_a, email, ip] (ordre quelconque)
        -- V3 = contient au moins 'axe'
        IF @cols NOT LIKE '%axe%' THEN
            -- Sauvegarde V2
            INSERT INTO _tmp_login_blocages_v2(ip, email, bloque_jusqu_a)
            SELECT ip, email, bloque_jusqu_a FROM login_blocages;
        END IF;
    END IF;

    -- Re-créer la table V3 proprement
    DROP TABLE IF EXISTS login_blocages;
    CREATE TABLE login_blocages (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        axe            ENUM('PAIR_IP_EMAIL', 'GLOBAL_IP', 'GLOBAL_EMAIL', 'GLOBAL_IP_INSCRIPTION') NOT NULL,
        ip             VARCHAR(45)  NOT NULL DEFAULT '',
        email          VARCHAR(255) NOT NULL DEFAULT '',
        bloque_jusqu_a DATETIME     NOT NULL,
        infraction_n   TINYINT UNSIGNED NOT NULL DEFAULT 1,
        created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_login_blocages_axe (axe, ip, email),
        INDEX idx_login_blocages_expire (bloque_jusqu_a)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

    -- Réinjecter ce qui a été sauvé depuis V2 en tant que PAIR_IP_EMAIL niveau 1
    INSERT INTO login_blocages(axe, ip, email, bloque_jusqu_a, infraction_n)
    SELECT 'PAIR_IP_EMAIL', t.ip, t.email, t.bloque_jusqu_a, 1
    FROM _tmp_login_blocages_v2 t;

    DROP TEMPORARY TABLE IF EXISTS _tmp_login_blocages_v2;
END$$
DELIMITER ;

CALL _migrate_login_blocages_v3();
DROP PROCEDURE IF EXISTS _migrate_login_blocages_v3;

SET FOREIGN_KEY_CHECKS = 1;

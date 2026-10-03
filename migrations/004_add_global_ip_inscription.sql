-- ========================================================================
-- MIGRATION N°004 — Défaut H-01 : axe GLOBAL_IP_INSCRIPTION
-- ========================================================================
-- Date cible : Octobre 2026
-- Idempotent : rejouable sans danger.
--
-- Objet : étendre l'ENUM `login_blocages.axe` avec la valeur
--   'GLOBAL_IP_INSCRIPTION' afin de protéger la page d'inscription
--   (pages/register.php) contre le flood de créations de compte par IP.
--
-- Stratégie ALTER TABLE sans perte, rétro-compatible :
--   * Si la colonne `axe` contient déjà GLOBAL_IP_INSCRIPTION → no-op.
--   * Sinon → MODIFY COLUMN en ajoutant la valeur (rajout à la fin,
--     pas de ré-ordonnement, coût quasi nul en MySQL 8.0+).
-- ========================================================================

DROP PROCEDURE IF EXISTS _migrate_login_blocages_v4;
DELIMITER $$
CREATE PROCEDURE _migrate_login_blocages_v4()
BEGIN
    DECLARE v_table_exist INT DEFAULT 0;
    DECLARE v_enum      LONGTEXT DEFAULT NULL;

    SELECT COUNT(*) INTO v_table_exist
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'login_blocages';

    IF v_table_exist = 0 THEN
        -- Table n'existe pas : sera créée par migrer_login_blocages_si_besoin()
        -- ou par database.sql, avec la nouvelle ENUM. Rien à faire ici.
        RETURN;
    END IF;

    SELECT COLUMN_TYPE INTO v_enum
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'login_blocages'
      AND COLUMN_NAME  = 'axe';

    IF v_enum IS NULL OR LOCATE('GLOBAL_IP_INSCRIPTION', v_enum) = 0 THEN
        ALTER TABLE login_blocages
        MODIFY COLUMN axe ENUM('PAIR_IP_EMAIL', 'GLOBAL_IP', 'GLOBAL_EMAIL', 'GLOBAL_IP_INSCRIPTION')
            NOT NULL;
    END IF;
END$$
DELIMITER ;

CALL _migrate_login_blocages_v4();
DROP PROCEDURE IF EXISTS _migrate_login_blocages_v4;

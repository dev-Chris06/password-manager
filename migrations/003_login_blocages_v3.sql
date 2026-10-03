-- Migration 003 : login_blocages V2 vers V3, sans suppression de données.
-- Exécuter une seule fois pendant une maintenance applicative.
-- Une table V2 est conservée sous le nom login_blocages_v2_legacy.

DROP PROCEDURE IF EXISTS migrate_login_blocages_v3;
DELIMITER $$
CREATE PROCEDURE migrate_login_blocages_v3()
BEGIN
    DECLARE has_table INT DEFAULT 0;
    DECLARE has_axe INT DEFAULT 0;

    SELECT COUNT(*) INTO has_table
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_blocages';

    IF has_table = 0 THEN
        CREATE TABLE login_blocages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            axe ENUM('PAIR_IP_EMAIL', 'GLOBAL_IP', 'GLOBAL_EMAIL', 'GLOBAL_IP_INSCRIPTION') NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            email VARCHAR(255) NOT NULL DEFAULT '',
            bloque_jusqu_a DATETIME NOT NULL,
            infraction_n TINYINT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_login_blocages_axe (axe, ip, email),
            INDEX idx_login_blocages_expire (bloque_jusqu_a)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ELSE
        SELECT COUNT(*) INTO has_axe
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_blocages' AND COLUMN_NAME = 'axe';

        IF has_axe = 0 THEN
            CREATE TABLE login_blocages_v3_new (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                axe ENUM('PAIR_IP_EMAIL', 'GLOBAL_IP', 'GLOBAL_EMAIL', 'GLOBAL_IP_INSCRIPTION') NOT NULL,
                ip VARCHAR(45) NOT NULL DEFAULT '',
                email VARCHAR(255) NOT NULL DEFAULT '',
                bloque_jusqu_a DATETIME NOT NULL,
                infraction_n TINYINT UNSIGNED NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_login_blocages_axe (axe, ip, email),
                INDEX idx_login_blocages_expire (bloque_jusqu_a)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            INSERT INTO login_blocages_v3_new (axe, ip, email, bloque_jusqu_a)
                SELECT 'PAIR_IP_EMAIL', ip, email, bloque_jusqu_a FROM login_blocages;
            RENAME TABLE login_blocages TO login_blocages_v2_legacy,
                         login_blocages_v3_new TO login_blocages;
        END IF;
    END IF;
END$$
DELIMITER ;
CALL migrate_login_blocages_v3();
DROP PROCEDURE IF EXISTS migrate_login_blocages_v3;

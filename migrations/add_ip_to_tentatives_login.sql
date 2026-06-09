-- Migration: Ajouter la colonne IP à la table tentatives_login
-- Date: 2026-05-08
-- Description: Permet le rate limiting basé sur l'adresse IP en plus de l'email

USE password_manager;

-- Vérifier si la colonne ip existe déjà
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'password_manager'
    AND TABLE_NAME = 'tentatives_login'
    AND COLUMN_NAME = 'ip'
);

-- Ajouter la colonne ip seulement si elle n'existe pas
SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE tentatives_login ADD COLUMN ip VARCHAR(45) NOT NULL DEFAULT \'\' AFTER email',
    'SELECT ''Column ip already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Ajouter un index sur la colonne ip pour optimiser les requêtes de rate limiting
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'password_manager'
    AND TABLE_NAME = 'tentatives_login'
    AND INDEX_NAME = 'idx_tentatives_ip'
);

SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_tentatives_ip ON tentatives_login(ip)',
    'SELECT ''Index idx_tentatives_ip already exists'' AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

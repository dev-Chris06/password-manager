CREATE DATABASE IF NOT EXISTS password_manager
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE password_manager;

CREATE TABLE IF NOT EXISTS utilisateurs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    hash_mdp VARCHAR(255) NOT NULL,
    sel_pbkdf2 VARCHAR(128) NOT NULL,
    totp_secret VARCHAR(255) NULL DEFAULT NULL,
    totp_active TINYINT(1) NOT NULL DEFAULT 0,
    totp_pending TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_utilisateurs_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    categorie ENUM('Réseaux sociaux', 'Email', 'Banque', 'École', 'Autre') NOT NULL DEFAULT 'Autre',
    site VARCHAR(255) NOT NULL,
    identifiant VARCHAR(255) NOT NULL,
    mdp_chiffre TEXT NOT NULL,
    iv VARCHAR(255) NOT NULL,
    auth_tag VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entrees_user_id (user_id),
    INDEX idx_entrees_categorie (categorie),
    CONSTRAINT fk_entrees_user
        FOREIGN KEY (user_id) REFERENCES utilisateurs(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tentatives_login (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL DEFAULT '',
    nb_tentatives TINYINT UNSIGNED NOT NULL DEFAULT 0,
    derniere_tentative DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    bloque_jusqu_a DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uq_email (email),
    INDEX idx_tentatives_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_actions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(64) NOT NULL,
    detail      VARCHAR(512) NOT NULL DEFAULT '',
    ip          VARCHAR(45) NOT NULL DEFAULT '',
    user_agent  VARCHAR(255) NOT NULL DEFAULT '',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_journal_user_id (user_id),
    INDEX idx_journal_action  (action),
    INDEX idx_journal_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

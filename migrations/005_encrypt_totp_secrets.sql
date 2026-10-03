-- Migration 005 : chiffrement des secrets TOTP avec la clé dérivée du mot de passe maître.
-- Les secrets TOTP historiques ne peuvent pas être chiffrés sans le mot de passe : ils sont invalidés.
ALTER TABLE utilisateurs
    ADD COLUMN totp_secret_iv VARCHAR(255) NULL DEFAULT NULL AFTER totp_secret,
    ADD COLUMN totp_secret_tag VARCHAR(255) NULL DEFAULT NULL AFTER totp_secret_iv;

UPDATE utilisateurs
SET totp_secret = NULL, totp_active = 0, last_totp_slot = 0
WHERE totp_secret IS NOT NULL;

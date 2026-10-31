<?php

declare(strict_types=1);

namespace App\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial zero-knowledge schema (docs/PLAN.md §4.7) and the session table used by PdoSessionHandler.
 */
final class Version20261031120808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: users, encrypted vault entries, backup codes, trusted devices, audit log, sessions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user (id INT UNSIGNED AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, kdf_algorithm VARCHAR(16) NOT NULL, kdf_memory_kib INT UNSIGNED NOT NULL, kdf_iterations INT UNSIGNED NOT NULL, kdf_parallelism INT UNSIGNED NOT NULL, kdf_salt VARCHAR(24) NOT NULL, auth_hash VARCHAR(255) NOT NULL, wrapped_vault_key VARCHAR(255) NOT NULL, totp_secret_encrypted VARCHAR(255) DEFAULT NULL, totp_pending_encrypted VARCHAR(255) DEFAULT NULL, totp_enabled_at DATETIME DEFAULT NULL, totp_last_counter BIGINT UNSIGNED DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_app_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE audit_log (id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL, action VARCHAR(64) NOT NULL, detail VARCHAR(255) NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, user_id INT UNSIGNED DEFAULT NULL, INDEX idx_audit_log_created_at (created_at), INDEX IDX_F6E1C0F5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE backup_code (id INT UNSIGNED AUTO_INCREMENT NOT NULL, code_hmac VARCHAR(64) NOT NULL, used_at DATETIME DEFAULT NULL, user_id INT UNSIGNED NOT NULL, UNIQUE INDEX uniq_backup_code_user_hmac (user_id, code_hmac), INDEX IDX_EC15B504A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trusted_device (id INT UNSIGNED AUTO_INCREMENT NOT NULL, nonce_hash VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME NOT NULL, user_id INT UNSIGNED NOT NULL, UNIQUE INDEX uniq_trusted_device_nonce (nonce_hash), INDEX IDX_F37E8F7BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vault_entry (uuid BINARY(16) NOT NULL, ciphertext MEDIUMTEXT NOT NULL, version INT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, user_id INT UNSIGNED NOT NULL, INDEX idx_vault_entry_user (user_id), PRIMARY KEY (uuid)) DEFAULT CHARACTER SET utf8mb4');
        // Managed by PdoSessionHandler, outside the ORM (see doctrine.dbal.schema_filter).
        $this->addSql('CREATE TABLE sessions (sess_id VARBINARY(128) NOT NULL, sess_data LONGBLOB NOT NULL, sess_lifetime INT UNSIGNED NOT NULL, sess_time INT UNSIGNED NOT NULL, INDEX sessions_sess_lifetime_idx (sess_lifetime), PRIMARY KEY (sess_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin');
        $this->addSql('ALTER TABLE audit_log ADD CONSTRAINT FK_F6E1C0F5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE backup_code ADD CONSTRAINT FK_EC15B504A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trusted_device ADD CONSTRAINT FK_F37E8F7BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE vault_entry ADD CONSTRAINT FK_76B9510BA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP FOREIGN KEY FK_F6E1C0F5A76ED395');
        $this->addSql('ALTER TABLE backup_code DROP FOREIGN KEY FK_EC15B504A76ED395');
        $this->addSql('ALTER TABLE trusted_device DROP FOREIGN KEY FK_F37E8F7BA76ED395');
        $this->addSql('ALTER TABLE vault_entry DROP FOREIGN KEY FK_76B9510BA76ED395');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE backup_code');
        $this->addSql('DROP TABLE trusted_device');
        $this->addSql('DROP TABLE vault_entry');
        $this->addSql('DROP TABLE sessions');
    }
}

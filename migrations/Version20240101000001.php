<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Security migration — adds API key authentication.
 *
 * Design notes
 * ─────────────
 * • api_keys: stores only the SHA-256 hash of the raw bearer token.
 *   Raw tokens are one-way hashed on creation and never persisted.
 *
 * • accounts.api_key_id: nullable FK — NULL means pre-auth / system account.
 *   ON DELETE SET NULL ensures accounts are not cascade-deleted if a key is
 *   revoked; they simply lose their owner reference.
 *
 * • transactions.idempotency_key: tightened to CHAR(64) — the service now stores
 *   the SHA-256 hex digest (64 chars) of "apiKeyId:clientKey", which is both
 *   a fixed-width constant-time compare and a namespace separator.
 */
final class Version20240101000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add api_keys table; add api_key_id FK to accounts; tighten idempotency_key column';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // ── api_keys ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE api_keys (
                id         BINARY(16)   NOT NULL COMMENT '(DC2Type:uuid)',
                name       VARCHAR(100) NOT NULL COMMENT 'Human-readable label for the key',
                key_hash   CHAR(64)     NOT NULL COMMENT 'SHA-256 hex digest of the raw token',
                active     TINYINT(1)   NOT NULL DEFAULT 1,
                created_at DATETIME(6)  NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX idx_api_key_hash (key_hash),
                INDEX idx_api_key_active (active)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── accounts: add owner FK ────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE accounts
                ADD COLUMN api_key_id BINARY(16) NULL COMMENT '(DC2Type:uuid)' AFTER version,
                ADD INDEX idx_account_api_key (api_key_id),
                ADD CONSTRAINT fk_account_api_key
                    FOREIGN KEY (api_key_id)
                    REFERENCES api_keys (id)
                    ON DELETE SET NULL
        SQL);

        // ── transactions: tighten idempotency_key to fixed-width SHA-256 ──────
        $this->addSql(<<<'SQL'
            ALTER TABLE transactions
                MODIFY COLUMN idempotency_key CHAR(64) NOT NULL
                    COMMENT 'SHA-256(apiKeyId:clientKey) — fixed-width for B-tree efficiency'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions MODIFY COLUMN idempotency_key VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE accounts DROP FOREIGN KEY fk_account_api_key');
        $this->addSql('ALTER TABLE accounts DROP INDEX idx_account_api_key, DROP COLUMN api_key_id');
        $this->addSql('DROP TABLE api_keys');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial schema:
 *  - accounts    : financial accounts with BCMath-safe DECIMAL(20,4) balance
 *  - transactions: immutable transfer audit log
 *
 * Design notes
 * ─────────────
 * • DECIMAL(20,4) for all monetary values — no float rounding.
 * • `idempotency_key` has a UNIQUE constraint at the DB level as a final
 *   safety net even if the application-level Redis lock fails.
 * • Indexes on foreign keys and query-hot columns (status, created_at)
 *   keep read performance predictable under high load.
 */
final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create accounts and transactions tables';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // ── accounts ──────────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id          BINARY(16)       NOT NULL COMMENT '(DC2Type:uuid)',
                owner_name  VARCHAR(255)     NOT NULL,
                currency    CHAR(3)          NOT NULL COMMENT 'ISO 4217',
                balance     DECIMAL(20, 4)   NOT NULL DEFAULT '0.0000',
                active      TINYINT(1)       NOT NULL DEFAULT 1,
                version     INT              NOT NULL DEFAULT 0 COMMENT 'Optimistic-lock counter',
                created_at  DATETIME(6)      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at  DATETIME(6)      NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_owner (owner_name),
                INDEX idx_currency (currency),
                INDEX idx_active (active)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        SQL);

        // ── transactions ──────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE transactions (
                id                     BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                idempotency_key        VARCHAR(255)    NOT NULL,
                source_account_id      BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                destination_account_id BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                amount                 DECIMAL(20, 4)  NOT NULL,
                currency               CHAR(3)         NOT NULL,
                status                 VARCHAR(20)     NOT NULL DEFAULT 'pending',
                description            VARCHAR(500)    NULL,
                failure_reason         LONGTEXT        NULL,
                created_at             DATETIME(6)     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                completed_at           DATETIME(6)     NULL     COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                UNIQUE INDEX idx_tx_idempotency (idempotency_key),
                INDEX idx_tx_source (source_account_id),
                INDEX idx_tx_dest (destination_account_id),
                INDEX idx_tx_status (status),
                INDEX idx_tx_created (created_at),
                CONSTRAINT fk_tx_source
                    FOREIGN KEY (source_account_id)
                    REFERENCES accounts (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_tx_dest
                    FOREIGN KEY (destination_account_id)
                    REFERENCES accounts (id)
                    ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS transactions');
        $this->addSql('DROP TABLE IF EXISTS accounts');
    }
}

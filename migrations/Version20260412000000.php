<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fintech infrastructure migration — three append-only tables.
 *
 * 1. ledger_entries — double-entry bookkeeping
 *    • Every transfer writes DEBIT(source) + CREDIT(destination).
 *    • balance_before / balance_after allow full account history reconstruction.
 *    • idx_le_account_created: per-account statement queries (O log N).
 *    • Partition by created_at monthly at ~4 M rows/s sustained load.
 *
 * 2. outbox_events — transactional outbox pattern
 *    • Written atomically with the transfer; relayed by ProcessOutboxCommand.
 *    • Exponential back-off via scheduled_at + retry_count.
 *    • idx_oe_status_scheduled: relay worker SELECT (status, scheduled_at ASC).
 *    • Purge PUBLISHED rows after 30 days to bound table size.
 *
 * 3. audit_log_entries — immutable compliance trail
 *    • PCI-DSS req 10 / FFIEC: every financial action traceable to actor + time.
 *    • idx_al_entity_created: "all actions on account X" — forensic queries.
 *    • idx_al_actor_created:  "all actions by API key Y" — incident response.
 *    • Archive to cold storage after 7 years (financial record retention law).
 *
 * 4. fee_amount column added to transactions for statement completeness.
 */
final class Version20260412000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ledger_entries, outbox_events, audit_log_entries tables; add fee_amount to transactions';
    }

    public function isTransactional(): bool
    {
        return false; // DDL auto-commits in MySQL; avoid wrapping in implicit tx.
    }

    public function up(Schema $schema): void
    {
        // ── fee_amount on transactions ────────────────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE transactions
                ADD COLUMN fee_amount DECIMAL(20, 4) NOT NULL DEFAULT '0.0000'
                    COMMENT 'Processing fee charged on this transfer'
                    AFTER failure_reason
        SQL);

        // ── ledger_entries ────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE ledger_entries (
                id             BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                transaction_id BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                account_id     BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                direction      VARCHAR(6)      NOT NULL COMMENT 'debit|credit',
                amount         DECIMAL(20, 4)  NOT NULL,
                currency       CHAR(3)         NOT NULL,
                balance_before DECIMAL(20, 4)  NOT NULL,
                balance_after  DECIMAL(20, 4)  NOT NULL,
                entry_type     VARCHAR(50)     NOT NULL DEFAULT 'transfer'
                    COMMENT 'transfer|fee|reversal|adjustment',
                created_at     DATETIME(6)     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_le_account_created (account_id, created_at),
                INDEX idx_le_transaction     (transaction_id),
                CONSTRAINT fk_le_transaction
                    FOREIGN KEY (transaction_id) REFERENCES transactions (id) ON DELETE RESTRICT,
                CONSTRAINT fk_le_account
                    FOREIGN KEY (account_id)     REFERENCES accounts (id)     ON DELETE RESTRICT
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Double-entry ledger — append-only, never UPDATE/DELETE'
        SQL);

        // ── outbox_events ─────────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE outbox_events (
                id           BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                event_type   VARCHAR(100)    NOT NULL,
                aggregate_id VARCHAR(36)     NOT NULL,
                payload      JSON            NOT NULL,
                status       VARCHAR(20)     NOT NULL DEFAULT 'pending'
                    COMMENT 'pending|processing|published|failed',
                retry_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                max_retries  SMALLINT UNSIGNED NOT NULL DEFAULT 5,
                last_error   LONGTEXT        NULL,
                created_at   DATETIME(6)     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                scheduled_at DATETIME(6)     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                processed_at DATETIME(6)     NULL     COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_oe_status_scheduled (status, scheduled_at),
                INDEX idx_oe_aggregate        (aggregate_id, event_type)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Transactional outbox — relay worker reads pending rows'
        SQL);

        // ── audit_log_entries ─────────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE audit_log_entries (
                id          BINARY(16)      NOT NULL COMMENT '(DC2Type:uuid)',
                entity_type VARCHAR(50)     NOT NULL,
                entity_id   VARCHAR(36)     NOT NULL,
                action      VARCHAR(100)    NOT NULL,
                actor_id    VARCHAR(36)     NOT NULL,
                ip_address  VARCHAR(45)     NULL,
                payload     JSON            NOT NULL,
                created_at  DATETIME(6)     NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY (id),
                INDEX idx_al_entity_created (entity_type, entity_id, created_at),
                INDEX idx_al_actor_created  (actor_id, created_at)
            ) ENGINE=InnoDB
              DEFAULT CHARSET=utf8mb4
              COLLATE=utf8mb4_unicode_ci
              COMMENT='Immutable audit trail — append-only, never UPDATE/DELETE'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS audit_log_entries');
        $this->addSql('DROP TABLE IF EXISTS outbox_events');
        $this->addSql('DROP TABLE IF EXISTS ledger_entries');
        $this->addSql('ALTER TABLE transactions DROP COLUMN fee_amount');
    }
}

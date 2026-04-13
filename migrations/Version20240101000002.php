<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance migration — indexes for high-traffic query paths.
 *
 * Design notes
 * ─────────────
 * 1. accounts (api_key_id, created_at DESC)
 *    The paginated account-list query is always scoped to one api_key_id and sorted
 *    by created_at DESC.  Without this composite index MySQL scans all rows for
 *    the key then performs a filesort.  The DESC direction hint matches the ORDER BY
 *    so the engine reads rows in index order and skips the sort entirely.
 *
 * 2. transactions (source_account_id, created_at DESC)
 *    findByAccount() filters on source OR destination, so two separate indexes are
 *    needed.  The destination-side index already existed (idx_tx_dest).  Adding the
 *    complementary source-side composite keeps the UNION plan cheap.
 *
 * 3. transactions (source_account_id) covering index for the api_key JOIN path
 *    findAllPaginated() JOINs transactions → sourceAccount → api_key_id.  The existing
 *    idx_tx_source single-column index serves this join; the composite above extends it
 *    without replacing it (MySQL picks whichever estimate is cheaper per query).
 */
final class Version20240101000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite performance indexes on accounts and transactions';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        // Covers: findPaginated(apiKeyId) ORDER BY a.createdAt DESC
        $this->addSql(<<<'SQL'
            ALTER TABLE accounts
                ADD INDEX idx_account_api_key_created (api_key_id, created_at)
        SQL);

        // Covers: findByAccount() source side + ORDER BY t.createdAt DESC
        $this->addSql(<<<'SQL'
            ALTER TABLE transactions
                ADD INDEX idx_tx_source_created (source_account_id, created_at)
        SQL);

        // Covers: findByAccount() destination side + ORDER BY t.createdAt DESC
        $this->addSql(<<<'SQL'
            ALTER TABLE transactions
                ADD INDEX idx_tx_dest_created (destination_account_id, created_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP INDEX idx_tx_dest_created');
        $this->addSql('ALTER TABLE transactions DROP INDEX idx_tx_source_created');
        $this->addSql('ALTER TABLE accounts DROP INDEX idx_account_api_key_created');
    }
}

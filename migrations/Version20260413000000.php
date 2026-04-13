<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add account_number column to accounts table.
 * Provides a human-readable, externally-visible financial identifier (FT + 12 digits).
 */
final class Version20260413000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account_number column to accounts table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accounts ADD account_number VARCHAR(20) DEFAULT NULL');

        // Backfill existing accounts with generated account numbers
        $this->addSql("
            UPDATE accounts
            SET account_number = CONCAT('FT', LPAD(FLOOR(RAND() * 999999999999), 12, '0'))
            WHERE account_number IS NULL
        ");

        $this->addSql('ALTER TABLE accounts MODIFY account_number VARCHAR(20) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_CAC89EACB1A4B286 ON accounts (account_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_CAC89EACB1A4B286 ON accounts');
        $this->addSql('ALTER TABLE accounts DROP account_number');
    }
}

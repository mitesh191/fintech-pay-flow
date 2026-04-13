<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add reversal support: original_transaction_id FK on transactions table.
 */
final class Version20260413100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add original_transaction_id to transactions for reversal tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions ADD original_transaction_id BINARY(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE transactions ADD CONSTRAINT FK_EAA81A4C4C9CF6B7 FOREIGN KEY (original_transaction_id) REFERENCES transactions (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_EAA81A4C4C9CF6B7 ON transactions (original_transaction_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE transactions DROP FOREIGN KEY FK_EAA81A4C4C9CF6B7');
        $this->addSql('DROP INDEX IDX_EAA81A4C4C9CF6B7 ON transactions');
        $this->addSql('ALTER TABLE transactions DROP original_transaction_id');
    }
}

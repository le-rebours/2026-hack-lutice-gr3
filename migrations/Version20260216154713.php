<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260216154713 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Fix missing subscription.user_id column before adding FK + index";
    }

    public function up(Schema $schema): void
    {
        // Ensure the column exists before index/FK
        $this->addSql("ALTER TABLE subscription ADD user_id INT DEFAULT NULL");

        $this->addSql("CREATE INDEX IDX_A3C664D3A76ED395 ON subscription (user_id)");
        $this->addSql("ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D3A76ED395");
        $this->addSql("DROP INDEX IDX_A3C664D3A76ED395 ON subscription");
        $this->addSql("ALTER TABLE subscription DROP user_id");
    }
}

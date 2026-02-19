<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260217105940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD stripe_payment_intent_id VARCHAR(100) DEFAULT NULL');
    }


    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP stripe_payment_intent_id');
    }

}

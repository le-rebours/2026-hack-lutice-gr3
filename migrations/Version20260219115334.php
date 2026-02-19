<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260219115334 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "No-op: previous migrations already applied required schema changes (user_id, stripe_payment_intent_id, FK/index).";
    }

    public function up(Schema $schema): void
    {
        // Intentionally left blank.
        // The schema changes originally generated here are already handled by earlier migrations
        // and/or already exist in the database.
    }

    public function down(Schema $schema): void
    {
        // Intentionally left blank.
    }
}

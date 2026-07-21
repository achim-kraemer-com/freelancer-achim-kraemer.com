<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add per-user stopwatch columns for the Zeiterfassung (time tracking) feature.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timer_started_at and timer_accumulated_seconds to the user table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD timer_started_at DATETIME DEFAULT NULL, ADD timer_accumulated_seconds INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP timer_started_at, DROP timer_accumulated_seconds');
    }
}

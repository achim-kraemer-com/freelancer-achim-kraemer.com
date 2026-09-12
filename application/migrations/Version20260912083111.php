<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rechnungsvorlage (Briefkopf) pro Rechnung: "achim-kraemer" oder "mirwena".
 */
final class Version20260912083111 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rechnung: Vorlage (Briefkopf) wählbar – bestehende Rechnungen bleiben bei Achim Krämer';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD template VARCHAR(20) DEFAULT \'achim-kraemer\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP template');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260720175438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invoice_dispatch (id INT AUTO_INCREMENT NOT NULL, sent_at DATETIME NOT NULL, recipient VARCHAR(255) NOT NULL, invoice_id INT NOT NULL, INDEX IDX_993A3C022989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoice_dispatch ADD CONSTRAINT FK_993A3C022989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice_dispatch DROP FOREIGN KEY FK_993A3C022989F1FD');
        $this->addSql('DROP TABLE invoice_dispatch');
    }
}

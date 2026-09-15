<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * E-Mail-Signaturen (Text oder HTML) mit optionaler Vorgabe je Rechnungsvorlage;
 * jede Rechnung merkt sich die Signatur, mit der sie verschickt wird.
 */
final class Version20260915102436 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-Mail-Signaturen anlegen und je Rechnung eine Signatur wählen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE mail_signature (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(120) NOT NULL, text LONGTEXT NOT NULL, format VARCHAR(10) DEFAULT \'text\' NOT NULL, position INT DEFAULT 0 NOT NULL, default_for_template VARCHAR(20) DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoice ADD signature_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744ED61183A FOREIGN KEY (signature_id) REFERENCES mail_signature (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_90651744ED61183A ON invoice (signature_id)');
    }

    public function down(Schema $schema): void
    {
        // The referencing column has to go before the referenced table can.
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744ED61183A');
        $this->addSql('DROP INDEX IDX_90651744ED61183A ON invoice');
        $this->addSql('ALTER TABLE invoice DROP signature_id');
        $this->addSql('DROP TABLE mail_signature');
    }
}

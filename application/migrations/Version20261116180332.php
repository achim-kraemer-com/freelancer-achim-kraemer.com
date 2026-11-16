<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die angeschlossenen Sprachmodelle für KI-Suche und Assistent. Jedes gehört
 * dem Benutzer, der es angeschlossen hat; sein API-Schlüssel liegt
 * verschlüsselt in api_key (siehe App\Service\SecretCipher).
 */
final class Version20261116180332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprachmodelle für KI-Suche und Assistent anlegen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE assistant_model (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(80) NOT NULL, provider VARCHAR(20) NOT NULL, model VARCHAR(120) NOT NULL, base_url VARCHAR(255) DEFAULT NULL, api_key LONGTEXT DEFAULT NULL, reasoning_effort VARCHAR(10) DEFAULT NULL, is_default TINYINT DEFAULT 0 NOT NULL, position INT DEFAULT 0 NOT NULL, last_checked_at DATETIME DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, INDEX IDX_EA8EB0017E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE assistant_model ADD CONSTRAINT FK_EA8EB0017E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE assistant_model DROP FOREIGN KEY FK_EA8EB0017E3C61F9');
        $this->addSql('DROP TABLE assistant_model');
    }
}

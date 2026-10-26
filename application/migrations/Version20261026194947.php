<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fortlaufende Rechnungsnummern: ein Zähler je Projekt, der nur wächst, und
 * ein UNIQUE-Index auf invoice.number.
 *
 * Bisher entstand die laufende Nummer aus der Anzahl vorhandener Rechnungen.
 * Nach dem Löschen einer Rechnung bekam die nächste damit dieselbe Nummer —
 * und ohne Index hätte die Datenbank das stillschweigend hingenommen.
 *
 * Der Backfill setzt den Zähler je Projekt auf die höchste bereits vergebene
 * laufende Nummer (letztes Segment der Rechnungsnummer). SUBSTRING_INDEX ist
 * MySQL-/MariaDB-Syntax; das Projekt läuft ausschließlich darauf.
 */
final class Version20261026194947 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Zähler je Projekt für fortlaufende Rechnungsnummern, UNIQUE-Index auf invoice.number';
    }

    /**
     * MySQL kennt kein transaktionales DDL: bricht der Index-Aufbau ab, bliebe
     * die halb gewanderte Spalte stehen. Darum vorher prüfen, ob der Index
     * überhaupt gesetzt werden kann, und sonst abbrechen, bevor etwas passiert.
     */
    public function preUp(Schema $schema): void
    {
        $duplicates = (string) $this->connection->fetchOne(
            'SELECT IFNULL(GROUP_CONCAT(number), \'\') FROM '
            .'(SELECT number FROM invoice GROUP BY number HAVING COUNT(*) > 1) d'
        );

        $this->abortIf(
            '' !== $duplicates,
            'Doppelte Rechnungsnummern vorhanden, der UNIQUE-Index kann nicht gesetzt '
            .'werden. Erst bereinigen: '.$duplicates
        );
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD invoice_counter INT DEFAULT 0 NOT NULL');

        $this->addSql(<<<'SQL'
            UPDATE project p SET p.invoice_counter = (
                SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(i.number, '-', -1) AS UNSIGNED)), 0)
                FROM invoice i WHERE i.project_id = p.id
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX UNIQ_9065174496901F54 ON invoice (number)');
    }

    public function down(Schema $schema): void
    {
        // Rollback verliert die Zählerstände: danach vergibt die alte Logik
        // wieder Nummern aus der Anzahl der Rechnungen.
        $this->addSql('DROP INDEX UNIQ_9065174496901F54 ON invoice');
        $this->addSql('ALTER TABLE project DROP invoice_counter');
    }
}

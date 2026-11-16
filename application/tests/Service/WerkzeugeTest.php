<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Service\Assistant\BadToolInput;
use App\Service\Assistant\Werkzeugkasten;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Die Werkzeuge des Assistenten.
 *
 * Lesende laufen sofort; schreibende werden erst vorgemerkt (plan) und
 * geschehen erst beim Übernehmen (apply) – genau das wird hier geprüft, denn
 * daran hängt, dass nichts hinter dem Rücken des Benutzers passiert.
 */
final class WerkzeugeTest extends KernelTestCase
{
    public function testEinNeuerKundeEntstehtErstBeimUebernehmen(): void
    {
        self::bootKernel();
        $eingabe = ['vorname' => 'Martha', 'nachname' => 'Musterfrau', 'email' => 'martha@example.com', 'ort' => 'Bonn'];

        $plan = $this->tools()->plan('kunde_anlegen', $eingabe);

        self::assertTrue($plan['ok']);
        self::assertSame('Kunde anlegen: „Martha Musterfrau“', $plan['action']['summary']);
        self::assertSame('martha@example.com', $plan['action']['details']['E-Mail']);
        // Vorgemerkt heißt noch nicht gespeichert.
        self::assertCount(0, $this->em()->getRepository(Customer::class)->findBy(['email' => 'martha@example.com']));

        $erledigt = $this->tools()->apply($plan['action']);

        $kunde = $this->findeKunden('martha@example.com');
        self::assertNotNull($kunde);
        self::assertSame('Bonn', $kunde->getCity());
        self::assertSame('/admin/customer/'.$kunde->getId(), $erledigt->link);
    }

    /** Was das Formular ablehnen würde, lehnt auch das Werkzeug ab. */
    public function testEineUnmoeglicheEMailWirdAbgelehnt(): void
    {
        self::bootKernel();

        $plan = $this->tools()->plan('kunde_anlegen', [
            'vorname' => 'Max', 'nachname' => 'Muster', 'email' => 'keine-adresse',
        ]);

        self::assertFalse($plan['ok']);
        self::assertStringStartsWith('Fehler:', $plan['message']);
        self::assertNull($this->findeKunden('keine-adresse'));
    }

    public function testEineAufgabeHatEntwederStundenOderFestpreis(): void
    {
        self::bootKernel();
        $projekt = $this->projekt();

        $plan = $this->tools()->plan('aufgabe_anlegen', [
            'projekt_id' => $projekt->getId(), 'name' => 'Beides', 'stunden' => 2, 'festpreis' => 300,
        ]);

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('entweder Stunden oder einen Festpreis', $plan['message']);
    }

    public function testEineAufgabeWirdMitStundenAngelegt(): void
    {
        self::bootKernel();
        $projekt = $this->projekt();

        $plan = $this->tools()->plan('aufgabe_anlegen', [
            'projekt_id' => $projekt->getId(), 'name' => 'Caching einbauen', 'stunden' => '2,5',
        ]);

        self::assertTrue($plan['ok']);
        // Die Vorschau sagt, was es kostet – 2,5 Stunden zu 60 €.
        self::assertStringContainsString('150,00 €', $plan['action']['details']['Abrechnung']);

        $this->tools()->apply($plan['action']);
        $this->em()->clear();

        $aufgabe = $this->em()->getRepository(Task::class)->findOneBy(['name' => 'Caching einbauen']);
        self::assertNotNull($aufgabe);
        self::assertSame('2.50', $aufgabe->getHours());
        self::assertNull($aufgabe->getPrice());
        self::assertSame(Task::STATUS_OPEN, $aufgabe->getStatus());
    }

    /** Erfasste Zeit landet im Protokoll und in den Stunden der Aufgabe. */
    public function testZeitErfassenSchreibtEintragUndStunden(): void
    {
        self::bootKernel();
        $aufgabe = $this->aufgabe($this->projekt(), '1.00');

        $plan = $this->tools()->plan('zeit_erfassen', [
            'aufgabe_id' => $aufgabe->getId(), 'minuten' => 90, 'tag' => '2026-10-07', 'notiz' => 'Caching eingebaut',
        ]);

        self::assertTrue($plan['ok']);
        self::assertSame('07.10.2026', $plan['action']['details']['Tag']);
        self::assertStringContainsString('2,50 Std.', $plan['action']['details']['Neuer Stand']);
        self::assertCount(0, $this->em()->getRepository(TimeEntry::class)->findBy(['task' => $aufgabe->getId()]));

        $this->tools()->apply($plan['action']);
        $this->em()->clear();

        $frisch = $this->em()->getRepository(Task::class)->find($aufgabe->getId());
        self::assertSame('2.50', $frisch->getHours());

        $eintraege = $this->em()->getRepository(TimeEntry::class)->findBy(['task' => $aufgabe->getId()]);
        self::assertCount(1, $eintraege);
        self::assertSame(90, $eintraege[0]->getMinutes());
        self::assertSame(5400, $eintraege[0]->getSeconds());
        self::assertSame('2026-10-07', $eintraege[0]->getWorkDate()->format('Y-m-d'));
        self::assertSame('Caching eingebaut', $eintraege[0]->getNote());
    }

    /** Angefangene Minuten zählen voll – wie beim Beenden der Stoppuhr. */
    public function testStundenWerdenZuVollenMinuten(): void
    {
        self::bootKernel();
        $aufgabe = $this->aufgabe($this->projekt(), null);

        $plan = $this->tools()->plan('zeit_erfassen', ['aufgabe_id' => $aufgabe->getId(), 'stunden' => 0.26]);

        self::assertStringContainsString('16 Min.', $plan['action']['details']['Dauer']);
    }

    /** Was in einer Rechnung steht, fasst der Assistent nicht an. */
    public function testEineAbgerechneteAufgabeIstGesperrt(): void
    {
        self::bootKernel();
        $aufgabe = $this->aufgabe($this->projekt(), '3.00');
        $aufgabe->setInvoiced(true);
        $this->em()->flush();

        $plan = $this->tools()->plan('aufgabe_aendern', ['id' => $aufgabe->getId(), 'stunden' => 99]);

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('gesperrt', $plan['message']);
    }

    /** Ohne ein geändertes Feld gibt es nichts vorzumerken. */
    public function testAendernOhneAngabeWirdAbgelehnt(): void
    {
        self::bootKernel();
        $aufgabe = $this->aufgabe($this->projekt(), '3.00');

        $plan = $this->tools()->plan('aufgabe_aendern', ['id' => $aufgabe->getId()]);

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('nichts zu ändern', $plan['message']);
    }

    public function testBezahltSetztDenZahlungstag(): void
    {
        self::bootKernel();
        $rechnung = $this->rechnung();

        $plan = $this->tools()->plan('rechnung_status', [
            'id' => $rechnung->getId(), 'status' => Invoice::STATUS_PAID, 'tag' => '2026-10-20',
        ]);

        self::assertTrue($plan['ok']);
        $this->tools()->apply($plan['action']);
        $this->em()->clear();

        $frisch = $this->em()->getRepository(Invoice::class)->find($rechnung->getId());
        self::assertSame(Invoice::STATUS_PAID, $frisch->getStatus());
        self::assertSame('2026-10-20', $frisch->getPaidAt()->format('Y-m-d'));
    }

    /** Derselbe Status noch einmal ist keine Änderung. */
    public function testDerGleicheStatusWirdAbgelehnt(): void
    {
        self::bootKernel();
        $rechnung = $this->rechnung();

        $plan = $this->tools()->plan('rechnung_status', ['id' => $rechnung->getId(), 'status' => Invoice::STATUS_SENT]);

        self::assertFalse($plan['ok']);
        self::assertStringContainsString('steht schon auf', $plan['message']);
    }

    /** Eine geratene ID führt nirgendwohin – auch nicht beim Übernehmen. */
    public function testEineUnbekannteIdWirdBeimUebernehmenAbgelehnt(): void
    {
        self::bootKernel();

        $this->expectException(BadToolInput::class);
        $this->tools()->apply(['tool' => 'zeit_erfassen', 'input' => ['aufgabe_id' => 999999, 'minuten' => 10]]);
    }

    /** Das lesende Werkzeug liefert IDs und Pfade – daran hangelt sich das Modell weiter. */
    public function testSuchenLiefertIdsUndPfade(): void
    {
        self::bootKernel();
        $aufgabe = $this->aufgabe($this->projekt(), '1.00');

        $ergebnis = \json_decode($this->tools()->run('suchen', ['begriffe' => ['Caching']]), true);

        self::assertSame(['Caching'], $ergebnis['gesucht']);
        self::assertSame($aufgabe->getId(), $ergebnis['treffer']['aufgaben'][0]['id']);
        self::assertSame('/admin/task/'.$aufgabe->getId().'/edit', $ergebnis['treffer']['aufgaben'][0]['link']);
    }

    /** Die Zahlen eines Zeitraums: abgerechnet, offen, erfasst. */
    public function testZahlenLesenRechnetDenZeitraumZusammen(): void
    {
        self::bootKernel();
        $this->rechnung();

        $ergebnis = \json_decode($this->tools()->run('zahlen_lesen', ['von' => '2026-10-01', 'bis' => '2026-10-31']), true);

        self::assertSame(['von' => '2026-10-01', 'bis' => '2026-10-31'], $ergebnis['zeitraum']);
        self::assertSame(1, $ergebnis['rechnungen_im_zeitraum']['anzahl']);
        // Beträge kommen als Zahl zurück; ob JSON daraus 240 oder 240.0 macht,
        // ist dem Modell gleich.
        self::assertEqualsWithDelta(240.0, $ergebnis['rechnungen_im_zeitraum']['summe'], 0.001);
        self::assertEqualsWithDelta(240.0, $ergebnis['offene_rechnungen_gesamt'], 0.001);
    }

    /** Ein Werkzeug, das es nicht gibt, bringt das Gespräch nicht zum Absturz. */
    public function testEinUnbekanntesWerkzeugMeldetSich(): void
    {
        self::bootKernel();

        self::assertStringContainsString('Unbekanntes Werkzeug', $this->tools()->run('zaubern', []));
        self::assertFalse($this->tools()->plan('zaubern', [])['ok']);
    }

    private function tools(): Werkzeugkasten
    {
        return static::getContainer()->get(Werkzeugkasten::class);
    }

    private function findeKunden(string $email): ?Customer
    {
        return $this->em()->getRepository(Customer::class)->findOneBy(['email' => $email]);
    }

    private function projekt(): Project
    {
        $kunde = (new Customer())
            ->setFirstname('Max')
            ->setLastname('Muster')
            ->setEmail('max-'.\uniqid().'@example.com');

        $projekt = (new Project())->setName('Webshop-Relaunch')->setCustomer($kunde);

        $this->em()->persist($kunde);
        $this->em()->persist($projekt);
        $this->em()->flush();

        return $projekt;
    }

    private function aufgabe(Project $projekt, ?string $stunden): Task
    {
        $aufgabe = (new Task())->setName('Caching einbauen')->setProject($projekt)->setHours($stunden);

        $this->em()->persist($aufgabe);
        $this->em()->flush();

        return $aufgabe;
    }

    private function rechnung(): Invoice
    {
        $projekt  = $this->projekt();
        $rechnung = (new Invoice())
            ->setNumber('26-2001-1')
            ->setCustomer($projekt->getCustomer())
            ->setProject($projekt)
            ->setTotalAmount('240.00')
            ->setStatus(Invoice::STATUS_SENT);
        $rechnung->setInvoiceDate(new \DateTime('2026-10-15'));

        $this->em()->persist($rechnung);
        $this->em()->flush();

        return $rechnung;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}

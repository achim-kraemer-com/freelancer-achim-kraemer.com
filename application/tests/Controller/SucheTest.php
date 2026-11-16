<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Service\Suche\Gesamtsuche;
use App\Service\Suche\Suchplan;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Suche hinter der Lupe: ein Suchfeld für Kunden, Projekte, Aufgaben,
 * Rechnungen und Zeiten, und jeder Treffer führt auf seine Seite.
 */
final class SucheTest extends WebTestCase
{
    public function testOhneAnmeldungFuehrtDieSucheZurAnmeldung(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/suche?q=Webshop');

        self::assertResponseRedirects('/login');
    }

    public function testDieLupeStehtInDerNavigation(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request('GET', '/admin/customer');

        self::assertCount(1, $crawler->filter('nav.admin-nav a.nav-werkzeug[href="/admin/suche"][data-suche-oeffnen]'));
        self::assertCount(1, $crawler->filter('nav.admin-nav a.nav-werkzeug[href="/admin/assistent"][data-assistent-oeffnen]'));
        self::assertCount(1, $crawler->filter('dialog#suche-dialog form[action="/admin/suche"] input[name="q"]'));
        self::assertCount(1, $crawler->filter('dialog#assistent-dialog form[action="/admin/assistent/fragen"]'));

        // Das Dashboard steht nicht auf dem Layout der Verwaltung, hat aber
        // dieselbe Leiste – und damit dieselben Fenster samt Skripten.
        $crawler = $client->request('GET', '/dashboard');

        self::assertCount(1, $crawler->filter('dialog#suche-dialog'));
        self::assertCount(1, $crawler->filter('dialog#assistent-dialog'));
        self::assertCount(1, $crawler->filter('script[src$="/js/fenster.js"]'));
    }

    /**
     * Ein Stichwort findet in jedem Bereich, was dazu passt, und jeder Treffer
     * verlinkt seine eigene Seite. Ohne angeschlossenes Modell sagt die Seite,
     * dass nach dem Wortlaut gesucht wurde.
     */
    public function testEinStichwortFindetInAllenBereichen(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $daten = $this->datenbestand();

        $crawler = $client->request('GET', '/admin/suche?teil=1&q=webshop');

        self::assertResponseIsSuccessful();
        // Nur der Ausschnitt für das Such-Fenster, ohne Seitengerüst.
        self::assertCount(0, $crawler->filter('nav.admin-nav'));

        $links = $crawler->filter('a.suche-treffer')->each(static fn ($a) => $a->attr('href'));

        self::assertSame([
            '/admin/project/'.$daten['projekt']->getId(),
            '/admin/task/'.$daten['aufgabe']->getId().'/edit',
            '/admin/invoice/'.$daten['rechnung']->getId(),
            '/admin/task/'.$daten['aufgabe']->getId().'/edit',
        ], $links);

        self::assertStringContainsString('Ohne angeschlossenes Modell', $crawler->filter('.suche-hinweis')->text());
    }

    /** Was nirgends vorkommt, bringt die Seite mit leeren Händen zurück. */
    public function testOhneTrefferStehtDasDa(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());
        $this->datenbestand();

        $crawler = $client->request('GET', '/admin/suche?q=Nilpferd');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nichts gefunden', $crawler->filter('.empty')->text());
    }

    /** Ein Begriff aus mehreren Wörtern passt, wenn alle Wörter vorkommen. */
    public function testAlleWoerterEinesBegriffsMuessenVorkommen(): void
    {
        static::createClient();
        $this->datenbestand();

        $suche = static::getContainer()->get(Gesamtsuche::class);

        self::assertArrayHasKey('projekte', $suche->suchen(Suchplan::wortlaut('webshop relaunch')));
        self::assertArrayNotHasKey('projekte', $suche->suchen(Suchplan::wortlaut('webshop nilpferd')));
    }

    /** Platzhalter von LIKE im Suchbegriff gelten wörtlich. */
    public function testProzentzeichenIstKeinPlatzhalter(): void
    {
        static::createClient();
        $em      = $this->em();
        $projekt = $this->datenbestand()['projekt'];

        $em->persist((new Task())->setName('50% Anzahlung')->setProject($projekt)->setPrice('500.00'));
        $em->persist((new Task())->setName('500 Euro Puffer')->setProject($projekt)->setPrice('500.00'));
        $em->flush();

        $treffer = static::getContainer()->get(Gesamtsuche::class)->suchen(Suchplan::wortlaut('50%'));

        self::assertSame(['50% Anzahlung'], \array_map(static fn (Task $t) => $t->getName(), $treffer['aufgaben']));
    }

    /** Ein Zeitraum grenzt ein, was gefunden wird – sein letzter Tag zählt mit. */
    public function testDerZeitraumGrenztEin(): void
    {
        static::createClient();
        $em      = $this->em();
        $projekt = $this->datenbestand()['projekt'];

        $alt = (new Task())->setName('Webshop Altlast')->setProject($projekt)->setHours('1.00');
        $alt->setCreatedAt(new \DateTime('2026-01-15'));
        $em->persist($alt);
        $em->flush();

        $suche = static::getContainer()->get(Gesamtsuche::class);

        $imJanuar = new Suchplan(
            ['Webshop'],
            ['aufgaben'],
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-02-01'),
        );

        self::assertSame(
            ['Webshop Altlast'],
            \array_map(static fn (Task $t) => $t->getName(), $suche->suchen($imJanuar)['aufgaben']),
        );
    }

    /**
     * Der Bestand, den die Suche finden soll: ein Kunde mit Projekt, Aufgabe,
     * Rechnung und erfasster Zeit – überall steht „Webshop" außer beim Kunden.
     *
     * @return array{kunde: Customer, projekt: Project, aufgabe: Task, rechnung: Invoice, zeit: TimeEntry}
     */
    private function datenbestand(): array
    {
        $em = $this->em();

        $kunde = (new Customer())
            ->setFirstname('Martha')
            ->setLastname('Musterfrau')
            ->setCompanyName('Musterfrau GmbH')
            ->setEmail('martha@example.com')
            ->setCity('Bonn');

        $projekt = (new Project())->setName('Webshop-Relaunch')->setCustomer($kunde);
        $projekt->setCreatedAt(new \DateTime('2026-09-01'));

        $aufgabe = (new Task())->setName('Webshop aufsetzen')->setProject($projekt)->setHours('4.00');
        $aufgabe->setCreatedAt(new \DateTime('2026-09-02'));

        $zeit = (new TimeEntry())
            ->setTask($aufgabe)
            ->setMinutes(90)
            ->setSeconds(5400)
            ->setNote('Webshop eingerichtet');
        $zeit->setWorkDate(new \DateTime('2026-09-03'));

        $rechnung = (new Invoice())
            ->setNumber('26-1001-1')
            ->setCustomer($kunde)
            ->setProject($projekt)
            ->setTotalAmount('240.00')
            ->setStatus(Invoice::STATUS_SENT);
        $rechnung->setInvoiceDate(new \DateTime('2026-09-30'));

        foreach ([$kunde, $projekt, $aufgabe, $zeit, $rechnung] as $entity) {
            $em->persist($entity);
        }

        $em->flush();

        return ['kunde' => $kunde, 'projekt' => $projekt, 'aufgabe' => $aufgabe, 'rechnung' => $rechnung, 'zeit' => $zeit];
    }

    private function admin(): User
    {
        $user = (new User())
            ->setEmail('suche-test@example.com')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('x');

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}

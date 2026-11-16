<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Assistant\Werkzeugkasten;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Weg durch die Oberfläche des Assistenten: ohne Modell sagt er das, und
 * eine vorbereitete Änderung geschieht erst, wenn sie bestätigt wird.
 *
 * Gefragt wird hier kein echtes Modell – die Vorschau kommt wie nach einer
 * Frage in die Sitzung, und geprüft wird, was die Oberfläche daraus macht.
 */
final class AssistantFlowTest extends WebTestCase
{
    public function testOhneAnmeldungFuehrtDerAssistentZurAnmeldung(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/assistent');

        self::assertResponseRedirects('/login');
    }

    public function testOhneModellSagtDieSeiteWasFehlt(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request('GET', '/admin/assistent');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Noch kein Modell angeschlossen', $crawler->filter('.assistant-setup')->text());
        // Ohne Modell lässt sich nichts abschicken.
        self::assertNotNull($crawler->filter('#assistant-text')->attr('disabled'));
        self::assertCount(1, $crawler->filter('.assistant-setup a[href="/admin/modell/neu"]'));
    }

    public function testDasFensterBekommtNurDasGespraech(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $crawler = $client->request('GET', '/admin/assistent?teil=1');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.admin-nav'));
        self::assertStringContainsString('Noch kein Modell angeschlossen', $crawler->text());
    }

    /**
     * Wer übernimmt, bekommt das Gespräch zurück – samt Link auf das, was
     * eben angelegt wurde. Vorher ist in der Datenbank nichts davon zu sehen.
     */
    public function testUebernehmenLegtAnUndVerlinktDasErgebnis(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $this->vorschau($client, ['name' => 'Caching einbauen', 'stunden' => 3]);

        $crawler = $client->request('GET', '/admin/assistent?teil=1');
        self::assertStringContainsString('Aufgabe anlegen: „Caching einbauen“', $crawler->filter('.proposal')->text());
        self::assertNull($this->findeAufgabe());

        // Wie das Fenster: dasselbe Formular, nur mit teil=1.
        $token   = $crawler->filter('form[action="/admin/assistent/uebernehmen"] input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/admin/assistent/uebernehmen?teil=1', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('nav.admin-nav'));

        $aufgabe = $this->findeAufgabe();
        self::assertNotNull($aufgabe);
        self::assertSame('3.00', $aufgabe->getHours());
        self::assertSame(
            ['/admin/task/'.$aufgabe->getId().'/edit'],
            $crawler->filter('.chat-links a')->each(static fn ($a) => $a->attr('href')),
        );
        self::assertCount(0, $crawler->filter('.proposal'), 'Die Vorschau ist erledigt.');
    }

    /** Verwerfen lässt die Datenbank, wie sie war. */
    public function testVerwerfenLegtNichtsAn(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $this->vorschau($client, ['name' => 'Caching einbauen', 'stunden' => 3]);

        $crawler = $client->request('GET', '/admin/assistent?teil=1');
        $token   = $crawler->filter('form[action="/admin/assistent/verwerfen"] input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/admin/assistent/verwerfen?teil=1', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.proposal'));
        self::assertNull($this->findeAufgabe());
    }

    /** Ohne gültiges Formular-Token geschieht nichts. */
    public function testOhneTokenWirdNichtUebernommen(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $this->vorschau($client, ['name' => 'Caching einbauen', 'stunden' => 3]);

        $client->request('POST', '/admin/assistent/uebernehmen?teil=1', ['_token' => 'falsch']);

        self::assertResponseIsSuccessful();
        self::assertNull($this->findeAufgabe());
    }

    /** Ohne JavaScript führt alles über Weiterleitungen auf die Seite zurück. */
    public function testOhneFensterFuehrtDieAktionZurueckAufDieSeite(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $this->vorschau($client, ['name' => 'Caching einbauen', 'stunden' => 3]);

        $client->request('GET', '/admin/assistent');
        $client->submitForm('Übernehmen');

        self::assertResponseRedirects('/admin/assistent');
        self::assertNotNull($this->findeAufgabe());
    }

    /** „Verlauf leeren" nimmt Gespräch und offene Vorschau mit. */
    public function testVerlaufLeerenRaeumtAuf(): void
    {
        $client = static::createClient();
        $client->loginUser($this->admin());

        $this->vorschau($client, ['name' => 'Caching einbauen', 'stunden' => 3]);

        $crawler = $client->request('GET', '/admin/assistent?teil=1');
        $token   = $crawler->filter('form[action="/admin/assistent/leeren"] input[name="_token"]')->attr('value');
        $crawler = $client->request('POST', '/admin/assistent/leeren?teil=1', ['_token' => $token]);

        self::assertCount(0, $crawler->filter('.proposal'));
        self::assertCount(0, $crawler->filter('.chat'));
    }

    /**
     * Legt eine vorbereitete Änderung in die Sitzung – wie sie nach einer
     * Frage an das Modell dort liegen würde. Im Verlauf steht die Frage dazu,
     * damit „Verlauf leeren" etwas zu räumen hat.
     *
     * @param array<string, mixed> $eingabe
     */
    private function vorschau(KernelBrowser $client, array $eingabe): void
    {
        $tools = static::getContainer()->get(Werkzeugkasten::class);
        $plan  = $tools->plan('aufgabe_anlegen', ['projekt_id' => $this->projekt()->getId()] + $eingabe);

        self::assertTrue($plan['ok'], $plan['message']);

        // Erst eine Anfrage, damit es eine Sitzung gibt, die sich füllen lässt.
        $client->request('GET', '/admin/assistent?teil=1');
        $sitzung = $client->getRequest()->getSession();
        $sitzung->set('assistant_history', [
            ['role' => 'user', 'text' => '3 Stunden Caching auf dem Webshop'],
            ['role' => 'assistant', 'text' => 'Ich habe die folgenden Änderungen vorbereitet.'],
        ]);
        $sitzung->set('assistant_pending', [$plan['action']]);
        $sitzung->save();
    }

    private function findeAufgabe(): ?Task
    {
        $this->em()->clear();

        return $this->em()->getRepository(Task::class)->findOneBy(['name' => 'Caching einbauen']);
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

    private function admin(): User
    {
        $user = (new User())
            ->setEmail('assistent-test@example.com')
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

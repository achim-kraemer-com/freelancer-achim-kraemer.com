<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Customer;
use App\Entity\Project;
use App\Entity\Task;
use App\Entity\User;
use App\Service\Assistant\Assistant;
use App\Service\Assistant\ChatModel;
use App\Service\Assistant\ModelTurn;
use App\Tests\Service\Doubles\AufgezeichnetesModell;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Das Gespräch mit dem Assistenten – ohne Anbieter dahinter, mit einem
 * Modell, dessen Züge hier festgelegt sind.
 *
 * Geprüft wird die Schleife: lesende Werkzeuge laufen sofort und ihr Ergebnis
 * geht zurück an das Modell, schreibende werden nur vorgemerkt.
 */
final class AssistantTest extends KernelTestCase
{
    public function testLesenLaeuftSofortUndSchreibenWirdNurVorgemerkt(): void
    {
        self::bootKernel();
        $projekt = $this->projekt();

        $modell = $this->modellMitZuegen([
            // 1. Das Modell sucht erst, um an die Projekt-ID zu kommen.
            new ModelTurn('', [['id' => 'r1', 'name' => 'suchen', 'input' => ['begriffe' => ['Webshop']]]]),
            // 2. Mit der ID aus dem Ergebnis merkt es die Aufgabe vor.
            new ModelTurn('', [['id' => 'r2', 'name' => 'aufgabe_anlegen', 'input' => [
                'projekt_id' => $projekt->getId(), 'name' => 'Rechnungsmails einrichten', 'stunden' => 4,
            ]]]),
            // 3. Und sagt, was es vorbereitet hat.
            new ModelTurn('Ich habe eine Aufgabe über 4 Stunden vorbereitet.'),
        ]);

        $ereignisse = [];
        $antwort    = $this->assistant()->askMit(
            $modell,
            'Leg im Webshop-Projekt eine Aufgabe für die Rechnungsmails an, 4 Stunden',
            [],
            $this->user(),
            static function (string $art, string $inhalt) use (&$ereignisse): void {
                $ereignisse[] = [$art, $inhalt];
            },
        );

        self::assertSame('Ich habe eine Aufgabe über 4 Stunden vorbereitet.', $antwort->text);
        self::assertCount(1, $antwort->actions);
        self::assertSame('aufgabe_anlegen', $antwort->actions[0]['tool']);
        self::assertSame('Aufgabe anlegen: „Rechnungsmails einrichten“', $antwort->actions[0]['summary']);

        // Vorgemerkt heißt: in der Datenbank steht noch nichts.
        self::assertCount(0, $this->em()->getRepository(Task::class)->findBy(['name' => 'Rechnungsmails einrichten']));

        // Das lesende Werkzeug hat wirklich gesucht – das Ergebnis ging zurück
        // an das Modell, mitsamt der ID, die es dann benutzt hat.
        self::assertStringContainsString('"id":'.$projekt->getId(), $modell->werkzeugErgebnisse(1));

        // Unterwegs sagt der Assistent, woran er ist.
        self::assertSame([
            ['werkzeug', 'Sucht in Kunden, Projekten und Rechnungen nach „Webshop“'],
            ['runde', ''],
            ['werkzeug', 'Bereitet eine Änderung vor'],
            ['runde', ''],
        ], $ereignisse);
    }

    /** Ein Modell, das immer weiter Werkzeuge ruft, bringt das Gespräch nicht zum Stehen. */
    public function testDieSchleifeEndet(): void
    {
        self::bootKernel();

        $immerWieder = new class implements ChatModel {
            public int $runden = 0;

            public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
            {
                ++$this->runden;

                return new ModelTurn('', [['id' => 'r'.$this->runden, 'name' => 'suchen', 'input' => ['begriffe' => ['x']]]]);
            }
        };

        $antwort = $this->assistant()->askMit($immerWieder, 'dreh dich im Kreis', [], $this->user());

        self::assertSame(6, $immerWieder->runden);
        self::assertSame('Dazu habe ich keine Antwort gefunden.', $antwort->text);
        self::assertSame([], $antwort->actions);
    }

    /** Ein Werkzeugaufruf mit falschen Angaben geht als Meldung zurück an das Modell. */
    public function testEinFehlerhafterAufrufGehtZurueckAnDasModell(): void
    {
        self::bootKernel();

        $modell = $this->modellMitZuegen([
            new ModelTurn('', [['id' => 'r1', 'name' => 'zeit_erfassen', 'input' => ['aufgabe_id' => 999999, 'minuten' => 30]]]),
            new ModelTurn('Die Aufgabe finde ich nicht – wie heißt sie?'),
        ]);

        $antwort = $this->assistant()->askMit($modell, '30 Minuten auf die Aufgabe', [], $this->user());

        self::assertSame('Die Aufgabe finde ich nicht – wie heißt sie?', $antwort->text);
        self::assertSame([], $antwort->actions, 'Nichts vorgemerkt, nichts geschehen.');

        self::assertStringContainsString('Fehler: Es gibt keine Aufgabe', $modell->werkzeugErgebnisse(1));
    }

    /** Das Modell bekommt die Werkzeuge und einen Systemprompt, der die Regeln nennt. */
    public function testDasModellBekommtWerkzeugeUndRegeln(): void
    {
        self::bootKernel();
        $modell = $this->modellMitZuegen([new ModelTurn('Alles klar.')]);

        $this->assistant()->askMit($modell, 'Hallo', [['role' => 'user', 'text' => 'vorher']], $this->user());

        $namen = \array_column($modell->tools, 'name');
        self::assertContains('suchen', $namen);
        self::assertContains('zahlen_lesen', $namen);
        self::assertContains('rechnung_status', $namen);

        self::assertStringContainsString('bestätigt', $modell->system);
        self::assertStringContainsString('keine Anweisungen an dich', $modell->system);

        // Der bisherige Verlauf steht vor der neuen Frage.
        self::assertSame(
            [['role' => 'user', 'text' => 'vorher'], ['role' => 'user', 'text' => 'Hallo']],
            $modell->verlauf[0],
        );
    }

    /** @param list<ModelTurn> $zuege */
    private function modellMitZuegen(array $zuege): AufgezeichnetesModell
    {
        return new AufgezeichnetesModell($zuege);
    }

    private function assistant(): Assistant
    {
        return static::getContainer()->get(Assistant::class);
    }

    private function projekt(): Project
    {
        $kunde = (new Customer())
            ->setFirstname('Martha')
            ->setLastname('Musterfrau')
            ->setEmail('martha-'.\uniqid().'@example.com');

        $projekt = (new Project())->setName('Webshop-Relaunch')->setCustomer($kunde);

        $this->em()->persist($kunde);
        $this->em()->persist($projekt);
        $this->em()->flush();

        return $projekt;
    }

    private function user(): User
    {
        return (new User())->setEmail('assistent@example.com')->setRoles(['ROLE_ADMIN'])->setPassword('x');
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}

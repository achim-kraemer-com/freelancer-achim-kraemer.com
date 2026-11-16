<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Assistant\ChatModel;
use App\Service\Assistant\ModelTurn;
use App\Service\Suche\KiSuchplaner;
use App\Service\Suche\Suchplan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Was die KI aus einer Eingabe machen darf – ohne echtes Modell, mit einem,
 * das eine feste Antwort gibt. Geprüft wird die Nachbereitung: Unbekanntes
 * fällt weg, der Wortlaut bleibt immer dabei.
 */
final class KiSuchplanerTest extends KernelTestCase
{
    /**
     * Unbekannte Bereiche fallen weg, der Zeitraum endet nach seinem letzten
     * Tag, der Wortlaut ist immer dabei und doppelte Begriffe zählen einmal.
     */
    public function testDerPlanDerKiWirdGeprueft(): void
    {
        $modell = $this->modellMitAntwort(new ModelTurn('', [[
            'id'    => 'ruf-1',
            'name'  => 'suchen',
            'input' => [
                'begriffe' => ['Müller', 'müller', 'Webshop', '  ', 42],
                'bereiche' => ['rechnungen', 'geheimfach'],
                'von'      => '2026-10-12',
                'bis'      => '2026-10-18',
            ],
        ]]));

        $plan = $this->planer()->planenMit($modell, 'Rechnungen von Müller letzte Woche', new \DateTimeImmutable('2026-10-25'));

        self::assertTrue($plan->vonKi);
        self::assertSame(['Rechnungen von Müller letzte Woche', 'Müller', 'Webshop'], $plan->begriffe);
        self::assertSame(['rechnungen'], $plan->bereiche);
        self::assertSame('2026-10-12 00:00', $plan->von?->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 00:00', $plan->bis?->format('Y-m-d H:i'));
    }

    /** Antwortet das Modell nur mit Text, bleibt die Suche nach dem Wortlaut. */
    public function testOhneWerkzeugaufrufBleibtDerWortlaut(): void
    {
        $plan = $this->planer()->planenMit(
            $this->modellMitAntwort(new ModelTurn('Ich suche gern für dich.')),
            'Rechnung Mirwena',
        );

        self::assertFalse($plan->vonKi);
        self::assertSame(['Rechnung Mirwena'], $plan->begriffe);
        self::assertSame([], $plan->bereiche);
        self::assertFalse($plan->hatZeitraum());
    }

    /** Ein unmögliches Datum ist kein Zeitraum. */
    public function testEinUngueltigesDatumWirdIgnoriert(): void
    {
        $modell = $this->modellMitAntwort(new ModelTurn('', [[
            'id'    => 'ruf-1',
            'name'  => 'suchen',
            'input' => ['begriffe' => ['Urlaub'], 'von' => '2026-02-30', 'bis' => 'morgen'],
        ]]));

        self::assertFalse($this->planer()->planenMit($modell, 'Urlaub')->hatZeitraum());
    }

    /** Ein Ende vor dem Anfang ergibt keinen Zeitraum – beide fallen weg. */
    public function testEinUmgekehrterZeitraumFaelltWeg(): void
    {
        $modell = $this->modellMitAntwort(new ModelTurn('', [[
            'id'    => 'ruf-1',
            'name'  => 'suchen',
            'input' => ['begriffe' => ['Shop'], 'von' => '2026-10-20', 'bis' => '2026-10-10'],
        ]]));

        $plan = $this->planer()->planenMit($modell, 'Shop');

        self::assertFalse($plan->hatZeitraum());
        self::assertSame(['Shop'], $plan->begriffe);
    }

    /** Das Werkzeug kennt genau die Bereiche, in denen gesucht werden kann. */
    public function testDasWerkzeugNenntNurVorhandeneBereiche(): void
    {
        $modell = new class implements ChatModel {
            /** @var list<array{name: string, description: string, schema: array<string, mixed>}> */
            public array $tools = [];

            public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
            {
                $this->tools = $tools;

                return new ModelTurn('');
            }
        };

        $this->planer()->planenMit($modell, 'irgendwas');

        self::assertCount(1, $modell->tools);
        self::assertSame('suchen', $modell->tools[0]['name']);
        self::assertSame(
            Suchplan::BEREICHE,
            $modell->tools[0]['schema']['properties']['bereiche']['items']['enum'],
        );
    }

    private function planer(): KiSuchplaner
    {
        self::bootKernel();

        return static::getContainer()->get(KiSuchplaner::class);
    }

    private function modellMitAntwort(ModelTurn $zug): ChatModel
    {
        return new class($zug) implements ChatModel {
            public function __construct(private readonly ModelTurn $zug)
            {
            }

            public function converse(string $system, array $tools, array $messages, ?\Closure $beiText = null): ModelTurn
            {
                return $this->zug;
            }
        };
    }
}

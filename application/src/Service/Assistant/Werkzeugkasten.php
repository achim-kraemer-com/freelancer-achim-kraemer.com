<?php

declare(strict_types=1);

namespace App\Service\Assistant;

use App\Service\Assistant\Werkzeuge\Werkzeugbereich;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Die Werkzeuge, die der Assistent bedienen darf. Lesen darf er überall –
 * Kunden, Projekte, Aufgaben, Rechnungen, Zeiten –, anlegen und ändern nur
 * Kunden, Projekte, Aufgaben, Zeiteinträge und den Status einer Rechnung.
 * Jedes Werkzeug ist entweder lesend oder schreibend:
 *
 *  - Lesende laufen sofort (run()); sie ändern nichts und liefern dem Modell
 *    die IDs, die es zum Ändern braucht, und den Pfad, unter dem der Eintrag
 *    in der Verwaltung steht.
 *  - Schreibende werden nur geprüft und beschrieben (plan()). Ausgeführt wird
 *    erst, wenn der Benutzer die Vorschau bestätigt – dann über apply().
 *
 * Die Werkzeuge selbst stehen nach Bereichen getrennt unter Werkzeuge/; hier
 * wird nur jeder Aufruf an den Bereich weitergereicht, der das Werkzeug
 * beschreibt.
 *
 * Geplante Aktionen sind reine Arrays, weil sie zwischen den beiden Schritten
 * in der Sitzung liegen; dort sollen keine Objekte einer Bibliothek landen,
 * die ein Deployment austauschen könnte.
 */
final class Werkzeugkasten
{
    /** @var list<Werkzeugbereich> */
    private readonly array $bereiche;

    /** @var array<string, Werkzeugbereich> Werkzeugname => zuständiger Bereich */
    private array $zustaendig = [];

    /** @var array<string, true> */
    private array $schreibend = [];

    /** @param iterable<Werkzeugbereich> $bereiche */
    public function __construct(
        #[AutowireIterator(Werkzeugbereich::TAG)]
        iterable $bereiche,
    ) {
        $this->bereiche = \is_array($bereiche) ? \array_values($bereiche) : \iterator_to_array($bereiche, false);

        foreach ($this->bereiche as $bereich) {
            foreach ($bereich->definitionen() as $definition) {
                $this->zustaendig[$definition['name']] = $bereich;
            }

            foreach ($bereich->schreibende() as $name) {
                $this->schreibend[$name] = true;
            }
        }
    }

    public function isWriting(string $tool): bool
    {
        return isset($this->schreibend[$tool]);
    }

    /**
     * Die Werkzeugbeschreibungen – anbieterneutral.
     *
     * Anthropic und die OpenAI-kompatiblen Anbieter erwarten dieselben
     * JSON-Schemata, nur unter anderen Schlüsseln. Die Umbenennung übernimmt
     * die jeweilige ChatModel-Umsetzung.
     *
     * @return list<array{name: string, description: string, schema: array<string, mixed>}>
     */
    public function definitions(): array
    {
        return \array_merge(...\array_map(static fn (Werkzeugbereich $b) => $b->definitionen(), $this->bereiche));
    }

    /**
     * Führt ein lesendes Werkzeug aus. Das Ergebnis geht als Text an das Modell
     * zurück – JSON, weil es die Zuordnung von ID zu Eintrag am klarsten macht.
     *
     * @param array<string, mixed> $input
     */
    public function run(string $tool, array $input): string
    {
        $bereich = $this->zustaendig[$tool] ?? null;

        if ($bereich === null || $this->isWriting($tool)) {
            return 'Fehler: Unbekanntes Werkzeug "'.$tool.'".';
        }

        return $bereich->lesen($tool, $input);
    }

    /**
     * Prüft einen schreibenden Werkzeugaufruf und beschreibt ihn, führt ihn
     * aber noch nicht aus.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, message: string, action?: array{tool: string, summary: string, details: array<string, string>, input: array<string, mixed>}}
     */
    public function plan(string $tool, array $input): array
    {
        if (!$this->isWriting($tool)) {
            return ['ok' => false, 'message' => 'Fehler: Unbekanntes Werkzeug "'.$tool.'".'];
        }

        try {
            return $this->zustaendig[$tool]->planen($tool, $input);
        } catch (BadToolInput $e) {
            // Zurück an das Modell, damit es die Angabe berichtigen kann.
            return ['ok' => false, 'message' => 'Fehler: '.$e->getMessage()];
        }
    }

    /**
     * Führt eine bestätigte Aktion aus. Die Eingaben werden dabei erneut
     * geprüft – zwischen Vorschau und Bestätigung kann sich alles geändert
     * haben, etwa weil die Aufgabe inzwischen in einer Rechnung steht.
     *
     * @param array{tool: string, input: array<string, mixed>} $action
     *
     * @throws BadToolInput wenn die Aktion inzwischen nicht mehr ausführbar ist
     */
    public function apply(array $action): Ausgefuehrt
    {
        if (!$this->isWriting($action['tool'])) {
            throw new BadToolInput('Unbekanntes Werkzeug "'.$action['tool'].'".');
        }

        return $this->zustaendig[$action['tool']]->uebernehmen($action['tool'], $action['input']);
    }
}

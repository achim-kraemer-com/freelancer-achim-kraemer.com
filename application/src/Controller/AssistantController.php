<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssistantModel;
use App\Entity\User;
use App\Repository\AssistantModelRepository;
use App\Service\Assistant\Assistant;
use App\Service\Assistant\AssistantReply;
use App\Service\Assistant\AssistantUnavailable;
use App\Service\Assistant\BadToolInput;
use App\Service\Assistant\Werkzeugkasten;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Assistent – meist im Fenster hinter dem „A" in der Navigation
 * (public/js/assistent.js), ohne JavaScript auf eigener Seite.
 *
 * Nach dem Vorbild des Portals (dort AssistantController).
 *
 * Das Fenster schickt jede Anfrage mit `teil=1` und bekommt statt einer
 * Weiterleitung das Gespräch als fertiges HTML zurück; Fehler stehen dann
 * darin statt als Meldung über der Seite.
 *
 * Gespräch und vorgemerkte Änderungen liegen in der Sitzung, nicht in der
 * Datenbank: Wert hat, was am Ende in Kunden, Aufgaben und Rechnungen steht –
 * der Gesprächsverlauf ist nur der Weg dorthin und darf mit der Anmeldung
 * enden.
 *
 * Mit `strom=1` kommt die Antwort, während das Modell schreibt: als Zeilen
 * JSON (NDJSON) mit Textstücken und dem, was gerade nachgesehen wird. In die
 * Sitzung kann ein Strom nicht mehr schreiben – die Kopfzeilen sind dann
 * schon unterwegs. Das Ergebnis wartet deshalb kurz im Cache, und das Fenster
 * holt es mit /abschliessen ab; erst diese gewöhnliche Anfrage legt es in die
 * Sitzung und liefert das fertige Gespräch.
 */
#[Route('/admin/assistent', name: 'admin_assistant_')]
#[IsGranted('ROLE_ADMIN')]
class AssistantController extends AbstractController
{
    private const HISTORY = 'assistant_history';
    private const PENDING = 'assistant_pending';

    /** So viele Beiträge bleiben im Verlauf; ältere fallen heraus. */
    private const MAX_VERLAUF = 20;

    private const MAX_ZEICHEN = 2000;

    /** So lange wartet ein gestreamtes Ergebnis auf seine Abholung. */
    private const ABHOLFRIST = 600;

    public function __construct(
        private readonly Assistant $assistant,
        private readonly Werkzeugkasten $tools,
        private readonly AssistantModelRepository $models,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->alsTeil($request)) {
            return $this->render('assistant/_gespraech.html.twig', $this->gespraech($request));
        }

        return $this->render('assistant/index.html.twig', $this->gespraech($request));
    }

    #[Route('/fragen', name: 'ask', methods: ['POST'])]
    public function ask(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('assistant_ask', (string) $request->request->get('_token'))) {
            return $this->antwort($request, ['Die Seite war zu lange offen. Bitte noch einmal senden.']);
        }

        /** @var User $user */
        $user    = $this->getUser();
        $session = $request->getSession();
        $frage   = \trim((string) $request->request->get('nachricht'));

        if ($frage === '') {
            return $this->antwort($request);
        }

        // Es antwortet immer die Vorgabe – gewählt unter Einstellungen → Modelle.
        $modell = $this->models->findDefaultFor($user);

        if ($modell === null) {
            if (!$this->alsTeil($request)) {
                $this->addFlash('error', 'Es ist noch kein Modell angeschlossen.');

                return $this->redirectToRoute('admin_assistant_model_index');
            }

            return $this->antwort($request);
        }

        if (\mb_strlen($frage) > self::MAX_ZEICHEN) {
            $frage = \mb_substr($frage, 0, self::MAX_ZEICHEN);
        }

        $verlauf = $session->get(self::HISTORY, []);

        // Eine neue Eingabe ersetzt eine offene Vorschau – meist ist sie
        // genau die Korrektur an dem, was gerade vorgeschlagen wurde.
        $session->remove(self::PENDING);

        if ($this->alsTeil($request) && $request->query->getBoolean('strom')) {
            return $this->strom($frage, $verlauf, $user, $modell);
        }

        try {
            $antwort = $this->assistant->ask($frage, $verlauf, $user, $modell);
        } catch (AssistantUnavailable $e) {
            return $this->antwort($request, [$e->getMessage()]);
        }

        $this->merken($request, $frage, $antwort);

        return $this->antwort($request);
    }

    /**
     * Holt ein gestreamtes Ergebnis ab und legt es in die Sitzung. Der
     * Schlüssel stammt aus dem Strom desselben Benutzers; ein fremder oder
     * abgelaufener findet nichts.
     */
    #[Route('/abschliessen', name: 'finish', methods: ['POST'])]
    public function finish(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('assistant_ask', (string) $request->request->get('_token'))) {
            return $this->antwort($request);
        }

        /** @var User $user */
        $user    = $this->getUser();
        $eintrag = $this->cache->getItem(self::cacheSchluessel($user, (string) $request->request->get('schluessel')));

        if ($eintrag->isHit()) {
            /** @var array{frage: string, text: string, aktionen: list<array<string, mixed>>} $ergebnis */
            $ergebnis = $eintrag->get();
            $this->cache->deleteItem($eintrag->getKey());
            $this->merken($request, $ergebnis['frage'], new AssistantReply($ergebnis['text'], $ergebnis['aktionen']));
        }

        return $this->antwort($request);
    }

    #[Route('/uebernehmen', name: 'apply', methods: ['POST'])]
    public function apply(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('assistant_apply', (string) $request->request->get('_token'))) {
            return $this->antwort($request);
        }

        $session = $request->getSession();
        $offen   = $session->get(self::PENDING, []);

        if ($offen === []) {
            return $this->antwort($request);
        }

        $erledigt = [];
        $fehler   = [];

        foreach ($offen as $aktion) {
            try {
                $erledigt[] = $this->tools->apply($aktion);
            } catch (BadToolInput $e) {
                $fehler[] = $aktion['summary'].': '.$e->getMessage();
            }
        }

        $session->remove(self::PENDING);

        // Was übernommen wurde, gehört in den Verlauf – sonst weiß weder der
        // Benutzer noch das Modell beim Nachfassen, was inzwischen geschehen ist.
        // Die Links darin führen auf das Angelegte und Geänderte.
        if ($erledigt !== []) {
            $text      = 'Übernommen: '.\implode(' ', \array_map(static fn ($e) => $e->text, $erledigt));
            $verlauf   = $session->get(self::HISTORY, []);
            $verlauf[] = [
                'role'  => 'assistant',
                'text'  => $text,
                'links' => \array_values(\array_filter(
                    \array_map(static fn ($e) => $e->link === null ? null : ['titel' => (string) $e->titel, 'link' => $e->link], $erledigt),
                )),
            ];
            $session->set(self::HISTORY, \array_slice($verlauf, -self::MAX_VERLAUF));

            if (!$this->alsTeil($request)) {
                $this->addFlash('success', $text);
            }
        }

        return $this->antwort($request, $fehler);
    }

    #[Route('/verwerfen', name: 'discard', methods: ['POST'])]
    public function discard(Request $request): Response
    {
        if ($this->isCsrfTokenValid('assistant_discard', (string) $request->request->get('_token'))) {
            $request->getSession()->remove(self::PENDING);
        }

        return $this->antwort($request);
    }

    #[Route('/leeren', name: 'clear', methods: ['POST'])]
    public function clear(Request $request): Response
    {
        if ($this->isCsrfTokenValid('assistant_clear', (string) $request->request->get('_token'))) {
            $session = $request->getSession();
            $session->remove(self::HISTORY);
            $session->remove(self::PENDING);
        }

        return $this->antwort($request);
    }

    /** @param list<array{role: string, text: string}> $verlauf */
    private function strom(string $frage, array $verlauf, User $user, AssistantModel $modell): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($frage, $verlauf, $user, $modell): void {
            $senden = static function (array $zeile): void {
                echo \json_encode($zeile, \JSON_UNESCAPED_UNICODE)."\n";

                if (\ob_get_level() > 0) {
                    \ob_flush();
                }

                \flush();
            };

            try {
                $antwort = $this->assistant->ask(
                    $frage,
                    $verlauf,
                    $user,
                    $modell,
                    static function (string $art, string $inhalt) use ($senden): void {
                        $senden(['art' => $art, 'inhalt' => $inhalt]);
                    },
                );
            } catch (AssistantUnavailable $e) {
                $senden(['art' => 'fehler', 'inhalt' => $e->getMessage()]);

                return;
            }

            $schluessel = \bin2hex(\random_bytes(16));
            $eintrag    = $this->cache->getItem(self::cacheSchluessel($user, $schluessel));
            $eintrag->set(['frage' => $frage, 'text' => $antwort->text, 'aktionen' => $antwort->actions]);
            $eintrag->expiresAfter(self::ABHOLFRIST);
            $this->cache->save($eintrag);

            $senden(['art' => 'fertig', 'inhalt' => $schluessel]);
        });

        $response->headers->set('Content-Type', 'application/x-ndjson; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache');
        // nginx vor Apache (Plesk) hielte die Zeilen sonst zurück, bis alles da ist.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private static function cacheSchluessel(User $user, string $schluessel): string
    {
        return 'assistent_strom_'.(int) $user->getId().'_'.\preg_replace('/[^a-f0-9]/', '', $schluessel);
    }

    /** Frage und Antwort in den Verlauf, die Änderungen in die Vorschau. */
    private function merken(Request $request, string $frage, AssistantReply $antwort): void
    {
        $session   = $request->getSession();
        $verlauf   = $session->get(self::HISTORY, []);
        $verlauf[] = ['role' => 'user', 'text' => $frage];
        $verlauf[] = ['role' => 'assistant', 'text' => $antwort->text];

        $session->set(self::HISTORY, \array_slice($verlauf, -self::MAX_VERLAUF));

        if ($antwort->actions !== []) {
            $session->set(self::PENDING, $antwort->actions);
        }
    }

    /**
     * Für das Fenster das Gespräch als HTML, sonst zurück auf die Seite.
     *
     * @param list<string> $fehler
     */
    private function antwort(Request $request, array $fehler = []): Response
    {
        if ($this->alsTeil($request)) {
            return $this->render('assistant/_gespraech.html.twig', ['fehler' => $fehler] + $this->gespraech($request));
        }

        foreach ($fehler as $meldung) {
            $this->addFlash('error', $meldung);
        }

        return $this->redirectToRoute('admin_assistant_index');
    }

    /** @return array<string, mixed> */
    private function gespraech(Request $request): array
    {
        /** @var User $user */
        $user    = $this->getUser();
        $session = $request->getSession();

        return [
            'verlauf'  => $session->get(self::HISTORY, []),
            'vorschau' => $session->get(self::PENDING, []),
            'aktiv'    => $this->models->findDefaultFor($user),
            'fehler'   => [],
        ];
    }

    private function alsTeil(Request $request): bool
    {
        return $request->query->getBoolean('teil');
    }
}

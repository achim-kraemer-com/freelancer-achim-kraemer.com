<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\AssistantModelRepository;
use App\Service\Assistant\AssistantUnavailable;
use App\Service\Suche\Gesamtsuche;
use App\Service\Suche\KiSuchplaner;
use App\Service\Suche\Suchplan;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Suche hinter der Lupe in der Navigation: über Kunden, Projekte,
 * Aufgaben, Rechnungen und Zeiten zugleich.
 *
 * Das Such-Fenster (public/js/suche.js) holt mit `teil=1` nur die Ergebnisse
 * und setzt sie unter das Suchfeld. Ohne JavaScript führt die Lupe auf diese
 * Seite selbst, mit eigenem Suchfeld.
 *
 * Nach dem Vorbild des Portals (dort SucheController).
 */
#[Route('/admin/suche', name: 'admin_suche')]
#[IsGranted('ROLE_ADMIN')]
class SucheController extends AbstractController
{
    private const MAX_ZEICHEN = 200;

    public function __construct(
        private readonly KiSuchplaner $planer,
        private readonly Gesamtsuche $suche,
        private readonly AssistantModelRepository $models,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user    = $this->getUser();
        $q       = \mb_substr(\trim((string) $request->query->get('q', '')), 0, self::MAX_ZEICHEN);
        $plan    = null;
        $treffer = [];
        $hinweis = null;

        if ($q !== '') {
            $plan = Suchplan::wortlaut($q);

            // Wie im Assistenten: das Modell, das unter Modelle Vorgabe ist.
            $modell = $this->models->findDefaultFor($user);

            if ($modell === null) {
                $hinweis = 'Ohne angeschlossenes Modell wird nach dem Wortlaut gesucht.';
            } else {
                try {
                    $plan = $this->planer->planen($q, $modell);
                } catch (AssistantUnavailable $e) {
                    $hinweis = $e->getMessage().' Gesucht wurde deshalb nach dem Wortlaut.';
                }
            }

            $treffer = $this->suche->suchen($plan);
        }

        return $this->render($request->query->getBoolean('teil') ? 'suche/_ergebnisse.html.twig' : 'suche/index.html.twig', [
            'q'       => $q,
            'plan'    => $plan,
            'treffer' => $treffer,
            'hinweis' => $hinweis,
        ]);
    }
}

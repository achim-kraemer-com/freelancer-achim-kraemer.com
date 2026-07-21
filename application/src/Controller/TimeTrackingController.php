<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Task;
use App\Entity\TimeEntry;
use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\TimeEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/time-tracking', name: 'admin_time_tracking_')]
#[IsGranted('ROLE_ADMIN')]
class TimeTrackingController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(TaskRepository $tasks, TimeEntryRepository $timeEntries): Response
    {
        return $this->render('time_tracking/index.html.twig', [
            'user'          => $this->getUser(),
            'tasks'         => $tasks->findOpenForTracking(),
            'recentEntries' => $timeEntries->findRecent(10),
        ]);
    }

    #[Route('/start', name: 'start', methods: ['POST'])]
    public function start(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('time-tracking', (string) $request->request->get('_token'))) {
            $this->user()->startTimer();
            $em->flush();
        }

        return $this->redirectToRoute('admin_time_tracking_index');
    }

    #[Route('/stop', name: 'stop', methods: ['POST'])]
    public function stop(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('time-tracking', (string) $request->request->get('_token'))) {
            $this->user()->stopTimer();
            $em->flush();
        }

        return $this->redirectToRoute('admin_time_tracking_index');
    }

    #[Route('/reset', name: 'reset', methods: ['POST'])]
    public function reset(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('time-tracking', (string) $request->request->get('_token'))) {
            $this->user()->resetTimer();
            $em->flush();
        }

        return $this->redirectToRoute('admin_time_tracking_index');
    }

    /**
     * "Aufgabe beenden": stop the stopwatch, round the measured time up to full
     * minutes and add it to the selected task's hours (which also increases the
     * project total). Finally the stopwatch is reset.
     */
    #[Route('/finish', name: 'finish', methods: ['POST'])]
    public function finish(Request $request, TaskRepository $tasks, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('time-tracking', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_time_tracking_index');
        }

        $user = $this->user();
        $user->stopTimer();

        $taskId = (int) $request->request->get('task');
        $task   = $taskId > 0 ? $tasks->find($taskId) : null;

        if (!$task instanceof Task || $task->isInvoiced()) {
            $this->addFlash('error', 'Bitte eine offene Aufgabe auswählen.');

            return $this->redirectToRoute('admin_time_tracking_index');
        }

        $seconds = $user->getElapsedSeconds();
        if ($seconds <= 0) {
            $this->addFlash('error', 'Es wurde keine Zeit gemessen.');

            return $this->redirectToRoute('admin_time_tracking_index');
        }

        // Round the measured time up to full minutes, then express it as hours.
        $minutes   = (int) \ceil($seconds / 60);
        $addedHours = $minutes / 60;

        $newHours = \round((float) ($task->getHours() ?? '0') + $addedHours, 2);
        $task->setHours((string) $newHours);

        // Record a protocol entry (date, duration, note) for this booking.
        $note = trim((string) $request->request->get('note'));
        $entry = (new TimeEntry())
            ->setTask($task)
            ->setSeconds($seconds)
            ->setMinutes($minutes)
            ->setNote($note !== '' ? $note : null);
        $em->persist($entry);

        $user->resetTimer();
        $em->flush();

        $this->addFlash('success', \sprintf(
            '%d Min. (%s Std.) zu „%s" hinzugefügt. Neuer Stand: %s Std.',
            $minutes,
            \number_format($addedHours, 2, ',', '.'),
            $task->getName(),
            \number_format($newHours, 2, ',', '.'),
        ));

        return $this->redirectToRoute('admin_time_tracking_index');
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}

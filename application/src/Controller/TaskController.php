<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Project;
use App\Entity\Task;
use App\Form\TaskType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_task_')]
#[IsGranted('ROLE_ADMIN')]
class TaskController extends AbstractController
{
    #[Route('/project/{id}/task/new', name: 'new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $task = new Task();
        $task->setProject($project);
        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($task);
            $em->flush();
            $this->addFlash('success', 'Aufgabe hinzugefügt.');

            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        return $this->render('task/new.html.twig', [
            'form'    => $form,
            'project' => $project,
        ]);
    }

    #[Route('/task/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Task $task, Request $request, EntityManagerInterface $em): Response
    {
        if ($task->isInvoiced()) {
            $this->addFlash('error', 'Bereits abgerechnete Aufgaben können nicht bearbeitet werden.');

            return $this->redirectToRoute('admin_project_show', ['id' => $task->getProject()->getId()]);
        }

        $form = $this->createForm(TaskType::class, $task);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Aufgabe aktualisiert.');

            return $this->redirectToRoute('admin_project_show', ['id' => $task->getProject()->getId()]);
        }

        return $this->render('task/edit.html.twig', [
            'form' => $form,
            'task' => $task,
        ]);
    }

    #[Route('/task/{id}/status', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(Task $task, Request $request, EntityManagerInterface $em): Response
    {
        $status = (string) $request->request->get('status');

        if ($this->isCsrfTokenValid('status'.$task->getId(), (string) $request->request->get('_token'))
            && \in_array($status, Task::STATUS_CHOICES, true)
        ) {
            $task->setStatus($status);
            $em->flush();
            $this->addFlash('success', 'Status auf "'.$status.'" gesetzt.');
        }

        return $this->redirectToRoute('admin_project_show', ['id' => $task->getProject()->getId()]);
    }

    #[Route('/task/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Task $task, Request $request, EntityManagerInterface $em): Response
    {
        $projectId = $task->getProject()?->getId();
        if ($this->isCsrfTokenValid('delete'.$task->getId(), (string) $request->request->get('_token'))) {
            if ($task->isInvoiced()) {
                $this->addFlash('error', 'Abgerechnete Aufgaben können nicht gelöscht werden.');
            } else {
                $em->remove($task);
                $em->flush();
                $this->addFlash('success', 'Aufgabe gelöscht.');
            }
        }

        return $this->redirectToRoute('admin_project_show', ['id' => $projectId]);
    }
}

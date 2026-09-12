<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Project;
use App\Enum\InvoiceTemplate;
use App\Form\ProjectType;
use App\Repository\InvoiceRepository;
use App\Repository\TaskRepository;
use App\Service\InvoicePdfBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_project_')]
#[IsGranted('ROLE_ADMIN')]
class ProjectController extends AbstractController
{
    #[Route('/customer/{id}/project/new', name: 'new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Customer $customer, Request $request, EntityManagerInterface $em): Response
    {
        $project = new Project();
        $project->setCustomer($customer);
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($project);
            $em->flush();
            $this->addFlash('success', 'Projekt angelegt.');

            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/new.html.twig', [
            'form'     => $form,
            'customer' => $customer,
        ]);
    }

    #[Route('/project/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Project $project): Response
    {
        return $this->render('project/show.html.twig', ['project' => $project]);
    }

    #[Route('/project/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(ProjectType::class, $project);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Projekt aktualisiert.');

            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        return $this->render('project/edit.html.twig', [
            'form'    => $form,
            'project' => $project,
        ]);
    }

    #[Route('/project/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Project $project, Request $request, EntityManagerInterface $em): Response
    {
        $customerId = $project->getCustomer()?->getId();
        if ($this->isCsrfTokenValid('delete'.$project->getId(), (string) $request->request->get('_token'))) {
            $em->remove($project);
            $em->flush();
            $this->addFlash('success', 'Projekt gelöscht.');
        }

        return $this->redirectToRoute('admin_customer_show', ['id' => $customerId]);
    }

    #[Route('/project/{id}/create-invoice', name: 'create_invoice', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function createInvoice(
        Project $project,
        Request $request,
        TaskRepository $taskRepository,
        InvoiceRepository $invoiceRepository,
        EntityManagerInterface $em,
        InvoicePdfBuilder $pdfBuilder,
    ): Response {
        if (!$this->isCsrfTokenValid('create-invoice'.$project->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        /** @var int[] $selectedIds */
        $selectedIds = array_map('intval', (array) $request->request->all('tasks'));
        $tasks       = $taskRepository->findByIdsForProject($selectedIds, $project->getId());

        if ([] === $tasks) {
            $this->addFlash('error', 'Bitte mindestens eine offene Aufgabe auswählen.');

            return $this->redirectToRoute('admin_project_show', ['id' => $project->getId()]);
        }

        $customer    = $project->getCustomer();
        $invoiceNo   = \date('y').'-'.($project->getId() + 1000).'-'.($invoiceRepository->countForProject($project->getId()) + 1);
        $invoiceDate = new \DateTime();

        $invoice = new Invoice();
        $invoice->setNumber($invoiceNo);
        $invoice->setCustomer($customer);
        $invoice->setProject($project);
        $invoice->setTemplate(InvoiceTemplate::tryFrom((string) $request->request->get('template')) ?? InvoiceTemplate::AchimKraemer);
        $invoice->setInvoiceDate($invoiceDate);
        $invoice->setDueDate((clone $invoiceDate)->modify('+'.Invoice::DEFAULT_PAYMENT_TERM_DAYS.' days'));
        $invoice->setStatus(Invoice::STATUS_CREATED);

        $total = 0.0;
        foreach ($tasks as $task) {
            $total += $task->getCost();
            $task->setInvoiced(true);
            $invoice->addTask($task);
            $em->persist($task);
        }
        $invoice->setTotalAmount(\number_format($total, 2, '.', ''));

        $invoice->setPdfFilename($pdfBuilder->generate($invoice));

        $em->persist($invoice);
        $em->flush();

        $this->addFlash('success', 'Rechnung '.$invoiceNo.' erstellt.');

        return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
    }
}

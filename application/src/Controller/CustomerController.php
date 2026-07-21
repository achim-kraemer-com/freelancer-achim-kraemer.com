<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/customer', name: 'admin_customer_')]
#[IsGranted('ROLE_ADMIN')]
class CustomerController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CustomerRepository $customers): Response
    {
        $q      = trim((string) $request->query->get('q'));
        $active = (string) $request->query->get('active');
        $page   = (int) $request->query->get('page', 1);

        return $this->render('customer/index.html.twig', [
            'pagination' => $customers->paginate($q ?: null, $active ?: null, $page),
            'q'          => $q,
            'active'     => $active,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $customer = new Customer();
        $form     = $this->createForm(CustomerType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($customer);
            $em->flush();
            $this->addFlash('success', 'Kunde angelegt.');

            return $this->redirectToRoute('admin_customer_show', ['id' => $customer->getId()]);
        }

        return $this->render('customer/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Customer $customer): Response
    {
        $paidTotal = $openTotal = $overdueTotal = 0.0;
        foreach ($customer->getInvoices() as $invoice) {
            $amount = (float) $invoice->getTotalAmount();
            if ($invoice->getStatus() === Invoice::STATUS_PAID) {
                $paidTotal += $amount;
            } else {
                $openTotal += $amount;
                if ($invoice->isOverdue()) {
                    $overdueTotal += $amount;
                }
            }
        }

        $openTasks = 0;
        foreach ($customer->getProjects() as $project) {
            foreach ($project->getTasks() as $task) {
                if (!$task->isInvoiced()) {
                    ++$openTasks;
                }
            }
        }

        // Invoices newest first for the listing.
        $invoices = $customer->getInvoices()->toArray();
        usort($invoices, static fn (Invoice $a, Invoice $b): int => $b->getInvoiceDate() <=> $a->getInvoiceDate());

        return $this->render('customer/show.html.twig', [
            'customer'     => $customer,
            'paidTotal'    => $paidTotal,
            'openTotal'    => $openTotal,
            'overdueTotal' => $overdueTotal,
            'openTasks'    => $openTasks,
            'invoices'     => $invoices,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, Customer $customer, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(CustomerType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'Kunde aktualisiert.');

            return $this->redirectToRoute('admin_customer_show', ['id' => $customer->getId()]);
        }

        return $this->render('customer/edit.html.twig', [
            'form'     => $form,
            'customer' => $customer,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, Customer $customer, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$customer->getId(), (string) $request->request->get('_token'))) {
            $em->remove($customer);
            $em->flush();
            $this->addFlash('success', 'Kunde gelöscht.');
        }

        return $this->redirectToRoute('admin_customer_index');
    }
}

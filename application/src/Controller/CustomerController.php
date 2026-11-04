<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Form\CustomerType;
use App\Repository\CustomerRepository;
use App\Service\ContactToCustomer;
use App\Service\PortalContacts;
use App\Service\PortalError;
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
    /** How many portal contacts the picker shows at once. */
    private const int PORTAL_LIMIT = 100;

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
    public function new(Request $request, EntityManagerInterface $em, PortalContacts $portal): Response
    {
        $customer = new Customer();
        $contact  = null;

        // A contact from the portal fills the form in advance – only when
        // the page is opened, not when it comes back: what the user has
        // corrected in between must not be overwritten by the guess again.
        $contactId = $request->query->getInt('kontakt');

        if ($contactId > 0 && !$request->isMethod('POST')) {
            try {
                $contact = $portal->find($contactId);

                if ($contact === null) {
                    $this->addFlash('error', 'Diesen Kontakt kennt das Portal nicht (mehr).');
                } else {
                    $customer = ContactToCustomer::convert($contact);
                }
            } catch (PortalError $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        $form = $this->createForm(CustomerType::class, $customer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($customer);
            $em->flush();
            $this->addFlash('success', 'Kunde angelegt.');

            return $this->redirectToRoute('admin_customer_show', ['id' => $customer->getId()]);
        }

        return $this->render('customer/new.html.twig', [
            'form'    => $form,
            'contact' => $contact,
        ]);
    }

    /**
     * The portal's address book, to pick from.
     *
     * Only a list with a search field: picking leads to the "new customer"
     * form, pre-filled. Nothing is saved here, so a portal that is down or a
     * token that is missing costs a sentence on the page and not an error.
     */
    #[Route('/portal', name: 'portal', methods: ['GET'])]
    public function portal(Request $request, PortalContacts $portal): Response
    {
        $q        = trim((string) $request->query->get('q'));
        $contacts = [];
        $error    = null;

        try {
            $contacts = $portal->search($q ?: null, self::PORTAL_LIMIT);
        } catch (PortalError $e) {
            $error = $e->getMessage();
        }

        return $this->render('customer/portal.html.twig', [
            'contacts'  => $contacts,
            'q'         => $q,
            'error'     => $error,
            'limit'     => self::PORTAL_LIMIT,
            // A full page means there may well be more behind it. Saying so
            // beats letting someone conclude a contact is not in the portal.
            'truncated' => \count($contacts) === self::PORTAL_LIMIT,
        ]);
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

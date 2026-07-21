<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\InvoiceDispatch;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/invoice', name: 'admin_invoice_')]
#[IsGranted('ROLE_ADMIN')]
class InvoiceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, InvoiceRepository $invoices): Response
    {
        $q      = trim((string) $request->query->get('q'));
        $status = (string) $request->query->get('status');
        $page   = (int) $request->query->get('page', 1);

        return $this->render('invoice/index.html.twig', [
            'pagination' => $invoices->paginate($q ?: null, $status ?: null, $page),
            'q'          => $q,
            'status'     => $status,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Invoice $invoice): Response
    {
        return $this->render('invoice/show.html.twig', ['invoice' => $invoice]);
    }

    #[Route('/{id}/status', name: 'status', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function status(Invoice $invoice, Request $request, EntityManagerInterface $em): Response
    {
        $status = (string) $request->request->get('status');

        if ($this->isCsrfTokenValid('status'.$invoice->getId(), (string) $request->request->get('_token'))
            && \in_array($status, Invoice::STATUS_CHOICES, true)
        ) {
            $invoice->setStatus($status);

            // Track the payment date: set it when marked paid, clear it otherwise.
            if ($status === Invoice::STATUS_PAID) {
                if ($invoice->getPaidAt() === null) {
                    $invoice->setPaidAt(new \DateTime('today'));
                }
            } else {
                $invoice->setPaidAt(null);
            }

            $em->flush();
            $this->addFlash('success', 'Status auf "'.$status.'" gesetzt.');
        }

        return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/send', name: 'send', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function send(
        Invoice $invoice,
        Request $request,
        EntityManagerInterface $em,
        MailerInterface $mailer,
        ContainerBagInterface $params,
    ): Response {
        if (!$this->isCsrfTokenValid('send'.$invoice->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
        }

        $mailerFrom     = (string) $params->get('mailer_from');
        $mailerFromName = (string) $params->get('mailer_from_name');

        $recipient = $invoice->getCustomer()?->getEmail();
        if (!$recipient) {
            $this->addFlash('error', 'Der Kunde hat keine E-Mail-Adresse hinterlegt.');

            return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
        }

        $filename = $invoice->getPdfFilename() ?? ('Rechnung-'.$invoice->getNumber().'.pdf');
        $filePath = $params->get('invoices_path').$filename;
        if (!is_file($filePath)) {
            $this->addFlash('error', 'Das Rechnungs-PDF wurde nicht gefunden.');

            return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
        }

        $body = \sprintf(
            "Guten Tag,\n\nanbei erhalten Sie die Rechnung %s vom %s über %s €.\n\n"
            ."Vielen Dank für die Zusammenarbeit.\n\nMit freundlichen Grüßen\n%s",
            $invoice->getNumber(),
            $invoice->getInvoiceDate()?->format('d.m.Y'),
            \number_format((float) $invoice->getTotalAmount(), 2, ',', '.'),
            $mailerFromName,
        );

        $email = (new Email())
            ->from(new Address($mailerFrom, $mailerFromName))
            ->replyTo($mailerFrom)
            ->to($recipient)
            ->subject('Rechnung '.$invoice->getNumber())
            ->text($body)
            ->attachFromPath($filePath, $filename, 'application/pdf');

        try {
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', 'E-Mail konnte nicht gesendet werden: '.$e->getMessage());

            return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
        }

        // Record when and to whom the invoice was sent (kept for every dispatch).
        $dispatch = (new InvoiceDispatch())->setRecipient($recipient);
        $invoice->addDispatch($dispatch);

        // A freshly created invoice becomes "verschickt" once it has been sent.
        if ($invoice->getStatus() === Invoice::STATUS_CREATED) {
            $invoice->setStatus(Invoice::STATUS_SENT);
        }

        $em->flush();

        $this->addFlash('success', 'Rechnung wurde an '.$recipient.' gesendet.');

        return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/due-date', name: 'due_date', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function dueDate(Invoice $invoice, Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('due-date'.$invoice->getId(), (string) $request->request->get('_token'))) {
            $value = (string) $request->request->get('due_date');
            $date  = $value !== '' ? \DateTime::createFromFormat('Y-m-d', $value) : null;

            if ($value !== '' && !$date instanceof \DateTime) {
                $this->addFlash('error', 'Ungültiges Datum.');
            } else {
                $invoice->setDueDate($date ?: null);
                $em->flush();
                $this->addFlash('success', 'Fälligkeit aktualisiert.');
            }
        }

        return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/download', name: 'download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function download(Invoice $invoice, ContainerBagInterface $params): Response
    {
        $filename = $invoice->getPdfFilename() ?? ('Rechnung-'.$invoice->getNumber().'.pdf');
        $filePath = $params->get('invoices_path').$filename;

        if (!is_file($filePath)) {
            throw $this->createNotFoundException('PDF nicht gefunden.');
        }

        $response = new BinaryFileResponse($filePath);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Invoice $invoice, Request $request, EntityManagerInterface $em, ContainerBagInterface $params): Response
    {
        if ($this->isCsrfTokenValid('delete'.$invoice->getId(), (string) $request->request->get('_token'))) {
            // Release the tasks so they can be invoiced again.
            foreach ($invoice->getTasks() as $task) {
                $task->setInvoiced(false);
                $task->setInvoice(null);
                $em->persist($task);
            }

            if ($invoice->getPdfFilename()) {
                $filePath = $params->get('invoices_path').$invoice->getPdfFilename();
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            $em->remove($invoice);
            $em->flush();
            $this->addFlash('success', 'Rechnung gelöscht.');
        }

        return $this->redirectToRoute('admin_invoice_index');
    }
}

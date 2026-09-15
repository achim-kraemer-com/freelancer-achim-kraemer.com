<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Entity\InvoiceDispatch;
use App\Repository\InvoiceRepository;
use App\Repository\MailSignatureRepository;
use App\Service\InvoiceMailer;
use App\Service\MailSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
    public function show(Invoice $invoice, MailSignatureRepository $signatureRepository, MailSanitizer $sanitizer): Response
    {
        $signatures = $signatureRepository->findAllOrdered();

        // Preview per signature: HTML defused, remote images may stay – it is
        // one's own content, not a foreign tracking pixel.
        $previews = [];
        foreach ($signatures as $signature) {
            $html = $signature->asHtml();
            if ($html !== null) {
                $previews[(int) $signature->getId()] = $sanitizer->clean($html, allowRemoteImages: true);
            }
        }

        return $this->render('invoice/show.html.twig', [
            'invoice'    => $invoice,
            'signatures' => $signatures,
            'previews'   => $previews,
        ]);
    }

    #[Route('/{id}/signature', name: 'signature', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function signature(
        Invoice $invoice,
        Request $request,
        MailSignatureRepository $signatureRepository,
        EntityManagerInterface $em,
    ): Response {
        if ($this->isCsrfTokenValid('signature'.$invoice->getId(), (string) $request->request->get('_token'))) {
            // An empty or unknown id counts as "none".
            $signature = $signatureRepository->find((int) $request->request->get('signature'));
            $invoice->setSignature($signature);
            $em->flush();
            $this->addFlash('success', $signature !== null
                ? 'Signatur „'.$signature->getLabel().'“ wird beim Versand verwendet.'
                : 'Die Rechnung wird ohne Signatur versendet.');
        }

        return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
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
        InvoiceMailer $invoiceMailer,
        ContainerBagInterface $params,
    ): Response {
        if (!$this->isCsrfTokenValid('send'.$invoice->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('admin_invoice_show', ['id' => $invoice->getId()]);
        }

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

        try {
            $invoiceMailer->send($invoice, $recipient, $filePath, $filename);
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

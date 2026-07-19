<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Invoice;
use App\Repository\InvoiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/invoice', name: 'admin_invoice_')]
#[IsGranted('ROLE_ADMIN')]
class InvoiceController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(InvoiceRepository $invoices): Response
    {
        return $this->render('invoice/index.html.twig', [
            'invoices' => $invoices->findBy([], ['invoiceDate' => 'DESC', 'id' => 'DESC']),
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
            $em->flush();
            $this->addFlash('success', 'Status auf "'.$status.'" gesetzt.');
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

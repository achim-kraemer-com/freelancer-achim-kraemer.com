<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MailSignature;
use App\Form\MailSignatureType;
use App\Repository\MailSignatureRepository;
use App\Service\MailSanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages the e-mail signatures – following the same pattern as the portal's
 * signature settings. Which signature an invoice uses is chosen on the
 * invoice itself, not here.
 */
#[Route('/admin/signature', name: 'admin_signature_')]
#[IsGranted('ROLE_ADMIN')]
class SignatureController extends AbstractController
{
    public function __construct(
        private readonly MailSignatureRepository $signatures,
        private readonly EntityManagerInterface $em,
        private readonly MailSanitizer $sanitizer,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $signatures = $this->signatures->findAllOrdered();

        // Show HTML signatures defused; remote images may stay – it is one's
        // own content, not a foreign tracking pixel.
        $previews = [];

        foreach ($signatures as $signature) {
            $html = $signature->asHtml();

            if ($html !== null) {
                $previews[(int) $signature->getId()] = $this->sanitizer->clean($html, allowRemoteImages: true);
            }
        }

        return $this->render('signature/index.html.twig', [
            'signatures' => $signatures,
            'previews'   => $previews,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $signature = (new MailSignature())->setPosition($this->signatures->nextPosition());

        $form = $this->createForm(MailSignatureType::class, $signature);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->keepDefaultUnique($signature);
            $this->em->persist($signature);
            $this->em->flush();
            $this->addFlash('success', 'Signatur angelegt.');

            return $this->redirectToRoute('admin_signature_index');
        }

        return $this->render('signature/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(MailSignature $signature, Request $request): Response
    {
        $form = $this->createForm(MailSignatureType::class, $signature);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->keepDefaultUnique($signature);
            $this->em->flush();
            $this->addFlash('success', 'Signatur gespeichert.');

            return $this->redirectToRoute('admin_signature_index');
        }

        return $this->render('signature/edit.html.twig', ['form' => $form, 'signature' => $signature]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(MailSignature $signature, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete'.$signature->getId(), (string) $request->request->get('_token'))) {
            // Invoices that use it are left without a signature (SET NULL).
            $this->em->remove($signature);
            $this->em->flush();
            $this->addFlash('success', 'Signatur entfernt.');
        }

        return $this->redirectToRoute('admin_signature_index');
    }

    /** A letterhead has at most one default signature: the one just saved wins. */
    private function keepDefaultUnique(MailSignature $signature): void
    {
        $template = $signature->getDefaultForTemplate();

        if ($template !== null) {
            $this->signatures->clearDefaultFor($template, $signature);
        }
    }
}

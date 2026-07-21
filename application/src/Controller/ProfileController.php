<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangeEmailType;
use App\Form\ChangePasswordType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile', name: 'app_profile_')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = $this->user();

        $emailForm    = $this->createForm(ChangeEmailType::class, $user);
        $passwordForm = $this->createForm(ChangePasswordType::class);

        $emailForm->handleRequest($request);
        if ($emailForm->isSubmitted() && $emailForm->isValid()) {
            $em->flush();
            $this->addFlash('success', 'E-Mail-Adresse aktualisiert.');

            return $this->redirectToRoute('app_profile_index');
        }

        $passwordForm->handleRequest($request);
        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $user->setPassword($hasher->hashPassword($user, (string) $passwordForm->get('newPassword')->getData()));
            $em->flush();
            $this->addFlash('success', 'Passwort geändert.');

            return $this->redirectToRoute('app_profile_index');
        }

        return $this->render('profile/index.html.twig', [
            'emailForm'    => $emailForm,
            'passwordForm' => $passwordForm,
        ]);
    }

    #[Route('/2fa/enable', name: '2fa_enable', methods: ['GET', 'POST'])]
    public function enableTwoFactor(Request $request, EntityManagerInterface $em, TotpAuthenticatorInterface $totp): Response
    {
        $user = $this->user();
        if ($user->isTotpEnabled()) {
            $this->addFlash('info', 'Zwei-Faktor-Authentifizierung ist bereits aktiv.');

            return $this->redirectToRoute('app_profile_index');
        }

        $session = $request->getSession();
        $secret  = (string) $session->get('2fa_pending_secret', '');
        if ($secret === '') {
            $secret = $totp->generateSecret();
            $session->set('2fa_pending_secret', $secret);
        }

        // Work on a detached copy so the secret is only persisted after a valid code.
        $pendingUser = (clone $user)->setTotpSecret($secret);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('enable-2fa', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Ungültiges Formular, bitte erneut versuchen.');
            } elseif ($totp->checkCode($pendingUser, trim((string) $request->request->get('code')))) {
                $user->setTotpSecret($secret);
                $em->flush();
                $session->remove('2fa_pending_secret');
                $this->addFlash('success', 'Zwei-Faktor-Authentifizierung aktiviert.');

                return $this->redirectToRoute('app_profile_index');
            } else {
                $this->addFlash('error', 'Der Code war nicht korrekt. Bitte erneut versuchen.');
            }
        }

        $qrDataUri = (new PngWriter())->write(new QrCode($totp->getQRContent($pendingUser)))->getDataUri();

        return $this->render('profile/2fa_enable.html.twig', [
            'qrDataUri' => $qrDataUri,
            'secret'    => $secret,
        ]);
    }

    #[Route('/2fa/disable', name: '2fa_disable', methods: ['POST'])]
    public function disableTwoFactor(Request $request, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('disable-2fa', (string) $request->request->get('_token'))) {
            $this->user()->setTotpSecret(null);
            $em->flush();
            $this->addFlash('success', 'Zwei-Faktor-Authentifizierung deaktiviert.');
        }

        return $this->redirectToRoute('app_profile_index');
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}

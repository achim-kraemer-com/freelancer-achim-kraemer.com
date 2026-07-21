<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/users', name: 'admin_user_')]
#[IsGranted('ROLE_ADMIN')]
class UserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(UserRepository $users): Response
    {
        return $this->render('admin/user/index.html.twig', [
            'users' => $users->findBy([], ['email' => 'ASC']),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $hasher): Response
    {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setRoles([(string) $form->get('role')->getData()]);
            $user->setPassword($hasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
            $em->persist($user);
            $em->flush();
            $this->addFlash('success', 'Benutzer angelegt.');

            return $this->redirectToRoute('admin_user_index');
        }

        return $this->render('admin/user/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/role', name: 'role', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function role(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Die eigene Rolle kann nicht geändert werden.');

            return $this->redirectToRoute('admin_user_index');
        }

        $role = (string) $request->request->get('role');
        if ($this->isCsrfTokenValid('role'.$user->getId(), (string) $request->request->get('_token'))
            && \in_array($role, ['ROLE_USER', 'ROLE_ADMIN'], true)
        ) {
            $user->setRoles([$role]);
            $em->flush();
            $this->addFlash('success', 'Rolle aktualisiert.');
        }

        return $this->redirectToRoute('admin_user_index');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Der eigene Benutzer kann nicht gelöscht werden.');

            return $this->redirectToRoute('admin_user_index');
        }

        if ($this->isCsrfTokenValid('delete'.$user->getId(), (string) $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Benutzer gelöscht.');
        }

        return $this->redirectToRoute('admin_user_index');
    }
}

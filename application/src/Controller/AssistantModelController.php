<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AssistantModel;
use App\Entity\User;
use App\Form\AssistantModelType;
use App\Repository\AssistantModelRepository;
use App\Service\Assistant\AssistantUnavailable;
use App\Service\Assistant\ChatModelFactory;
use App\Service\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die angeschlossenen Sprachmodelle verwalten – samt verschlüsselt abgelegtem
 * Schlüssel und Verbindungstest. Nach dem Vorbild des Portals (dort
 * AssistantModelController).
 *
 * Ein Modell gehört dem Benutzer, der es angeschlossen hat: Der Schlüssel ist
 * seiner, und abgerechnet wird darüber. Kunden und Rechnungen gehören dagegen
 * allen Angemeldeten gemeinsam.
 */
#[Route('/admin/modell', name: 'admin_assistant_model_')]
#[IsGranted('ROLE_ADMIN')]
class AssistantModelController extends AbstractController
{
    public function __construct(
        private readonly AssistantModelRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly SecretCipher $cipher,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        return $this->render('assistant_model/index.html.twig', [
            'models' => $this->repository->findForUser($user),
        ]);
    }

    #[Route('/neu', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        /** @var User $user */
        $user  = $this->getUser();
        $model = new AssistantModel();
        $model->setOwner($user)->setPosition($this->repository->nextPosition($user));

        $form = $this->createForm(AssistantModelType::class, $model, ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyKey($form, $model);

            // Das erste Modell ist zwangsläufig die Vorgabe.
            if ($this->repository->findForUser($user) === []) {
                $model->setIsDefault(true);
            }

            $this->em->persist($model);
            $this->em->flush();
            $this->addFlash('success', 'Modell angelegt.');

            return $this->redirectToRoute('admin_assistant_model_index');
        }

        return $this->render('assistant_model/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}/bearbeiten', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(AssistantModel $model, Request $request): Response
    {
        $this->denyUnlessOwner($model);

        $form = $this->createForm(AssistantModelType::class, $model);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->applyKey($form, $model);
            $this->em->flush();
            $this->addFlash('success', 'Modell aktualisiert.');

            return $this->redirectToRoute('admin_assistant_model_index');
        }

        return $this->render('assistant_model/edit.html.twig', ['form' => $form, 'model' => $model]);
    }

    /** Schickt eine kurze Anfrage an das Modell und meldet, was dabei herauskommt. */
    #[Route('/{id}/test', name: 'test', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function test(AssistantModel $model, Request $request, ChatModelFactory $factory): Response
    {
        $this->denyUnlessOwner($model);

        if ($this->isCsrfTokenValid('test'.$model->getId(), (string) $request->request->get('_token'))) {
            try {
                $zug = $factory->create($model)->converse(
                    'Antworte mit einem einzigen Wort: bereit.',
                    [],
                    [['role' => 'user', 'text' => 'Bist du bereit?']],
                );

                $model->setLastCheckedAt(new \DateTimeImmutable())->setLastError(null);
                $this->addFlash('success', \sprintf(
                    '„%s" antwortet: %s',
                    (string) $model->getLabel(),
                    $zug->text !== '' ? $zug->text : '(leere Antwort, aber die Verbindung steht)',
                ));
            } catch (AssistantUnavailable $e) {
                $model->setLastCheckedAt(new \DateTimeImmutable())->setLastError($e->getMessage());
                $this->addFlash('error', $e->getMessage());
            }

            $this->em->flush();
        }

        return $this->redirectToRoute('admin_assistant_model_index');
    }

    #[Route('/{id}/vorgabe', name: 'default', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function makeDefault(AssistantModel $model, Request $request): Response
    {
        $this->denyUnlessOwner($model);

        if ($this->isCsrfTokenValid('default'.$model->getId(), (string) $request->request->get('_token'))) {
            $this->repository->makeDefault($model);

            $this->addFlash('success', \sprintf('„%s" ist jetzt die Vorgabe.', (string) $model->getLabel()));
        }

        return $this->redirectToRoute('admin_assistant_model_index');
    }

    #[Route('/{id}/loeschen', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(AssistantModel $model, Request $request): Response
    {
        $this->denyUnlessOwner($model);

        if ($this->isCsrfTokenValid('delete'.$model->getId(), (string) $request->request->get('_token'))) {
            $warVorgabe = $model->isDefault();

            $this->em->remove($model);
            $this->em->flush();

            // Ohne Vorgabe stünde die Auswahl leer da – die Lücke gleich schließen.
            if ($warVorgabe) {
                /** @var User $user */
                $user      = $this->getUser();
                $nachfolger = $this->repository->findForUser($user)[0] ?? null;

                if ($nachfolger !== null) {
                    $this->repository->makeDefault($nachfolger);
                }
            }

            $this->addFlash('success', 'Modell entfernt.');
        }

        return $this->redirectToRoute('admin_assistant_model_index');
    }

    /**
     * Leer gelassener Schlüssel lässt den bisherigen unangetastet.
     *
     * @param FormInterface<AssistantModel> $form
     */
    private function applyKey(FormInterface $form, AssistantModel $model): void
    {
        $key = \trim((string) $form->get('apiKeyPlain')->getData());

        if ($key !== '') {
            $model->setApiKey($this->cipher->encrypt($key));
        }
    }

    private function denyUnlessOwner(AssistantModel $model): void
    {
        if ($model->getOwner() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Dieses Modell gehört einem anderen Benutzer.');
        }
    }
}

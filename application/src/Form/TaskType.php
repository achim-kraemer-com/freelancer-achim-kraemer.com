<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Task;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TaskType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Aufgabe'])
            ->add('description', TextareaType::class, [
                'label'    => 'Beschreibung',
                'required' => false,
                'attr'     => ['rows' => 2],
            ])
            ->add('hours', NumberType::class, [
                'label'    => 'Stunden',
                'required' => false,
                'scale'    => 2,
                'html5'    => true,
                'attr'     => ['step' => '0.25', 'min' => '0', 'placeholder' => 'z. B. 3,5'],
                'help'     => 'Wird mit '.Task::HOURLY_RATE.' € / Std. berechnet.',
            ])
            ->add('price', NumberType::class, [
                'label'    => 'Festpreis (€)',
                'required' => false,
                'scale'    => 2,
                'html5'    => true,
                'attr'     => ['step' => '0.01', 'min' => '0', 'placeholder' => 'optional'],
                'help'     => 'Wenn gesetzt, überschreibt der Festpreis die Stundenberechnung.',
            ]);

        // Require at least one of hours / price.
        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
            /** @var Task $task */
            $task = $event->getData();
            if (null === $task) {
                return;
            }
            if (null === $task->getHours() && null === $task->getPrice()) {
                $event->getForm()->addError(new FormError('Bitte Stunden oder einen Festpreis angeben.'));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Task::class]);
    }
}

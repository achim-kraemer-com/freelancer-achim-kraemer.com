<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class ChangePasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label'       => 'Aktuelles Passwort',
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(),
                    new UserPassword(message: 'Das aktuelle Passwort ist nicht korrekt.'),
                ],
                'attr' => ['autocomplete' => 'current-password'],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type'            => PasswordType::class,
                'mapped'          => false,
                'first_options'   => ['label' => 'Neues Passwort', 'attr' => ['autocomplete' => 'new-password']],
                'second_options'  => ['label' => 'Neues Passwort wiederholen', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'Die Passwörter stimmen nicht überein.',
                'constraints'     => [
                    new NotBlank(),
                    new Length(min: 8, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen haben.'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}

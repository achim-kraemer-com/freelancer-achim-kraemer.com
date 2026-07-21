<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, ['label' => 'E-Mail'])
            ->add('role', ChoiceType::class, [
                'label'   => 'Rolle',
                'mapped'  => false,
                'choices' => [
                    'Benutzer'      => 'ROLE_USER',
                    'Administrator' => 'ROLE_ADMIN',
                ],
                'data' => 'ROLE_USER',
            ])
            ->add('plainPassword', PasswordType::class, [
                'label'       => 'Passwort',
                'mapped'      => false,
                'constraints' => [
                    new NotBlank(),
                    new Length(min: 8, minMessage: 'Das Passwort muss mindestens {{ limit }} Zeichen haben.'),
                ],
                'attr' => ['autocomplete' => 'new-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}

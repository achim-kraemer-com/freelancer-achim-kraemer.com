<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CustomerType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstname', TextType::class, ['label' => 'Vorname'])
            ->add('lastname', TextType::class, ['label' => 'Nachname'])
            ->add('companyName', TextType::class, ['label' => 'Firma', 'required' => false])
            ->add('email', EmailType::class, ['label' => 'E-Mail'])
            ->add('phone', TextType::class, ['label' => 'Telefon', 'required' => false])
            ->add('mobile', TextType::class, ['label' => 'Mobil', 'required' => false])
            ->add('street', TextType::class, ['label' => 'Straße', 'required' => false])
            ->add('housenumber', TextType::class, ['label' => 'Hausnummer', 'required' => false])
            ->add('plz', TextType::class, ['label' => 'PLZ', 'required' => false])
            ->add('city', TextType::class, ['label' => 'Ort', 'required' => false])
            ->add('country', TextType::class, ['label' => 'Land', 'required' => false])
            ->add('website', TextType::class, ['label' => 'Website', 'required' => false])
            ->add('isActive', CheckboxType::class, ['label' => 'Aktiv', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Customer::class]);
    }
}

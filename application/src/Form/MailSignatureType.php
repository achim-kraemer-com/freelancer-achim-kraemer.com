<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\MailSignature;
use App\Enum\InvoiceTemplate;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MailSignatureType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Name',
                'attr'  => ['placeholder' => 'z. B. Achim Krämer, Mirwena', 'autocomplete' => 'off'],
                'help'  => 'Steht so in der Auswahl an der Rechnung.',
            ])
            ->add('format', ChoiceType::class, [
                'label'    => 'Art',
                'expanded' => true,
                'choices'  => MailSignature::FORMAT_CHOICES,
                'help'     => 'HTML für Formatierung, Links und Bilder – etwa eine Signatur aus dem '
                    .'Signatur-Generator, die sich mit „Signatur kopieren“ hier einfügen lässt.',
            ])
            ->add('defaultForTemplate', EnumType::class, [
                'label'        => 'Vorgabe für Rechnungsvorlage',
                'class'        => InvoiceTemplate::class,
                'choice_label' => static fn (InvoiceTemplate $template): string => $template->label(),
                'required'     => false,
                'placeholder'  => 'Keine',
                'help'         => 'Neue Rechnungen mit diesem Briefkopf bekommen diese Signatur vorausgewählt – '
                    .'an der Rechnung lässt sich eine andere wählen.',
            ])
            // For HTML signatures this holds the source; the visual editor in
            // the form writes into this field (signature-editor.js).
            ->add('text', TextareaType::class, [
                'label' => 'Text',
                'attr'  => ['rows' => 8, 'placeholder' => "Achim Krämer\nTelefon, Adresse, Webseite …"],
                'help'  => 'Kommt beim Versand unter den Text der E-Mail – bei reinem Text hinter der '
                    .'üblichen Trennzeile „-- “, an der Mailprogramme eine Signatur erkennen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MailSignature::class,
        ]);
    }
}

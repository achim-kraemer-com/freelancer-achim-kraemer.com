<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AssistantModel;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** @extends AbstractType<AssistantModel> */
class AssistantModelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Name',
                'attr'  => ['placeholder' => 'z. B. Claude Opus 5', 'autocomplete' => 'off'],
                'help'  => 'Steht so in der Auswahl über dem Eingabefeld.',
            ])
            ->add('provider', ChoiceType::class, [
                'label'   => 'Art der Schnittstelle',
                'choices' => AssistantModel::PROVIDER_CHOICES,
                'help'    => 'OpenAI-kompatibel sprechen außer OpenAI auch OpenRouter, Groq, '
                    .'Together, Google über seinen OpenAI-Endpunkt sowie lokale Modelle '
                    .'über Ollama oder LM Studio.',
            ])
            ->add('model', TextType::class, [
                'label' => 'Modellname',
                'attr'  => ['placeholder' => 'claude-opus-5', 'autocomplete' => 'off', 'list' => 'modell-vorschlaege'],
                'help'  => 'Genau so, wie ihn der Anbieter schreibt – bei OpenRouter etwa '
                    .'„anthropic/claude-opus-4.5", bei OpenAI „gpt-5".',
            ])
            ->add('baseUrl', TextType::class, [
                'label'    => 'Adresse',
                'required' => false,
                'attr'     => ['placeholder' => 'leer = Vorgabe des Anbieters', 'list' => 'adress-vorschlaege', 'autocomplete' => 'off'],
                'help'     => 'Nur nötig, wenn nicht der Anbieter selbst gemeint ist – für '
                    .'OpenRouter, Groq oder ein Modell im eigenen Netz.',
            ])
            ->add('reasoningEffort', ChoiceType::class, [
                'label'       => 'Denkmodus',
                'required'    => false,
                'placeholder' => 'Vorgabe des Anbieters',
                'choices'     => AssistantModel::REASONING_CHOICES,
                'help'        => 'Nur für OpenAI-kompatible Anbieter. Bei einem lokalen Modell '
                    .'ohne Grafikkarte „Aus" wählen – sonst denkt es vor jeder Antwort '
                    .'minutenlang.',
            ])
            ->add('apiKeyPlain', PasswordType::class, [
                'label'    => 'Schlüssel',
                'mapped'   => false,
                'required' => false,
                'attr'     => [
                    'autocomplete' => 'new-password',
                    'placeholder'  => $options['is_new'] ? 'sk-…' : 'Leer lassen = unverändert',
                ],
                'help'     => 'Wird verschlüsselt gespeichert. Lokale Modelle brauchen meist keinen.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AssistantModel::class,
            'is_new'     => false,
        ]);

        $resolver->setAllowedTypes('is_new', 'bool');
    }
}

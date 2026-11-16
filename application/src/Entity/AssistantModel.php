<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssistantModelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ein angeschlossenes Sprachmodell – es beantwortet die Fragen an den
 * Assistenten und übersetzt die Eingaben der KI-Suche.
 *
 * Zwei Sorten reichen für alles: die von Anthropic und die OpenAI-kompatible.
 * Letztere sprechen OpenAI, OpenRouter, Groq, Together, Google über seinen
 * OpenAI-Endpunkt sowie lokale Modelle über Ollama oder LM Studio – der
 * Unterschied steckt allein in der Adresse.
 *
 * Jedes Modell gehört dem Benutzer, der es angeschlossen hat: Der Schlüssel
 * ist seiner, und abgerechnet wird darüber. Er liegt verschlüsselt in der
 * Datenbank; ver- und entschlüsselt wird ausschließlich über den SecretCipher.
 */
#[ORM\Entity(repositoryClass: AssistantModelRepository::class)]
#[ORM\Table(name: 'assistant_model')]
class AssistantModel
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';

    /** @var array<string, string> */
    public const PROVIDER_CHOICES = [
        'Anthropic (Claude)'                                     => self::PROVIDER_ANTHROPIC,
        'OpenAI-kompatibel (OpenAI, OpenRouter, Groq, Ollama …)'  => self::PROVIDER_OPENAI,
    ];

    /** Adresse, wenn keine eigene eingetragen ist. Anthropic bringt seine eigene mit. */
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Denkmodus (reasoning_effort) für OpenAI-kompatible Anbieter. Leer heißt:
     * das Modell entscheidet selbst. „Aus" schaltet die Denkphase ab – für
     * Modelle wie Qwen 3.5 auf einer CPU der Unterschied zwischen Sekunden
     * und Minuten je Antwort.
     *
     * @var array<string, string>
     */
    public const REASONING_CHOICES = [
        'Aus – keine Denkphase (schnell, für lokale Modelle)' => 'none',
        'Niedrig'                                            => 'low',
        'Mittel'                                             => 'medium',
        'Hoch'                                               => 'high',
    ];

    /**
     * Vorschläge für das Formular: Anbieter, Adresse und ein gängiger Modellname.
     *
     * @var array<string, array{url: string, model: string}>
     */
    public const PRESETS = [
        'OpenAI'          => ['url' => 'https://api.openai.com/v1',       'model' => 'gpt-5'],
        'OpenRouter'      => ['url' => 'https://openrouter.ai/api/v1',    'model' => 'anthropic/claude-opus-4.5'],
        'Groq'            => ['url' => 'https://api.groq.com/openai/v1',  'model' => 'llama-3.3-70b-versatile'],
        'Ollama (lokal)'  => ['url' => 'http://localhost:11434/v1',       'model' => 'llama3.1'],
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\Column(length: 80)]
    #[Assert\NotBlank(message: 'Bitte einen Namen angeben, unter dem das Modell in der Auswahl steht.')]
    #[Assert\Length(max: 80)]
    private ?string $label = null;

    #[ORM\Column(length: 20)]
    private string $provider = self::PROVIDER_ANTHROPIC;

    /** Der Modellname, wie ihn der Anbieter erwartet – z. B. claude-opus-5 oder openai/gpt-5. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank(message: 'Bitte den Modellnamen angeben, z. B. claude-opus-5.')]
    private ?string $model = null;

    /** Leer = Vorgabe des Anbieters. Nur nötig für OpenRouter, Groq, Ollama und Ähnliches. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(
        message: 'Bitte eine gültige Adresse angeben (http oder https).',
        protocols: ['http', 'https'],
        requireTld: false,
    )]
    private ?string $baseUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apiKey = null;

    /**
     * Denkmodus, siehe REASONING_CHOICES. Nur OpenAI-kompatible Anbieter werten
     * ihn aus (OpenAI, Ollama, OpenRouter); Anthropic ignoriert ihn.
     */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Choice(choices: ['none', 'low', 'medium', 'high'], message: 'Bitte einen gültigen Denkmodus wählen.')]
    private ?string $reasoningEffort = null;

    /** Das Modell, das ohne ausdrückliche Wahl verwendet wird. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isDefault = false;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function isAnthropic(): bool
    {
        return $this->provider === self::PROVIDER_ANTHROPIC;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): static
    {
        $baseUrl = $baseUrl === null ? null : \trim($baseUrl);

        $this->baseUrl = $baseUrl === '' ? null : $baseUrl;

        return $this;
    }

    /** Die tatsächlich anzusprechende Adresse – null heißt: Vorgabe des SDK. */
    public function resolveBaseUrl(): ?string
    {
        if ($this->baseUrl !== null) {
            return \rtrim($this->baseUrl, '/');
        }

        return $this->isAnthropic() ? null : self::DEFAULT_BASE_URL;
    }

    /** Liefert den verschlüsselten Schlüssel; entschlüsselt wird im SecretCipher. */
    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }

    public function setApiKey(?string $apiKey): static
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /** Denkmodus des Modells – null heißt: Vorgabe des Anbieters. */
    public function getReasoningEffort(): ?string
    {
        return $this->reasoningEffort;
    }

    public function setReasoningEffort(?string $reasoningEffort): static
    {
        $this->reasoningEffort = $reasoningEffort === '' ? null : $reasoningEffort;

        return $this;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function setLastCheckedAt(?\DateTimeImmutable $lastCheckedAt): static
    {
        $this->lastCheckedAt = $lastCheckedAt;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): static
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Kurze Herkunftsangabe für die Oberfläche, z. B. „openrouter.ai · gpt-5". */
    public function describe(): string
    {
        $url  = $this->resolveBaseUrl();
        $host = $url !== null ? (\parse_url($url, \PHP_URL_HOST) ?: $url) : 'api.anthropic.com';

        return $host.' · '.(string) $this->model;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
class Task
{
    /** Hourly rate in EUR used when a task is billed by time. */
    final public const int HOURLY_RATE = 60;

    final public const string STATUS_OPEN        = 'offen';
    final public const string STATUS_IN_PROGRESS = 'in Arbeit';
    final public const string STATUS_DONE        = 'erledigt';

    /** @var array<string, string> label => value, for form choices */
    final public const array STATUS_CHOICES = [
        'Offen'     => self::STATUS_OPEN,
        'In Arbeit' => self::STATUS_IN_PROGRESS,
        'Erledigt'  => self::STATUS_DONE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Project $project = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Time spent in hours (billed at HOURLY_RATE). Nullable when a fixed price is used. */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $hours = null;

    /** Fixed price in EUR. When set, it overrides the hourly calculation. */
    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    #[Assert\PositiveOrZero]
    private ?string $price = null;

    #[ORM\Column]
    private bool $invoiced = false;

    #[ORM\Column(length: 20, options: ['default' => self::STATUS_OPEN])]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'tasks')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Invoice $invoice = null;

    /**
     * @var Collection<int, TimeEntry>
     */
    #[ORM\OneToMany(targetEntity: TimeEntry::class, mappedBy: 'task', orphanRemoval: true)]
    #[ORM\OrderBy(['workDate' => 'DESC', 'id' => 'DESC'])]
    private Collection $timeEntries;

    public function __construct()
    {
        $this->createdAt   = new \DateTime();
        $this->timeEntries = new ArrayCollection();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getHours(): ?string
    {
        return $this->hours;
    }

    public function setHours(?string $hours): static
    {
        $this->hours = $hours;

        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function isInvoiced(): bool
    {
        return $this->invoiced;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** URL/CSS-safe slug of the status ("in Arbeit" -> "in-arbeit"). */
    public function getStatusSlug(): string
    {
        return str_replace(' ', '-', strtolower($this->status));
    }

    public function setInvoiced(bool $invoiced): static
    {
        $this->invoiced = $invoiced;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getInvoice(): ?Invoice
    {
        return $this->invoice;
    }

    public function setInvoice(?Invoice $invoice): static
    {
        $this->invoice = $invoice;

        return $this;
    }

    /**
     * Effective cost of this task in EUR: the fixed price when set,
     * otherwise hours * HOURLY_RATE.
     */
    public function getCost(): float
    {
        if (null !== $this->price) {
            return (float) $this->price;
        }

        if (null !== $this->hours) {
            return (float) $this->hours * self::HOURLY_RATE;
        }

        return 0.0;
    }

    /** True when the task is billed by time rather than a fixed price. */
    public function isHourly(): bool
    {
        return null === $this->price && null !== $this->hours;
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    public function getTimeEntries(): Collection
    {
        return $this->timeEntries;
    }

    public function addTimeEntry(TimeEntry $timeEntry): static
    {
        if (!$this->timeEntries->contains($timeEntry)) {
            $this->timeEntries->add($timeEntry);
            $timeEntry->setTask($this);
        }

        return $this;
    }

    public function removeTimeEntry(TimeEntry $timeEntry): static
    {
        if ($this->timeEntries->removeElement($timeEntry)) {
            if ($timeEntry->getTask() === $this) {
                $timeEntry->setTask(null);
            }
        }

        return $this;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProjectRepository::class)]
class Project
{
    /** Added to the project id so the middle part of an invoice number has four digits. */
    private const int INVOICE_NUMBER_OFFSET = 1000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'projects')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Customer $customer = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    /**
     * How many invoice numbers this project has handed out so far. Only ever
     * grows – see {@see nextInvoiceNumber()}.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $invoiceCounter = 0;

    /**
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'project', orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks     = new ArrayCollection();
        $this->createdAt = new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): ?Customer
    {
        return $this->customer;
    }

    public function setCustomer(?Customer $customer): static
    {
        $this->customer = $customer;

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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, Task>
     */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(Task $task): static
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setProject($this);
        }

        return $this;
    }

    public function removeTask(Task $task): static
    {
        if ($this->tasks->removeElement($task)) {
            if ($task->getProject() === $this) {
                $task->setProject(null);
            }
        }

        return $this;
    }

    /**
     * Sum of all tasks' costs (in EUR).
     */
    public function getTotalCost(): float
    {
        $total = 0.0;
        foreach ($this->tasks as $task) {
            $total += $task->getCost();
        }

        return $total;
    }

    public function getOpenTotalCost(): float
    {
        $total = 0.0;
        foreach ($this->tasks as $task) {
            if (!$task->isInvoiced()) {
                $total += $task->getCost();
            }
        }

        return $total;
    }

    public function getInvoiceCounter(): int
    {
        return $this->invoiceCounter;
    }

    /**
     * Hands out the next invoice number for this project, as in "26-1003-4":
     * two-digit year, project id offset to four digits, running number.
     *
     * The running number comes from a counter that only ever grows. Counting
     * the invoices that exist instead would give the next invoice the number
     * of a deleted one – and a number that has gone out to a customer must
     * never come back on a different invoice. A deletion therefore leaves a
     * gap, which is the lesser evil.
     */
    public function nextInvoiceNumber(\DateTimeInterface $date): string
    {
        return \sprintf(
            '%s-%d-%d',
            $date->format('y'),
            (int) $this->id + self::INVOICE_NUMBER_OFFSET,
            ++$this->invoiceCounter,
        );
    }
}

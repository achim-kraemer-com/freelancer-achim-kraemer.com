<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\InvoiceTemplate;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvoiceRepository::class)]
class Invoice
{
    final public const int HOURLY_RATE = Task::HOURLY_RATE;

    final public const string STATUS_CREATED = 'erstellt';
    final public const string STATUS_SENT    = 'verschickt';
    final public const string STATUS_PAID    = 'bezahlt';

    /** Default payment term (days) added to the invoice date to derive the due date. */
    final public const int DEFAULT_PAYMENT_TERM_DAYS = 14;

    /** @var array<string, string> label => value, for form choices */
    final public const array STATUS_CHOICES = [
        'Erstellt'   => self::STATUS_CREATED,
        'Verschickt' => self::STATUS_SENT,
        'Bezahlt'    => self::STATUS_PAID,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $number = null;

    #[ORM\ManyToOne(inversedBy: 'invoices')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Customer $customer = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Project $project = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $invoiceDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $paidAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private ?string $totalAmount = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CREATED;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $pdfFilename = null;

    /** Letterhead (blank PDF) the invoice is rendered with; invoices predating the choice use Achim Krämer's. */
    #[ORM\Column(length: 20, enumType: InvoiceTemplate::class, options: ['default' => 'achim-kraemer'])]
    private InvoiceTemplate $template = InvoiceTemplate::AchimKraemer;

    /** Signature placed under the e-mail the invoice is sent with; null sends the mail without one. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?MailSignature $signature = null;

    /**
     * @var Collection<int, Task>
     */
    #[ORM\OneToMany(targetEntity: Task::class, mappedBy: 'invoice')]
    private Collection $tasks;

    /**
     * @var Collection<int, InvoiceDispatch>
     */
    #[ORM\OneToMany(targetEntity: InvoiceDispatch::class, mappedBy: 'invoice', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sentAt' => 'DESC'])]
    private Collection $dispatches;

    public function __construct()
    {
        $this->tasks       = new ArrayCollection();
        $this->dispatches  = new ArrayCollection();
        $this->invoiceDate = new \DateTime();
    }

    public function __toString(): string
    {
        return (string) $this->number;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): ?string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
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

    public function getProject(): ?Project
    {
        return $this->project;
    }

    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getInvoiceDate(): ?\DateTimeInterface
    {
        return $this->invoiceDate;
    }

    public function setInvoiceDate(\DateTimeInterface $invoiceDate): static
    {
        $this->invoiceDate = $invoiceDate;

        return $this;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;

        return $this;
    }

    public function getPaidAt(): ?\DateTimeInterface
    {
        return $this->paidAt;
    }

    public function setPaidAt(?\DateTimeInterface $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    /** True when the invoice is unpaid and its due date lies in the past. */
    public function isOverdue(): bool
    {
        if ($this->status === self::STATUS_PAID || $this->dueDate === null) {
            return false;
        }

        return $this->dueDate < new \DateTime('today');
    }

    /** Whole days until (positive) or since (negative) the due date; null if none set. */
    public function getDaysUntilDue(): ?int
    {
        if ($this->dueDate === null) {
            return null;
        }

        $today = new \DateTime('today');
        $due   = \DateTime::createFromInterface($this->dueDate)->setTime(0, 0);
        $diff  = $today->diff($due);

        return (int) $diff->format('%r%a');
    }

    public function getTotalAmount(): ?string
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(string $totalAmount): static
    {
        $this->totalAmount = $totalAmount;

        return $this;
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

    public function getPdfFilename(): ?string
    {
        return $this->pdfFilename;
    }

    public function setPdfFilename(?string $pdfFilename): static
    {
        $this->pdfFilename = $pdfFilename;

        return $this;
    }

    public function getTemplate(): InvoiceTemplate
    {
        return $this->template;
    }

    public function setTemplate(InvoiceTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getSignature(): ?MailSignature
    {
        return $this->signature;
    }

    public function setSignature(?MailSignature $signature): static
    {
        $this->signature = $signature;

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
            $task->setInvoice($this);
        }

        return $this;
    }

    public function removeTask(Task $task): static
    {
        if ($this->tasks->removeElement($task)) {
            if ($task->getInvoice() === $this) {
                $task->setInvoice(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, InvoiceDispatch>
     */
    public function getDispatches(): Collection
    {
        return $this->dispatches;
    }

    public function addDispatch(InvoiceDispatch $dispatch): static
    {
        if (!$this->dispatches->contains($dispatch)) {
            $this->dispatches->add($dispatch);
            $dispatch->setInvoice($this);
        }

        return $this;
    }

    public function removeDispatch(InvoiceDispatch $dispatch): static
    {
        if ($this->dispatches->removeElement($dispatch)) {
            if ($dispatch->getInvoice() === $this) {
                $dispatch->setInvoice(null);
            }
        }

        return $this;
    }

    /** The most recent time this invoice was e-mailed, or null if never sent. */
    public function getLastSentAt(): ?\DateTimeImmutable
    {
        $first = $this->dispatches->first();

        return $first ? $first->getSentAt() : null;
    }
}

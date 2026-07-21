<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_IDENTIFIER_EMAIL', fields: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private ?string $email = null;

    /**
     * @var list<string> The user roles
     */
    #[ORM\Column]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    /** Timestamp when the running time-tracking stopwatch was started; null when stopped. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $timerStartedAt = null;

    /** Elapsed seconds accumulated from completed start/stop intervals of the stopwatch. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $timerAccumulatedSeconds = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return \array_unique($roles);
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getTimerStartedAt(): ?\DateTimeImmutable
    {
        return $this->timerStartedAt;
    }

    public function setTimerStartedAt(?\DateTimeImmutable $timerStartedAt): static
    {
        $this->timerStartedAt = $timerStartedAt;

        return $this;
    }

    public function getTimerAccumulatedSeconds(): int
    {
        return $this->timerAccumulatedSeconds;
    }

    public function setTimerAccumulatedSeconds(int $seconds): static
    {
        $this->timerAccumulatedSeconds = \max(0, $seconds);

        return $this;
    }

    public function isTimerRunning(): bool
    {
        return $this->timerStartedAt !== null;
    }

    /** Total elapsed seconds including the currently running interval, if any. */
    public function getElapsedSeconds(): int
    {
        $elapsed = $this->timerAccumulatedSeconds;

        if ($this->timerStartedAt !== null) {
            $elapsed += \max(0, (new \DateTimeImmutable())->getTimestamp() - $this->timerStartedAt->getTimestamp());
        }

        return $elapsed;
    }

    /** Starts the stopwatch if it is not already running. */
    public function startTimer(): static
    {
        if ($this->timerStartedAt === null) {
            $this->timerStartedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    /** Stops the stopwatch and folds the running interval into the accumulated seconds. */
    public function stopTimer(): static
    {
        if ($this->timerStartedAt !== null) {
            $this->timerAccumulatedSeconds += \max(0, (new \DateTimeImmutable())->getTimestamp() - $this->timerStartedAt->getTimestamp());
            $this->timerStartedAt = null;
        }

        return $this;
    }

    /** Clears the stopwatch completely. */
    public function resetTimer(): static
    {
        $this->timerStartedAt          = null;
        $this->timerAccumulatedSeconds = 0;

        return $this;
    }
}

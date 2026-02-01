<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Repository\TrainingEntryRepository;

#[ORM\Entity(repositoryClass: TrainingEntryRepository::class)]
#[ORM\Table(name: 'sportabzeichen_training')]
#[ORM\UniqueConstraint(name: 'uniq_user_discipline_year', columns: ['user_id', 'discipline_id', 'year'])]
class TrainingEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Discipline::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Discipline $discipline = null;

    #[ORM\Column(type: 'integer')]
    private ?int $year = null;

    // Wir speichern den Wert als String (z.B. "12:30" oder "5.45"), 
    // damit Schüler flexibel eintragen können.
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $value = null;

    #[ORM\Column(type: 'datetime')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTime();
    }

    // --- Getter & Setter ---

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    public function getDiscipline(): ?Discipline { return $this->discipline; }
    public function setDiscipline(?Discipline $discipline): self { $this->discipline = $discipline; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getValue(): ?string { return $this->value; }
    public function setValue(?string $value): self { 
        $this->value = $value; 
        $this->updatedAt = new \DateTime(); 
        return $this; 
    }

    public function getUpdatedAt(): \DateTimeInterface { return $this->updatedAt; }
}
<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\RequirementRepository;
use PulsR\SportabzeichenBundle\Entity\Discipline;

#[ORM\Entity(repositoryClass: RequirementRepository::class)]
#[ORM\Table(name: 'sportabzeichen_requirements')]
// Achtung: In UniqueConstraint stehen noch die DB-Spaltennamen!
#[ORM\UniqueConstraint(name: 'uniq_sportabzeichen_requirements', columns: ['discipline_id', 'jahr', 'age_min', 'age_max', 'geschlecht'])]
class Requirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Discipline::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', name: 'discipline_id')]
    private ?Discipline $discipline = null;

    // Mapping: PHP $year -> DB 'jahr'
    #[ORM\Column(type: 'integer', name: 'jahr')]
    private ?int $year = null;

    // Mapping: PHP $minAge -> DB 'age_min'
    #[ORM\Column(type: 'integer', name: 'age_min')]
    private ?int $minAge = null;

    // Mapping: PHP $maxAge -> DB 'age_max'
    #[ORM\Column(type: 'integer', name: 'age_max')]
    private ?int $maxAge = null;

    // Mapping: PHP $gender -> DB 'geschlecht'
    #[ORM\Column(type: 'text', name: 'geschlecht')]
    private ?string $gender = null;

    // Mapping: PHP $selectionId -> DB 'auswahlnummer'
    #[ORM\Column(type: 'integer', name: 'auswahlnummer')]
    private ?int $selectionId = null;

    // Mapping: PHP $bronze -> DB 'bronze' (Name gleich, aber explizit)
    #[ORM\Column(type: 'float', nullable: true, name: 'bronze')]
    private ?float $bronze = null;

    // Mapping: PHP $silver -> DB 'silber'
    #[ORM\Column(type: 'float', nullable: true, name: 'silber')]
    private ?float $silver = null;

    // Mapping: PHP $gold -> DB 'gold'
    #[ORM\Column(type: 'float', nullable: true, name: 'gold')]
    private ?float $gold = null;

    // Mapping: PHP $swimmingProof -> DB 'schwimmnachweis'
    #[ORM\Column(type: 'boolean', name: 'schwimmnachweis', options: ['default' => false])]
    private ?bool $swimmingProof = false;

    // --- GETTER & SETTER (Alles Englisch) ---

    public function getId(): ?int { return $this->id; }

    public function getDiscipline(): ?Discipline { return $this->discipline; }
    public function setDiscipline(?Discipline $discipline): self { $this->discipline = $discipline; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getMinAge(): ?int { return $this->minAge; }
    public function setMinAge(int $minAge): self { $this->minAge = $minAge; return $this; }

    public function getMaxAge(): ?int { return $this->maxAge; }
    public function setMaxAge(int $maxAge): self { $this->maxAge = $maxAge; return $this; }

    public function getGender(): ?string { return $this->gender; }
    public function setGender(string $gender): self { $this->gender = $gender; return $this; }

    public function getSelectionId(): ?int { return $this->selectionId; }
    public function setSelectionId(int $selectionId): self { $this->selectionId = $selectionId; return $this; }

    public function getBronze(): ?float { return $this->bronze; }
    public function setBronze(?float $bronze): self { $this->bronze = $bronze; return $this; }

    public function getSilver(): ?float { return $this->silver; }
    public function setSilver(?float $silver): self { $this->silver = $silver; return $this; }

    public function getGold(): ?float { return $this->gold; }
    public function setGold(?float $gold): self { $this->gold = $gold; return $this; }

    public function isSwimmingProof(): ?bool { return $this->swimmingProof; }
    public function setSwimmingProof(bool $swimmingProof): self { $this->swimmingProof = $swimmingProof; return $this; }
}
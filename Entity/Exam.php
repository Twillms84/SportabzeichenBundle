<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use IServ\CoreBundle\Entity\User; // <--- WICHTIG: User importieren

#[ORM\Table(name: 'sportabzeichen_exams')]
#[ORM\Entity(repositoryClass: ExamRepository::class)]
class Exam
{
    // ... (id, name, year, date bleiben wie sie sind) ...
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, name: 'exam_name')]
    private ?string $name = null;

    #[ORM\Column(type: 'integer', name: 'exam_year')]
    private ?int $year = null;

    #[ORM\Column(type: 'date', nullable: true, name: 'exam_date')]
    private ?\DateTimeInterface $date = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'creator_id', referencedColumnName: 'id', nullable: true)]
    private ?User $creator = null;

    // --- GETTER (gibt jetzt ein User-Objekt zurück!) ---
    public function getCreator(): ?User
    {
        return $this->creator;
    }

    // --- SETTER (erwartet ein User-Objekt) ---
    public function setCreator(?User $creator): self
    {
        $this->creator = $creator;
        return $this;
    }
    // ... (Getter/Setter für id, name, year, date bleiben gleich) ...

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    // ... (toString und getDisplayName bleiben gleich) ...
    public function __toString(): string 
    { 
        return $this->name ?? (string)$this->year; 
    }
    
    public function getDisplayName(): string
    {
        return 'Sportabzeichen ' . $this->year;
    }
}
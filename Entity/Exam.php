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

    #[ORM\Column(type: 'string', nullable: true, name: 'creator_id')]
    private ?string $creator = null;

    public function getCreator(): ?string
    {
        return $this->creator;
    }

    public function setCreator(?string $creator): self
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
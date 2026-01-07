<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;

#[ORM\Table(name: 'sportabzeichen_exams')]
#[ORM\Entity(repositoryClass: ExamRepository::class)]
class Exam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    // PHP: $name  <-->  DB: 'exam_name'
    #[ORM\Column(type: 'string', length: 255, name: 'exam_name')]
    private ?string $name = null;

    // PHP: $year  <-->  DB: 'exam_year'
    #[ORM\Column(type: 'integer', name: 'exam_year')]
    private ?int $year = null;

    // PHP: $date  <-->  DB: 'exam_date'
    #[ORM\Column(type: 'date', nullable: true, name: 'exam_date')]
    private ?\DateTimeInterface $date = null;

    // --- GETTER & SETTER (Clean English) ---

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getYear(): ?int { return $this->year; }
    public function setYear(int $year): self { $this->year = $year; return $this; }

    public function getDate(): ?\DateTimeInterface { return $this->date; }
    public function setDate(?\DateTimeInterface $date): self { $this->date = $date; return $this; }

    // String Repräsentation
    public function __toString(): string 
    { 
        return $this->name ?? (string)$this->year; 
    }
    
    // Hilfsmethode, falls man "Sportabzeichen 2024" braucht
    public function getDisplayName(): string
    {
        return 'Sportabzeichen ' . $this->year;
    }
}
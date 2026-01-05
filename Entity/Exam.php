<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_exams')]
class Exam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $examName = null;

    #[ORM\Column(type: 'integer')]
    private ?int $examYear = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $examDate = null;

    public function getId(): ?int { return $this->id; }

    public function getExamName(): ?string { return $this->examName; }
    public function setExamName(string $name): self { $this->examName = $name; return $this; }

    public function getExamYear(): ?int { return $this->examYear; }
    public function setExamYear(int $year): self { $this->examYear = $year; return $this; }

    public function getExamDate(): ?\DateTimeInterface { return $this->examDate; }
    public function setExamDate(?\DateTimeInterface $date): self { $this->examDate = $date; return $this; }
    
    // Kleiner Helfer für das Entity
    public function __toString(): string { return $this->examName ?? (string)$this->examYear; }
}
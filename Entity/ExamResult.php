<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_exam_results')]
class ExamResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ExamParticipant::class, inversedBy: 'results')]
    #[ORM\JoinColumn(name: 'ep_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?ExamParticipant $examParticipant = null;

    #[ORM\ManyToOne(targetEntity: Discipline::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Discipline $discipline = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $leistung = null; // Als Float speichern, z.B. 12.5 (Sekunden) oder 4.20 (Meter)

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stufe = null;

    #[ORM\Column(type: 'integer')]
    private ?int $points = null; // 0, 1, 2, 3

    // Getter & Setter ...
    public function getId(): ?int { return $this->id; }

    public function getExamParticipant(): ?ExamParticipant { return $this->examParticipant; }
    public function setExamParticipant(?ExamParticipant $ep): self { 
        $this->examParticipant = $ep; 
        return $this; 
    }

    public function getDiscipline(): ?Discipline { return $this->discipline; }
    public function setDiscipline(?Discipline $discipline): self { 
        $this->discipline = $discipline; 
        return $this; 
    }

    public function getLeistung(): ?float { return $this->leistung; }
    public function setLeistung(?float $leistung): self { 
        $this->leistung = $leistung; 
        return $this; 
    }

    public function getPoints(): ?int { return $this->points; }
    public function setPoints(int $points): self { 
        $this->points = $points; 
        return $this; 
    }

    public function getStufe(): ?string { return $this->stufe; }
    public function setStufe(?string $stufe): self { 
        $this->stufe = $stufe; 
        return $this; 
    }
}
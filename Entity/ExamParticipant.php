<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Entity\Exam;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_exam_participants')]
class ExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[ORM\ManyToOne(targetEntity: Exam::class)]
    #[ORM\JoinColumn(nullable: false, name: 'exam_id')]
    private ?Exam $exam = null;
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Participant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    // Falls du eine Exam-Entity hast, hier verknüpfen. Sonst erst mal nur das Jahr.
    #[ORM\Column(type: 'integer')]
    private ?int $examYear = null; 

    #[ORM\Column(type: 'integer')]
    private ?int $ageYear = null; // Das Alter im Prüfungsjahr

    // Relation zu den Ergebnissen (siehe unten)
    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'])]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    // Getter & Setter ...
    public function getExam(): ?Exam
    {
        return $this->exam;
    }

    public function setExam(?Exam $exam): self
    {
        $this->exam = $exam;

        return $this;
    }
    public function getId(): ?int { return $this->id; }
    
    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { 
        $this->participant = $participant; 
        return $this; 
    }

    public function getExamYear(): ?int { return $this->examYear; }
    public function setExamYear(int $examYear): self { 
        $this->examYear = $examYear; 
        return $this; 
    }
    
    public function getAgeYear(): ?int { return $this->ageYear; }
    public function setAgeYear(int $ageYear): self { 
        $this->ageYear = $ageYear; 
        return $this; 
    }

    /**
     * @return Collection<int, ExamResult>
     */
    public function getResults(): Collection { return $this->results; }
}
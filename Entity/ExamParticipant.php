<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Repository\ExamParticipantRepository;

#[ORM\Entity(repositoryClass: ExamParticipantRepository::class)]
#[ORM\Table(name: 'sportabzeichen_exam_participants')]
class ExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;
    
    // Die Verbindung zur Prüfung (Hierüber holen wir das Jahr)
    #[ORM\ManyToOne(targetEntity: Exam::class)]
    #[ORM\JoinColumn(nullable: false, name: 'exam_id')]
    private ?Exam $exam = null;

    #[ORM\ManyToOne(targetEntity: Participant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    // HINWEIS: $examYear wurde hier entfernt, da es jetzt über $exam läuft.

    #[ORM\Column(type: 'integer')]
    private ?int $ageYear = null; // Das Alter im Prüfungsjahr

    // Relation zu den Ergebnissen
    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'])]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int 
    { 
        return $this->id; 
    }

    public function getExam(): ?Exam
    {
        return $this->exam;
    }

    public function setExam(?Exam $exam): self
    {
        $this->exam = $exam;
        return $this;
    }
    
    public function getParticipant(): ?Participant 
    { 
        return $this->participant; 
    }

    public function setParticipant(?Participant $participant): self 
    { 
        $this->participant = $participant; 
        return $this; 
    }

    /**
     * Hilfsmethode: Holt das Jahr direkt aus dem verknüpften Exam-Objekt.
     * So muss der Rest des Codes nicht geändert werden.
     */
    public function getExamYear(): ?int 
    { 
        return $this->exam ? $this->exam->getExamYear() : null; 
    }
    
    public function getAgeYear(): ?int 
    { 
        return $this->ageYear; 
    }

    public function setAgeYear(int $ageYear): self 
    { 
        $this->ageYear = $ageYear; 
        return $this; 
    }

    /**
     * @return Collection<int, ExamResult>
     */
    public function getResults(): Collection 
    { 
        return $this->results; 
    }
    
    // Methoden für Punkteberechnung (Dummy-Platzhalter, falls du sie im Controller nutzt)
    public function getTotalPoints(): int
    {
        $points = 0;
        foreach ($this->results as $result) {
            $points += $result->getPoints();
        }
        return $points;
    }

    public function getFinalMedal(): ?string
    {
        // Einfache Logik, muss wahrscheinlich an deine Regeln angepasst werden
        $points = $this->getTotalPoints();
        if ($points >= 11) return 'Gold'; // Beispielwerte
        if ($points >= 8) return 'Silber';
        if ($points >= 4) return 'Bronze';
        return null;
    }
}
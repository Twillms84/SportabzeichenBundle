<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\ExamParticipantRepository;

#[ORM\Entity(repositoryClass: ExamParticipantRepository::class)]
#[ORM\Table(name: 'sportabzeichen_exam_participants')]
#[ORM\UniqueConstraint(name: 'uniq_exam_participant', columns: ['exam_id', 'participant_id'])]
class ExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Exam::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', name: 'exam_id')]
    private ?Exam $exam = null;

    #[ORM\ManyToOne(targetEntity: Participant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', name: 'participant_id')]
    private ?Participant $participant = null;

    // PHP: $age  <-->  DB: 'age_year'
    #[ORM\Column(type: 'integer', name: 'age_year')]
    private ?int $age = null;

    // NEU: Wir speichern die Punkte fest in der DB für Performance & Statistiken
    #[ORM\Column(type: 'integer', nullable: true, name: 'total_points')]
    private ?int $totalPoints = 0;

    // NEU: Wir speichern die Medaille fest in der DB
    #[ORM\Column(length: 20, nullable: true, name: 'final_medal')]
    private ?string $finalMedal = 'none';

    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    // --- GETTER & SETTER ---

    public function getId(): ?int { return $this->id; }

    public function getExam(): ?Exam { return $this->exam; }
    public function setExam(?Exam $exam): self { $this->exam = $exam; return $this; }

    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { $this->participant = $participant; return $this; }

    // Helper: Greift auf das Jahr des verknüpften Exams zu
    public function getExamYear(): ?int { return $this->exam?->getYear(); }

    // Umbenannt: getAgeYear -> getAge
    public function getAge(): ?int { return $this->age; }
    public function setAge(int $age): self { $this->age = $age; return $this; }
    // Fallback Alias, falls alter Code noch getAgeYear aufruft:
    public function getAgeYear(): ?int { return $this->age; }

    public function getTotalPoints(): ?int { return $this->totalPoints; }
    public function setTotalPoints(?int $totalPoints): self { $this->totalPoints = $totalPoints; return $this; }

    public function getFinalMedal(): ?string { return $this->finalMedal; }
    public function setFinalMedal(?string $finalMedal): self { $this->finalMedal = $finalMedal; return $this; }

    /**
     * @return Collection<int, ExamResult>
     */
    public function getResults(): Collection
    {
        return $this->results;
    }
}
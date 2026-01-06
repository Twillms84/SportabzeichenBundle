<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\ExamParticipantRepository;

#[ORM\Entity]
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

    // Mapping auf deine existierende Spalte "age_year"
    #[ORM\Column(type: 'integer', name: 'age_year')]
    private ?int $age = null;

    // Existierende Spalte "total_points"
    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 0], name: 'total_points')]
    private ?int $totalPoints = 0;

    // Existierende Spalte "final_medal" (Länge 10 laut deinem Schema)
    #[ORM\Column(length: 10, nullable: true, options: ['default' => 'NONE'], name: 'final_medal')]
    private ?string $finalMedal = 'NONE';

    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getExam(): ?Exam { return $this->exam; }
    public function setExam(?Exam $exam): self { $this->exam = $exam; return $this; }

    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { $this->participant = $participant; return $this; }

    // Der Controller sucht oft nach "getExamYear", das leiten wir hier weiter
    public function getExamYear(): ?int { return $this->exam?->getYear(); }

    // Wir nennen es im Code "$age", mappen es aber auf "age_year"
    public function getAge(): ?int { return $this->age; }
    public function setAge(int $age): self { $this->age = $age; return $this; }
    // Fallback Alias für alten Code
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
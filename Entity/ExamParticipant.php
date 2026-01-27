<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use PulsR\SportabzeichenBundle\Repository\ExamParticipantRepository;

#[ORM\Entity] // Ggf. hier repositoryClass hinzufügen, falls du eins hast
#[ORM\Table(name: 'sportabzeichen_exam_participants')]
#[ORM\UniqueConstraint(name: 'sportabzeichen_exam_participants_exam_id_participant_id_key', columns: ['exam_id', 'participant_id'])]
class ExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Exam::class)]
    #[ORM\JoinColumn(name: 'exam_id', nullable: false, onDelete: 'CASCADE')]
    private ?Exam $exam = null;

    // HIER WAR EINE LÜCKE: "inversedBy" ist nötig, damit $participant->getExamParticipants() gefüllt wird!
    #[ORM\ManyToOne(targetEntity: Participant::class, inversedBy: 'examParticipants')]
    #[ORM\JoinColumn(name: 'participant_id', nullable: false, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    // DB: age_year (not null) -> PHP: $age
    #[ORM\Column(type: 'integer', name: 'age_year')]
    private ?int $age = null;

    // DB: total_points (default 0)
    #[ORM\Column(type: 'integer', options: ['default' => 0], name: 'total_points')]
    private int $totalPoints = 0;

    // DB: final_medal (default 'NONE')
    #[ORM\Column(type: 'string', length: 10, options: ['default' => 'NONE'], name: 'final_medal')]
    private string $finalMedal = 'NONE';

    // Relation zu den Ergebnissen
    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: ExamResult::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $results;

    public function __construct()
    {
        // Falls das fehlt, bitte ergänzen:
        $this->results = new \Doctrine\Common\Collections\ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getExam(): ?Exam { return $this->exam; }
    public function setExam(?Exam $exam): self { $this->exam = $exam; return $this; }

    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { $this->participant = $participant; return $this; }

    public function getExamYear(): ?int { return $this->exam?->getYear(); }

    public function getAge(): ?int { return $this->age; }
    public function setAge(int $age): self { $this->age = $age; return $this; }
    public function getAgeYear(): ?int { return $this->age; } // Alias

    public function getTotalPoints(): int { return $this->totalPoints; }
    public function setTotalPoints(int $totalPoints): self { $this->totalPoints = $totalPoints; return $this; }

    public function getFinalMedal(): string { return $this->finalMedal; }
    public function setFinalMedal(string $finalMedal): self { $this->finalMedal = $finalMedal; return $this; }

    /**
     * @return Collection<int, ExamResult>
     */
    public function getResults(): Collection { return $this->results; }

    public function addResult(ExamResult $result): self
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
            $result->setExamParticipant($this);
        }

        return $this;
    }

    public function removeResult(ExamResult $result): self
    {
        if ($this->results->removeElement($result)) {
            // set the owning side to null (unless already changed)
            if ($result->getExamParticipant() === $this) {
                $result->setExamParticipant(null);
            }
        }

        return $this;
    }
}
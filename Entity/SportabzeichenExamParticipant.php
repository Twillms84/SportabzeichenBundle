<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity]
#[ORM\Table(
    name: 'sportabzeichen_exam_participants',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'uniq_exam_participant',
            columns: ['exam_id', 'participant_id']
        )
    ]
)]
class SportabzeichenExamParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: SportabzeichenExam::class, inversedBy: 'examParticipants')]
    #[ORM\JoinColumn(name: 'exam_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private SportabzeichenExam $exam;

    #[ORM\ManyToOne(targetEntity: SportabzeichenParticipant::class, inversedBy: 'examParticipations')]
    #[ORM\JoinColumn(name: 'participant_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private SportabzeichenParticipant $participant;

    #[ORM\OneToMany(mappedBy: 'examParticipant', targetEntity: SportabzeichenExamResult::class, cascade: ['remove'])]
    private Collection $results;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    // ---------------------------------------
    // GETTER / SETTER
    // ---------------------------------------

    public function getId(): int
    {
        return $this->id;
    }

    public function getExam(): SportabzeichenExam
    {
        return $this->exam;
    }

    public function setExam(SportabzeichenExam $exam): self
    {
        $this->exam = $exam;
        return $this;
    }

    public function getParticipant(): SportabzeichenParticipant
    {
        return $this->participant;
    }

    public function setParticipant(SportabzeichenParticipant $participant): self
    {
        $this->participant = $participant;
        return $this;
    }

    public function getResults(): Collection
    {
        return $this->results;
    }

    public function __toString(): string
    {
        return $this->participant->getNachname() . ', ' . $this->participant->getVorname();
    }
}

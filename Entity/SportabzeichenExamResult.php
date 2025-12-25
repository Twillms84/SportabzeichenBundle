<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_exam_results')]
#[ORM\UniqueConstraint(name: 'uniq_exam_discipline', columns: ['ep_id', 'discipline_id'])]
class SportabzeichenExamResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: SportabzeichenExamParticipant::class, inversedBy: 'results')]
    #[ORM\JoinColumn(name: 'ep_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private SportabzeichenExamParticipant $examParticipant;

    #[ORM\ManyToOne(targetEntity: SportabzeichenDiscipline::class)]
    #[ORM\JoinColumn(name: 'discipline_id', referencedColumnName: 'id')]
    private SportabzeichenDiscipline $discipline;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $leistung = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $stufe = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $points = null;

    // ---------------------------------------
    // GETTER / SETTER
    // ---------------------------------------

    public function getId(): int
    {
        return $this->id;
    }

    public function getExamParticipant(): SportabzeichenExamParticipant
    {
        return $this->examParticipant;
    }

    public function setExamParticipant(SportabzeichenExamParticipant $ep): self
    {
        $this->examParticipant = $ep;
        return $this;
    }

    public function getDiscipline(): SportabzeichenDiscipline
    {
        return $this->discipline;
    }

    public function setDiscipline(SportabzeichenDiscipline $discipline): self
    {
        $this->discipline = $discipline;
        return $this;
    }

    public function getLeistung(): ?float
    {
        return $this->leistung;
    }

    public function setLeistung(?float $value): self
    {
        $this->leistung = $value;
        return $this;
    }

    public function getStufe(): ?string
    {
        return $this->stufe;
    }

    public function setStufe(?string $stufe): self
    {
        $this->stufe = $stufe;
        return $this;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function setPoints(?int $points): self
    {
        $this->points = $points;
        return $this;
    }

    public function __toString(): string
    {
        return $this->discipline->getName() . ' – ' . ($this->leistung ?? '?');
    }
}

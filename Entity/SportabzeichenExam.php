<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use IServ\CrudBundle\Entity\CrudInterface;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_exams')]
class SportabzeichenExam implements CrudInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $examName = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $examDate = null;

    #[ORM\Column(type: 'integer')]
    private int $examYear;

    #[ORM\Column(type: 'datetimetz')]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetimetz')]
    private \DateTimeInterface $updatedAt;

    #[ORM\OneToMany(mappedBy: 'exam', targetEntity: SportabzeichenExamParticipant::class, cascade: ['remove'])]
    private Collection $examParticipants;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->examParticipants = new ArrayCollection();
    }

    // --- CrudInterface: REQUIRED ---
    public function __toString(): string
    {
        return $this->examName
            ? $this->examName . ' (' . $this->examYear . ')'
            : 'Prüfung ' . $this->examYear;
    }

    // --- Getter/Setter ---

    public function getId(): int
    {
        return $this->id;
    }

    public function getExamName(): ?string
    {
        return $this->examName;
    }

    public function setExamName(?string $name): self
    {
        $this->examName = $name;
        return $this;
    }

    public function getExamDate(): ?\DateTimeInterface
    {
        return $this->examDate;
    }

    public function setExamDate(?\DateTimeInterface $date): self
    {
        $this->examDate = $date;
        return $this;
    }

    public function getExamYear(): int
    {
        return $this->examYear;
    }

    public function setExamYear(int $year): self
    {
        $this->examYear = $year;
        return $this;
    }

    public function getExamParticipants(): Collection
    {
        return $this->examParticipants;
    }
    public function getCreatedAt(): ?\DateTimeInterface
    {
    return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
    return $this->updatedAt;
    }
}

<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use IServ\CoreBundle\Entity\User;
use IServ\CrudBundle\Entity\CrudInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use PulsR\SportabzeichenBundle\Repository\ParticipantRepository;

#[ORM\Entity(repositoryClass: ParticipantRepository::class)]
#[ORM\Table(name: 'sportabzeichen_participants')]
class Participant implements CrudInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    // PHP: $birthdate  <-->  DB: 'geburtsdatum'
    #[ORM\Column(type: 'date', nullable: true, name: 'geburtsdatum')]
    private ?\DateTimeInterface $birthdate = null;

    // PHP: $gender  <-->  DB: 'geschlecht'
    #[ORM\Column(type: 'string', length: 10, nullable: true, name: 'geschlecht')]
    private ?string $gender = null; 

    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: SwimmingProof::class, cascade: ['remove'])]
    private Collection $swimmingProofs;

    // Optional: Rückbeziehung zu den Teilnahmen (falls du mal wissen willst "An welchen Prüfungen hat dieser Teilnehmer teilgenommen?")
    #[ORM\OneToMany(mappedBy: 'participant', targetEntity: ExamParticipant::class, cascade: ['remove'])]
    private Collection $examParticipants;

    public function __construct()
    {
        $this->swimmingProofs = new ArrayCollection();
        $this->examParticipants = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): self { $this->user = $user; return $this; }

    // --- UMBENANNT: getGeburtsdatum -> getBirthdate ---
    public function getBirthdate(): ?\DateTimeInterface { return $this->birthdate; }
    public function setBirthdate(?\DateTimeInterface $birthdate): self { $this->birthdate = $birthdate; return $this; }
    // Fallback Alias, falls alter Code noch getGeburtsdatum sucht:
    public function getGeburtsdatum(): ?\DateTimeInterface { return $this->birthdate; }


    // --- UMBENANNT: getGeschlecht -> getGender ---
    // Das behebt deinen Fehler im ExamResultController
    public function getGender(): ?string { return $this->gender; }
    public function setGender(?string $gender): self { $this->gender = $gender; return $this; }
    // Fallback Alias:
    public function getGeschlecht(): ?string { return $this->gender; }


    /**
     * @return Collection<int, SwimmingProof>
     */
    public function getSwimmingProofs(): Collection
    {
        return $this->swimmingProofs;
    }

    /**
     * @return Collection<int, ExamParticipant>
     */
    public function getExamParticipants(): Collection
    {
        return $this->examParticipants;
    }

    public function __toString(): string
    {
        return $this->user ? (string)$this->user : 'Unbekannt';
    }
}
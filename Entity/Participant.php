<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use IServ\CoreBundle\Entity\User;
use IServ\CrudBundle\Entity\CrudInterface;

#[ORM\Entity(repositoryClass: 'PulsR\SportabzeichenBundle\Repository\ParticipantRepository')]
#[ORM\Table(name: 'sportabzeichen_participants')] // Wir behalten den alten Tabellennamen
class Participant implements CrudInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $vorname = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $nachname = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $geburtsdatum = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $geschlecht = null; // m, w, d

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $klasse = null; // z.B. "5a", "LK Sport"

    // ------------------------------------
    // GETTER & SETTER
    // ------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getVorname(): ?string
    {
        return $this->vorname;
    }

    public function setVorname(?string $vorname): self
    {
        $this->vorname = $vorname;
        return $this;
    }

    public function getNachname(): ?string
    {
        return $this->nachname;
    }

    public function setNachname(?string $nachname): self
    {
        $this->nachname = $nachname;
        return $this;
    }

    public function getGeburtsdatum(): ?\DateTimeInterface
    {
        return $this->geburtsdatum;
    }

    public function setGeburtsdatum(?\DateTimeInterface $geburtsdatum): self
    {
        $this->geburtsdatum = $geburtsdatum;
        return $this;
    }

    public function getGeschlecht(): ?string
    {
        return $this->geschlecht;
    }

    public function setGeschlecht(?string $geschlecht): self
    {
        $this->geschlecht = $geschlecht;
        return $this;
    }

    public function getKlasse(): ?string
    {
        return $this->klasse;
    }

    public function setKlasse(?string $klasse): self
    {
        $this->klasse = $klasse;
        return $this;
    }

    public function __toString(): string
    {
        return $this->nachname . ', ' . $this->vorname;
    }
}
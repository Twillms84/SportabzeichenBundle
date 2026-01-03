<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use IServ\CoreBundle\Entity\User;
use IServ\CrudBundle\Entity\CrudInterface;

#[ORM\Entity(repositoryClass: 'PulsR\SportabzeichenBundle\Repository\ParticipantRepository')]
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

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $geburtsdatum = null;

    #[ORM\Column(type: 'string', length: 10, nullable: true)]
    private ?string $geschlecht = null; 

    // KEINE Spalten für Vorname, Nachname, Klasse!

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

    // Hilfsmethode für die Anzeige als String
    public function __toString(): string
    {
        return $this->user ? (string)$this->user : 'Unbekannt';
    }
}
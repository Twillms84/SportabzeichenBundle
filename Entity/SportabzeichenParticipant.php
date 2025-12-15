<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use IServ\CrudBundle\Entity\CrudInterface;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_participants')]
class SportabzeichenParticipant implements CrudInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'text', unique: true)]
    private string $importId;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $vorname = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $nachname = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $geschlecht = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $geburtsdatum = null;

    #[ORM\Column(type: 'datetimetz')]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // --- CrudInterface: REQUIRED ---
    public function __toString(): string
    {
        return trim(($this->nachname ?? '') . ', ' . ($this->vorname ?? ''));
    }

    // --- Getter/Setter ---
    public function getId(): int
    {
        return $this->id;
    }

    public function getImportId(): string
    {
        return $this->importId;
    }

    public function setImportId(string $importId): self
    {
        $this->importId = $importId;
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

    public function getGeschlecht(): ?string
    {
        return $this->geschlecht;
    }

    public function setGeschlecht(?string $geschlecht): self
    {
        $this->geschlecht = $geschlecht;
        return $this;
    }

    public function getGeburtsdatum(): ?\DateTimeInterface
    {
        return $this->geburtsdatum;
    }

    public function setGeburtsdatum(?\DateTimeInterface $date): self
    {
        $this->geburtsdatum = $date;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }
}

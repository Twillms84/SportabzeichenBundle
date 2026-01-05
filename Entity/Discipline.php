<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_disciplines')]
class Discipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: 'string', length: 50)]
    private ?string $kategorie = null; // z.B. "Ausdauer", "Kraft"

    #[ORM\Column(type: 'boolean')]
    private bool $schwimmnachweis = false; // Das Flag, das wir oft prüfen!

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $einheit = null; // 's', 'm', 'min'

    #[ORM\Column(type: 'string', length: 20, nullable: true)]
    private ?string $berechnungsart = 'BIGGER'; // 'BIGGER' (Weite/Anzahl) oder 'SMALLER' (Laufzeit)

    // Getter & Setter
    public function getEinheit(): ?string { return $this->einheit; }
    public function setEinheit(?sstring $art): self { $this->berechnungsart = $art; rtring $einheit): self { $this->einheit = $einheit; return $this; }

    public function getBerechnungsart(): ?string { return $this->berechnungsart; }
    public function setBerechnungsart(?eturn $this; }

    public function getId(): ?int { return $this->id; }
    public function getName(): ?string { return $this->name; }
    public function getKategorie(): ?string { return $this->kategorie; }
    public function isSchwimmnachweis(): bool { return $this->schwimmnachweis; }
    
    // Hilfsmethode
    public function isSwimmingCategory(): bool 
    {
        return $this->schwimmnachweis || str_contains(strtoupper($this->kategorie ?? ''), 'SCHWIMM');
    }
}
<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_requirements')]
class Requirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Discipline::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Discipline $discipline = null;

    #[ORM\Column(type: 'integer')]
    private ?int $jahr = null;

    #[ORM\Column(type: 'string', length: 10)]
    private ?string $geschlecht = null; // 'MALE' oder 'FEMALE'

    #[ORM\Column(type: 'integer')]
    private ?int $ageMin = null;

    #[ORM\Column(type: 'integer')]
    private ?int $ageMax = null;

    // Wir speichern die Werte als String, um Formate wie "1:30" oder "1,50" flexibel zu halten
    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $gold = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $silber = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    private ?string $bronze = null;
    
    #[ORM\Column(type: 'integer')]
    private ?int $auswahlnummer = 0; // Für die Sortierung (1, 2, 3...)

    // Getter & Setter
    public function getId(): ?int { return $this->id; }
    
    public function getDiscipline(): ?Discipline { return $this->discipline; }
    public function setDiscipline(?Discipline $d): self { $this->discipline = $d; return $this; }

    public function getJahr(): ?int { return $this->jahr; }
    public function setJahr(int $jahr): self { $this->jahr = $jahr; return $this; }

    public function getGeschlecht(): ?string { return $this->geschlecht; }
    public function setGeschlecht(string $g): self { $this->geschlecht = $g; return $this; }

    public function getAgeMin(): ?int { return $this->ageMin; }
    public function setAgeMin(int $age): self { $this->ageMin = $age; return $this; }

    public function getAgeMax(): ?int { return $this->ageMax; }
    public function setAgeMax(int $age): self { $this->ageMax = $age; return $this; }

    public function getGold(): ?string { return $this->gold; }
    public function getSilber(): ?string { return $this->silber; }
    public function getBronze(): ?string { return $this->bronze; }
    
    public function getAuswahlnummer(): int { return $this->auswahlnummer; }
}
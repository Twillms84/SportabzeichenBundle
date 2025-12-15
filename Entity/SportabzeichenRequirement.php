<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'sportabzeichen_requirements',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: "uniq_req",
            columns: ["discipline_id", "jahr", "altersklasse", "geschlecht"]
        )
    ]
)]
class SportabzeichenRequirement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\ManyToOne(targetEntity: SportabzeichenDiscipline::class)]
    #[ORM\JoinColumn(name: "discipline_id", referencedColumnName: "id", onDelete: "CASCADE")]
    private SportabzeichenDiscipline $discipline;

    #[ORM\Column(type: 'integer')]
    private int $jahr;

    #[ORM\Column(type: 'text')]
    private string $altersklasse;

    #[ORM\Column(type: 'text')]
    private string $geschlecht;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $bronze = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $silber = null;

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $gold = null;

    #[ORM\Column(type: 'boolean')]
    private bool $schwimmnachweis = false;

    // ---------------------------------------
    // GETTER / SETTER
    // ---------------------------------------

    public function getId(): int
    {
        return $this->id;
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

    public function getJahr(): int
    {
        return $this->jahr;
    }

    public function setJahr(int $jahr): self
    {
        $this->jahr = $jahr;
        return $this;
    }

    public function getAltersklasse(): string
    {
        return $this->altersklasse;
    }

    public function setAltersklasse(string $ak): self
    {
        $this->altersklasse = $ak;
        return $this;
    }

    public function getGeschlecht(): string
    {
        return $this->geschlecht;
    }

    public function setGeschlecht(string $g): self
    {
        $this->geschlecht = $g;
        return $this;
    }

    public function getBronze(): ?float
    {
        return $this->bronze;
    }

    public function setBronze(?float $val): self
    {
        $this->bronze = $val;
        return $this;
    }

    public function getSilber(): ?float
    {
        return $this->silber;
    }

    public function setSilber(?float $val): self
    {
        $this->silber = $val;
        return $this;
    }

    public function getGold(): ?float
    {
        return $this->gold;
    }

    public function setGold(?float $val): self
    {
        $this->gold = $val;
        return $this;
    }

    public function hasSchwimmnachweis(): bool
    {
        return $this->schwimmnachweis;
    }

    public function setSchwimmnachweis(bool $value): self
    {
        $this->schwimmnachweis = $value;
        return $this;
    }

    public function __toString(): string
    {
        return sprintf(
            '%s / %s / %s (%d)',
            $this->discipline->getName(),
            $this->altersklasse,
            $this->geschlecht,
            $this->jahr
        );
    }
}

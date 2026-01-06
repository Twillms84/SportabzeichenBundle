<?php

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

#[ORM\Entity(repositoryClass: 'PulsR\SportabzeichenBundle\Repository\DisciplineRepository')]
#[ORM\Table(name: 'sportabzeichen_disciplines')]
class Discipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    // Wir nennen es jetzt 'unit' statt 'einheit' für Konsistenz
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $unit = null;

    // Die Kategorie (z.B. 'AUSDAUER', 'KRAFT')
    #[ORM\Column(type: 'string', length: 50)]
    private ?string $category = null;

    // Rückbeziehung zu den Requirements (Anforderungen)
    #[ORM\OneToMany(mappedBy: 'discipline', targetEntity: Requirement::class, orphanRemoval: true)]
    private Collection $requirements;

    #[ORM\Column(type: 'text', options: ['default' => 'GREATER'])]
    private ?string $berechnungsart = 'GREATER';

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verband = null;

    public function __construct()
    {
        $this->requirements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->einheit;
    }

    public function setUnit(string $unit): self
    {
        $this->einheit = $unit;
        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->kategorie;
    }

    public function setCategory(string $category): self
    {
        $this->kategorie = $category;
        return $this;
    }

    public function getBerechnungsart(): ?string
    {
        return $this->berechnungsart;
    }

    public function setBerechnungsart(string $berechnungsart): self
    {
        $this->berechnungsart = $berechnungsart;
        return $this;
    }

    public function getVerband(): ?string
    {
        return $this->verband;
    }

    public function setVerband(?string $verband): self
    {
        $this->verband = $verband;
        return $this;
    }

    /**
     * @return Collection<int, Requirement>
     */
    public function getRequirements(): Collection
    {
        return $this->requirements;
    }

    public function addRequirement(Requirement $requirement): self
    {
        if (!$this->requirements->contains($requirement)) {
            $this->requirements[] = $requirement;
            $requirement->setDiscipline($this);
        }

        return $this;
    }

    public function removeRequirement(Requirement $requirement): self
    {
        if ($this->requirements->removeElement($requirement)) {
            // set the owning side to null (unless already changed)
            if ($requirement->getDiscipline() === $this) {
                $requirement->setDiscipline(null);
            }
        }

        return $this;
    }
    
    // Hilfsmethode für alte Templates, falls noch jemand getEinheit() aufruft
    public function getEinheit(): ?string
    {
        return $this->getUnit();
    }
}
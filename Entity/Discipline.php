<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use PulsR\SportabzeichenBundle\Repository\DisciplineRepository;

#[ORM\Entity(repositoryClass: DisciplineRepository::class)]
#[ORM\Table(name: 'sportabzeichen_disciplines')]
class Discipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $name = null;

    // Mapping auf DB-Spalte 'einheit'
    #[ORM\Column(type: 'string', length: 50, name: 'einheit')]
    private ?string $unit = null;

    // Mapping auf DB-Spalte 'kategorie'
    #[ORM\Column(type: 'string', length: 50, name: 'kategorie')]
    private ?string $category = null;

    // Mapping auf Requirement (Stelle sicher, dass Requirement::discipline existiert!)
    #[ORM\OneToMany(mappedBy: 'discipline', targetEntity: Requirement::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $requirements;

    #[ORM\Column(type: 'text', options: ['default' => 'GREATER'])]
    private string $berechnungsart = 'GREATER'; // Typ direkt auf string gesetzt, da Default vorhanden

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $verband = null;

    public function __construct()
    {
        $this->requirements = new ArrayCollection();
    }

    // --- WICHTIG FÜR FORMULARE ---
    public function __toString(): string
    {
        // Zeigt z.B. "3000m Lauf (Ausdauer)" an
        return sprintf('%s (%s)', $this->name ?? 'Neue Disziplin', $this->category ?? '-');
    }

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    public function getUnit(): ?string { return $this->unit; }
    public function setUnit(string $unit): self { $this->unit = $unit; return $this; }
    // Fallback für alte Templates
    public function getEinheit(): ?string { return $this->unit; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(string $category): self { $this->category = $category; return $this; }

    public function getBerechnungsart(): string { return $this->berechnungsart; }
    public function setBerechnungsart(string $berechnungsart): self { $this->berechnungsart = $berechnungsart; return $this; }

    public function getVerband(): ?string { return $this->verband; }
    public function setVerband(?string $verband): self { $this->verband = $verband; return $this; }

    /**
     * @return Collection<int, Requirement>
     */
    public function getRequirements(): Collection { return $this->requirements; }

    public function addRequirement(Requirement $requirement): self
    {
        if (!$this->requirements->contains($requirement)) {
            $this->requirements[] = $requirement;
            $requirement->setDiscipline($this);
        }
        return $this;
    }
    public function isSwimmingCategory(): bool
    {
        // 1. Check: Steht "Schwimmen" im Namen oder der Kategorie?
        // Wir nutzen ?? '', falls name oder category null sind, um Fehler zu vermeiden.
        $nameCheck = str_contains(strtolower($this->name ?? ''), 'schwimmen');
        $catCheck  = str_contains(strtolower($this->category ?? ''), 'schwimmen');

        if ($nameCheck || $catCheck) {
            return true;
        }

        // 2. Check: Prüfen der Requirements (DB-Flag)
        // Wir loopen durch alle Anforderungen dieser Disziplin.
        foreach ($this->requirements as $req) {
            // Prüfen auf die neue Methode (Englisch)
            if (method_exists($req, 'isSwimmingProof') && $req->isSwimmingProof()) {
                return true;
            }
            // Fallback: Prüfen auf die alte Methode (Deutsch), falls der Getter so hieß
            if (method_exists($req, 'isSchwimmnachweis') && $req->isSchwimmnachweis()) {
                return true;
            }
        }

        // --- WICHTIG: DIESE ZEILE HAT GEFEHLT ---
        // Wenn weder der Name passt noch ein Requirement das Flag hat:
        return false;
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
}
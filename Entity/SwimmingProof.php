<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'PulsR\SportabzeichenBundle\Repository\SwimmingProofRepository')]
#[ORM\Table(name: 'sportabzeichen_swimming_proofs')]
class SwimmingProof
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Participant::class, inversedBy: 'swimmingProofs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Participant $participant = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $confirmedAt = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $validUntil = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $requirementMetVia = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $examYear = null;

    // Getter & Setter ...

    public function getId(): ?int { return $this->id; }

    public function getParticipant(): ?Participant { return $this->participant; }
    public function setParticipant(?Participant $participant): self { 
        $this->participant = $participant; 
        return $this; 
    }

    public function getConfirmedAt(): ?\DateTimeInterface { return $this->confirmedAt; }
    public function setConfirmedAt(\DateTimeInterface $confirmedAt): self { 
        $this->confirmedAt = $confirmedAt; 
        return $this; 
    }

    public function getValidUntil(): ?\DateTimeInterface { return $this->validUntil; }
    public function setValidUntil(\DateTimeInterface $validUntil): self { 
        $this->validUntil = $validUntil; 
        return $this; 
    }

    public function getRequirementMetVia(): ?string { return $this->requirementMetVia; }
    public function setRequirementMetVia(?string $requirementMetVia): self { 
        $this->requirementMetVia = $requirementMetVia; 
        return $this; 
    }

    public function getExamYear(): ?int { return $this->examYear; }
    public function setExamYear(?int $examYear): self { 
        $this->examYear = $examYear; 
        return $this; 
    }
}
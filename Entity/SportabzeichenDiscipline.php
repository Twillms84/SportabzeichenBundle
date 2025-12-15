<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'sportabzeichen_disciplines')]
class SportabzeichenDiscipline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private int $id;

    #[ORM\Column(type: 'text')]
    private string $name;

    #[ORM\Column(type: 'text')]
    private string $kategorie;

    #[ORM\Column(type: 'text')]
    private string $einheit;

    #[ORM\Column(type: 'text')]
    private string $berechnungsart;

    #[ORM\Column(type: 'datetimetz')]
    private \DateTimeInterface $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getKategorie(): string
    {
        return $this->kategorie;
    }

    public function getEinheit(): string
    {
        return $this->einheit;
    }

    public function getBerechnungsart(): string
    {
        return $this->berechnungsart;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}

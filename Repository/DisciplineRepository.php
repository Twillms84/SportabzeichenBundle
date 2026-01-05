<?php

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PulsR\SportabzeichenBundle\Entity\Discipline;

/**
 * @extends ServiceEntityRepository<Discipline>
 */
class DisciplineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discipline::class);
    }

    // Hier können wir später spezielle Abfragen einbauen, z.B.:
    // "Gib mir alle Disziplinen für Kategorie 'Kraft'"
}
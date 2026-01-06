<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PulsR\SportabzeichenBundle\Entity\Requirement;
use PulsR\SportabzeichenBundle\Entity\Discipline;

/**
 * @extends ServiceEntityRepository<Requirement>
 */
class RequirementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Requirement::class);
    }

    /**
     * Findet die passende Anforderung basierend auf Disziplin, Jahr, Geschlecht und Alter.
     */
    public function findMatchingRequirement(Discipline $discipline, int $year, string $gender, int $age): ?Requirement
    {
        return $this->createQueryBuilder('r')
        ->where('r.discipline = :disc')
        // Nutze hier die Namen der Variablen aus der Requirement-Entity!
        ->andWhere('r.year = :jahr')      // Falls die Variable in der Entity $year heißt
        ->andWhere('r.gender = :gender')  // Falls die Variable in der Entity $gender heißt
        ->andWhere(':age BETWEEN r.ageMin AND r.ageMax') // Meistens camelCase in Entities
        ->setParameters([
            'disc'   => $discipline,
            'jahr'   => $year,
            'gender' => $gender,
            'age'    => $age,
        ])
            ->getQuery()
            ->getOneOrNullResult();
    }
}
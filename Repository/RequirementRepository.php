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
        ->andWhere('r.jahr = :jahr')         // Versuche 'jahr' (oder 'year')
        ->andWhere('r.geschlecht = :gender') // Versuche 'geschlecht' (oder 'gender')
        ->andWhere(':age BETWEEN r.age_min AND r.age_max') // Hier mit Unterstrichen!
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
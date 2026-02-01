<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PulsR\SportabzeichenBundle\Entity\TrainingEntry;

/**
 * @extends ServiceEntityRepository<TrainingEntry>
 *
 * @method TrainingEntry|null find($id, $lockMode = null, $lockVersion = null)
 * @method TrainingEntry|null findOneBy(array $criteria, array $orderBy = null)
 * @method TrainingEntry[]    findAll()
 * @method TrainingEntry[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TrainingEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingEntry::class);
    }

    // Hier können später spezielle Suchfunktionen rein, 
    // z.B. "Finde alle Trainingsergebnisse eines Users für 2026"
    
    public function save(TrainingEntry $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(TrainingEntry $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
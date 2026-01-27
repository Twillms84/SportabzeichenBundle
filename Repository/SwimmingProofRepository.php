<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use PulsR\SportabzeichenBundle\Entity\SwimmingProof;

/**
 * @extends ServiceEntityRepository<SwimmingProof>
 */
class SwimmingProofRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SwimmingProof::class);
    }
}
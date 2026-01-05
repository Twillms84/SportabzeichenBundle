<?php

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\ORM\EntityRepository; // <--- WICHTIG: Das normale Repository nutzen
use PulsR\SportabzeichenBundle\Entity\Exam;

/**
 * @extends ServiceEntityRepository<Exam>
 */
class ExamRepository extends ServiceEntityRepository
{
    // Hier könnten wir später spezielle Suchfunktionen einbauen, z.B.:
    // public function findRecentExams(int $limit): array { ... }
}
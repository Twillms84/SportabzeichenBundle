<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\ORM\EntityRepository; // <--- WICHTIG: Das normale Repository nutzen
use PulsR\SportabzeichenBundle\Entity\Exam;

/**
 * @extends EntityRepository<Exam>
 */
class ExamRepository extends EntityRepository
{
    // Hier könnten wir später spezielle Suchfunktionen einbauen, z.B.:
    // public function findRecentExams(int $limit): array { ... }
}
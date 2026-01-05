<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\ORM\EntityRepository; // <--- WICHTIG: Das normale Repository nutzen
use PulsR\SportabzeichenBundle\Entity\Discipline;

/**
 * @extends EntityRepository<Discipline>
 */
class DisciplineRepository extends EntityRepository
{

    // Hier können wir später spezielle Abfragen einbauen, z.B.:
    // "Gib mir alle Disziplinen für Kategorie 'Kraft'"
}
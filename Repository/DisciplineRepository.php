<?php

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\ORM\EntityRepository; // <--- WICHTIG: Das normale Repository nutzen
use PulsR\SportabzeichenBundle\Entity\Discipline;

/**
 * @extends ServiceEntityRepository<Discipline>
 */
class DisciplineRepository extends ServiceEntityRepository
{

    // Hier können wir später spezielle Abfragen einbauen, z.B.:
    // "Gib mir alle Disziplinen für Kategorie 'Kraft'"
}
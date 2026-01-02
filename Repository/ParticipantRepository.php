<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Repository;

use Doctrine\ORM\EntityRepository; // <--- WICHTIG: Das normale Repository nutzen
use PulsR\SportabzeichenBundle\Entity\Participant;

/**
 * @extends EntityRepository<Participant>
 */
class ParticipantRepository extends EntityRepository
{
    // Wir brauchen hier keinen Konstruktor mehr!
    // Doctrine kümmert sich automatisch um alles.
}
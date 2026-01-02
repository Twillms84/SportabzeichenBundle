<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Domain\User\UserRepository;
use PulsR\SportabzeichenBundle\Entity\Participant;
use PulsR\SportabzeichenBundle\Repository\ParticipantRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/participants", name="sportabzeichen_admin_participants_")
 */
final class AdminParticipantController extends AbstractController
{
    **
    * @Route("/", name="index")
    */
public function index(Request $request, ParticipantRepository $repo): Response
{
    // 1. Minimalste Abfrage: Keine Klasse, nur Name, Limit 10!
    $participants = $repo->createQueryBuilder('p')
        ->select('p.id, p.vorname, p.nachname, p.geburtsdatum, p.geschlecht') // KEIN p.klasse
        ->orderBy('p.nachname', 'ASC')
        ->setMaxResults(10) // Nur 10 Einträge!
        ->getQuery()
        ->getArrayResult();

    // Wir übergeben KEINE Berechnungen (totalCount etc.), nur das rohe Array
    return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
        'participants' => $participants,
        // 'activeTab' => 'participants_manage', // AUSKOMMENTIERT: Wir testen ohne Tabs-Logik
        'currentPage' => 1,
        'maxPages' => 1,
        'totalCount' => 10
    ]);
}
}
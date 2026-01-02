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
    /**
     * @Route("/", name="index")
     */
    public function index(Request $request, ParticipantRepository $repo): Response
    {
        // 1. Paginierung Parameter
        $page = $request->query->getInt('page', 1); // Standard: Seite 1
        $limit = 50; // Maximal 50 Einträge pro Seite (Sicherheitslimit für Speicher!)
        if ($page < 1) $page = 1;
        
        // 2. Gesamtanzahl zählen (für die Berechnung der letzten Seite)
        // Wir zählen effizient nur die IDs
        $totalCount = $repo->createQueryBuilder('p')
            ->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = (int) ceil($totalCount / $limit);

        // 3. Datensätze für die aktuelle Seite holen
        $participants = $repo->createQueryBuilder('p')
            ->select('p.id, p.vorname, p.nachname, p.geburtsdatum, p.geschlecht, p.klasse')
            ->orderBy('p.nachname', 'ASC')
            ->addOrderBy('p.vorname', 'ASC')
            ->setFirstResult(($page - 1) * $limit) // OFFSET
            ->setMaxResults($limit) // LIMIT
            ->getQuery()
            ->getArrayResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab' => 'participants_manage',
            'currentPage' => $page,
            'maxPages' => $maxPages,
            'totalCount' => $totalCount
        ]);
    }
    
    /**
     * @Route("/missing", name="missing")
     */
    public function missing(Request $request, ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        // 1. IDs holen (extrem sparsam)
        $existingIds = $pRepo->createQueryBuilder('p')
            ->select('IDENTITY(p.user)')
            ->where('p.user IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        
        $excludeIds = array_column($existingIds, 1);

        // 2. User suchen (Nur Arrays!)
        $qb = $uRepo->createQueryBuilder('u')
            ->select('u.username, u.firstname, u.lastname') // KEINE ID, KEINE GRUPPEN laden
            ->where('u.act = true')
            ->orderBy('u.lastname', 'ASC')
            ->setMaxResults(50); // Sehr striktes Limit

        if (!empty($excludeIds)) {
            $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
        }

        // Suche
        $searchTerm = $request->query->get('q');
        if ($searchTerm) {
            $qb->andWhere('u.username LIKE :s OR u.firstname LIKE :s OR u.lastname LIKE :s')
               ->setParameter('s', '%' . $searchTerm . '%');
        }

        // ARRAY RESULT IST ENTSCHEIDEND
        $missingUsers = $qb->getQuery()->getArrayResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'activeTab' => 'participants_manage',
            'limit_reached' => count($missingUsers) >= 50,
            'searchTerm' => $searchTerm,
        ]);
    }

    /**
     * @Route("/add/{username}", name="add")
     */
    public function add(string $username, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        $user = $userRepo->findOneBy(['username' => $username]);

        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        $p = new Participant();
        $p->setUser($user);
        $p->setVorname($user->getName()->getFirstname());
        $p->setNachname($user->getName()->getLastname());
        $p->setGeschlecht('m'); 
        $p->setGeburtsdatum(new \DateTime('2010-01-01')); 

        $em->persist($p);
        $em->flush();

        $this->addFlash('success', 'Hinzugefügt.');
        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }
}
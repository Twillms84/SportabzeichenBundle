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
        // 1. Paginierung: Seite aus URL lesen, Standard = 1
        $page = $request->query->getInt('page', 1);
        $limit = 50; // Sicherheitslimit für den Speicher
        if ($page < 1) $page = 1;

        // 2. Gesamtanzahl zählen (effizient)
        $totalCount = $repo->createQueryBuilder('p')
            ->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = (int) ceil($totalCount / $limit);
        if ($maxPages < 1) $maxPages = 1;

        // 3. Nur die Daten für die aktuelle Seite holen (als Array!)
        $participants = $repo->createQueryBuilder('p')
            ->select('p.id, p.vorname, p.nachname, p.geburtsdatum, p.geschlecht, p.klasse')
            ->orderBy('p.nachname', 'ASC')
            ->addOrderBy('p.vorname', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab' => 'participants_manage',
            'currentPage' => $page,
            'maxPages' => $maxPages,
            'totalCount' => $totalCount,
        ]);
    }

    /**
     * @Route("/{id}/update", name="update", methods={"POST"})
     */
    public function update(Request $request, int $id, ParticipantRepository $repo, EntityManagerInterface $em): Response
    {
        $participant = $repo->find($id);
        if (!$participant) {
            throw $this->createNotFoundException('Teilnehmer nicht gefunden.');
        }

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {
                // Datum ungültig, ignorieren oder Fehler werfen
            }
        }
        
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    /**
     * @Route("/missing", name="missing")
     */
    public function missing(Request $request, ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        $searchTerm = $request->query->get('q');
        $missingUsers = [];
        $limitReached = false;

        // WICHTIG: Suche nur ausführen, wenn Suchbegriff existiert (> 2 Zeichen)
        // Verhindert das Laden aller User (Speicherschutz)
        if ($searchTerm && strlen($searchTerm) > 2) {
            
            // 1. IDs der existierenden Teilnehmer holen
            $existingIds = $pRepo->createQueryBuilder('p')
                ->select('IDENTITY(p.user)')
                ->where('p.user IS NOT NULL')
                ->getQuery()
                ->getScalarResult();
            
            $excludeIds = array_column($existingIds, 1);

            // 2. IServ User suchen, die KEINE Teilnehmer sind
            $qb = $uRepo->createQueryBuilder('u')
                ->select('u.username, u.firstname, u.lastname')
                ->where('u.act = true') // Nur aktive Accounts
                ->andWhere('u.username LIKE :s OR u.firstname LIKE :s OR u.lastname LIKE :s')
                ->setParameter('s', '%' . $searchTerm . '%')
                ->orderBy('u.lastname', 'ASC')
                ->setMaxResults(50);

            if (!empty($excludeIds)) {
                $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
            }

            $missingUsers = $qb->getQuery()->getArrayResult();
            $limitReached = count($missingUsers) >= 50;
        }

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'searchTerm' => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab' => 'participants_manage'
        ]);
    }

    /**
     * @Route("/add/{username}", name="add")
     */
    public function add(string $username, UserRepository $uRepo, EntityManagerInterface $em): Response
    {
        $user = $uRepo->findOneBy(['username' => $username]);
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // Check ob schon existiert (doppelte Sicherheit)
        $exists = $em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($exists) {
            $this->addFlash('warning', 'Benutzer ist bereits Teilnehmer.');
        } else {
            $participant = new Participant();
            $participant->setUser($user);
            $participant->setVorname($user->getFirstname());
            $participant->setNachname($user->getLastname());
            // Klasse/Gruppenlogik hier optional einfügen, falls gewünscht
            
            $em->persist($participant);
            $em->flush();
            
            $this->addFlash('success', $user->getName() . ' wurde hinzugefügt.');
        }

        return $this->redirectToRoute('sportabzeichen_admin_participants_missing', ['q' => $user->getLastname()]);
    }
}
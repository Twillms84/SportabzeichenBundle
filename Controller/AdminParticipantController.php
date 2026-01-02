<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Domain\User\User;
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
    public function index(ParticipantRepository $repo): Response
    {
        // NOTBREMSE: Wir laden keine Objekte, sondern nur ein Array.
        // Wir begrenzen hart auf 100 Einträge, um zu sehen, ob die Seite überhaupt lädt.
        $qb = $repo->createQueryBuilder('p')
            ->select('p.id, p.vorname, p.nachname, p.geburtsdatum, p.geschlecht, p.klasse') // Nur Felder, keine Objekte!
            ->orderBy('p.nachname', 'ASC')
            ->addOrderBy('p.vorname', 'ASC')
            ->setMaxResults(100); 

        $participants = $qb->getQuery()->getArrayResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab' => 'participants_manage',
            'limit_reached' => count($participants) >= 100
        ]);
    }

    /**
     * @Route("/{id}/update", name="update", methods={"POST"})
     */
    public function update(Request $request, int $id, ParticipantRepository $repo, EntityManagerInterface $em): Response
    {
        // Da wir im Index keine Objekte haben, laden wir hier das EINE Objekt zum Speichern nach
        $participant = $repo->find($id);

        if (!$participant) {
            throw $this->createNotFoundException('Teilnehmer nicht gefunden');
        }

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {}
        }
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();
        $this->addFlash('success', 'Gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
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
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
     * Zeigt die Liste aller bereits registrierten Teilnehmer.
     * ACHTUNG: Auch hier begrenzen wir auf 500, um Speicherüberlauf zu verhindern.
     * * @Route("/", name="index")
     */
    public function index(ParticipantRepository $repo): Response
    {
        // Wir nutzen den QueryBuilder statt findBy, um Kontrolle über das Limit zu haben
        $participants = $repo->createQueryBuilder('p')
            ->orderBy('p.nachname', 'ASC')
            ->addOrderBy('p.vorname', 'ASC')
            ->setMaxResults(500) // Sicherheitslimit!
            ->getQuery()
            ->getResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab' => 'participants_manage',
            'limit_reached' => count($participants) >= 500
        ]);
    }

    /**
     * Speichert Änderungen.
     * @Route("/{id}/update", name="update", methods={"POST"})
     */
    public function update(Request $request, Participant $participant, EntityManagerInterface $em): Response
    {
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) { /* ignore */ }
        }
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();
        $this->addFlash('success', 'Gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    /**
     * Zeigt fehlende Nutzer an.
     * EXTREME PERFORMANCE OPTIMIERUNG: Lädt nur Arrays, keine Objekte!
     * * @Route("/missing", name="missing")
     */
    public function missing(Request $request, ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        // 1. IDs der existierenden Teilnehmer holen (Nur IDs, winzig klein)
        $existingIds = $pRepo->createQueryBuilder('p')
            ->select('IDENTITY(p.user)')
            ->where('p.user IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        
        $excludeIds = array_column($existingIds, 1);

        // 2. Suche nach Usern vorbereiten
        // WICHTIG: Wir selektieren nur Felder, nicht das Objekt "u"
        $qb = $uRepo->createQueryBuilder('u')
            ->select('u.username, u.firstname, u.lastname, u.id') // <--- Nur Text laden!
            ->where('u.act = true')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setMaxResults(100); // Strenges Limit für die Anzeige

        if (!empty($excludeIds)) {
            $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
        }

        // Suche
        $searchTerm = $request->query->get('q');
        if ($searchTerm) {
            $qb->andWhere('u.username LIKE :s OR u.firstname LIKE :s OR u.lastname LIKE :s')
               ->setParameter('s', '%' . $searchTerm . '%');
        }

        // WICHTIG: getArrayResult() statt getResult()
        // Das verhindert, dass Symfony versucht, tausende User-Objekte zu bauen.
        $missingUsers = $qb->getQuery()->getArrayResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers, // Das ist jetzt ein Array von Arrays, keine User-Objekte!
            'activeTab' => 'participants_manage',
            'limit_reached' => count($missingUsers) >= 100,
            'searchTerm' => $searchTerm,
        ]);
    }

    /**
     * @Route("/add/{username}", name="add")
     */
    public function add(string $username, UserRepository $userRepo, EntityManagerInterface $em): Response
    {
        // Wir laden den User erst hier, wenn wir ihn wirklich brauchen (und nur einen!)
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
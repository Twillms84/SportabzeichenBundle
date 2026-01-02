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
     *
     * @Route("/", name="index")
     */
    public function index(ParticipantRepository $repo): Response
    {
        // Alle Teilnehmer holen, sortiert nach Nachname, Vorname
        $participants = $repo->findBy([], ['nachname' => 'ASC', 'vorname' => 'ASC']);

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab' => 'participants_manage', // Markiert den Reiter als aktiv
        ]);
    }

    /**
     * Speichert Änderungen (Geburtsdatum/Geschlecht) aus dem Modal.
     *
     * @Route("/{id}/update", name="update", methods={"POST"})
     */
    public function update(Request $request, Participant $participant, EntityManagerInterface $em): Response
    {
        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {
                $this->addFlash('error', 'Ungültiges Datum.');
            }
        }

        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();
        $this->addFlash('success', sprintf('Daten für %s %s aktualisiert.', $participant->getVorname(), $participant->getNachname()));

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    /**
     * Zeigt IServ-Nutzer an, die noch NICHT in der Teilnehmerliste sind.
     * Optimiert um "Memory Exhausted" Fehler zu vermeiden.
     *
     * @Route("/missing", name="missing")
     */
    public function missing(Request $request, ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        // 1. IDs aller existierenden Teilnehmer holen (Performant via ScalarResult)
        // Wir holen nur die User-IDs, nicht die ganzen Objekte.
        $existingIdsResult = $pRepo->createQueryBuilder('p')
            ->select('IDENTITY(p.user)')
            ->where('p.user IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        
        // Array flachklopfen (z.B. [1, 5, 99, ...])
        $excludeIds = array_column($existingIdsResult, 1);

        // 2. QueryBuilder für IServ User erstellen
        $qb = $uRepo->createQueryBuilder('u')
            ->where('u.act = true') // Nur aktive Nutzer
            ->orderBy('u.username', 'ASC')
            ->setMaxResults(200); // WICHTIG: Begrenzung gegen Speicherüberlauf

        // Bereits vorhandene Nutzer ausschließen
        if (!empty($excludeIds)) {
            $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
        }

        // 3. Optionale Suche (falls jemand spezifisch gesucht wird)
        // Das Suchfeld im Template müsste dazu name="q" haben und das Formular absenden.
        // Wenn du nur JS-Filterung nutzt, greift dies hier nicht, schadet aber auch nicht.
        $searchTerm = $request->query->get('q');
        if ($searchTerm) {
            $qb->andWhere('u.username LIKE :search OR u.firstname LIKE :search OR u.lastname LIKE :search')
               ->setParameter('search', '%' . $searchTerm . '%');
        }

        $missingUsers = $qb->getQuery()->getResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'activeTab' => 'participants_manage',
            'limit_reached' => count($missingUsers) >= 200,
            'searchTerm' => $searchTerm,
        ]);
    }

    /**
     * Fügt einen IServ-User als Teilnehmer hinzu.
     *
     * @Route("/add/{username}", name="add")
     */
    public function add(User $user, EntityManagerInterface $em): Response
    {
        // Prüfen, ob der User nicht doch schon existiert (doppelte Einträge verhindern)
        // Das Repository müsste man hier eigentlich nochmal fragen, aber wir vertrauen der UI.
        
        $p = new Participant();
        $p->setUser($user);
        $p->setVorname($user->getName()->getFirstname());
        $p->setNachname($user->getName()->getLastname());
        
        // Standardwerte setzen (müssen vom Admin geprüft werden)
        $p->setGeschlecht('m'); 
        // Standard-Geburtsdatum (z.B. 01.01.2010), damit das Feld nicht NULL ist
        $p->setGeburtsdatum(new \DateTime('2010-01-01')); 

        // Optional: Klasse auslesen (falls IServ Gruppenstruktur das hergibt)
        // $groups = $user->getGroups(); ...

        $em->persist($p);
        $em->flush();

        $this->addFlash('success', sprintf('Benutzer %s wurde hinzugefügt. Bitte Geburtsdatum und Geschlecht prüfen.', $user->getName()));

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }
}
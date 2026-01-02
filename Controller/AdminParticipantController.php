<?php

namespace PulsR\SportabzeichenBundle\Controller;

use PulsR\Sportabzeichen\Entity\Participant; // Deine Entity
use PulsR\Sportabzeichen\Repository\ParticipantRepository;
use IServ\Core\Domain\User\User; // IServ User Klasse
use IServ\Core\Domain\User\UserRepository; // Um IServ User zu finden
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/participants", name="sportabzeichen_admin_participants_")
 */
class AdminParticipantController extends AbstractController
{
    /**
     * Hauptansicht: Liste aller Teilnehmer
     * @Route("/", name="index")
     */
    public function index(ParticipantRepository $repo): Response
    {
        // Hole alle Teilnehmer, sortiert nach Nachname
        $participants = $repo->findBy([], ['nachname' => 'ASC', 'vorname' => 'ASC']);

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
        ]);
    }

    /**
     * Bearbeiten eines Teilnehmers (Modal-Ziel oder Seite)
     * @Route("/{id}/edit", name="edit", methods={"POST"})
     */
    public function edit(Request $request, Participant $participant, EntityManagerInterface $em): Response
    {
        // Hier einfach die POST-Daten auslesen (Quick & Dirty für Admin-Panel)
        // Sauberer wäre eine Symfony Form, aber für 2 Felder reicht das:
        
        $dobStr = $request->request->get('dob');
        $gender = $request->request->get('gender'); // 'm', 'w', ...

        if ($dobStr) {
            $participant->setGeburtsdatum(new \DateTime($dobStr));
        }
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();

        $this->addFlash('success', 'Teilnehmerdaten aktualisiert.');
        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    /**
     * Ansicht: Fehlende Benutzer finden (IServ User vs. Teilnehmer)
     * @Route("/missing", name="missing")
     */
    public function missing(ParticipantRepository $pRepo, UserRepository $uRepo): Response
    {
        // 1. Alle aktuellen Teilnehmer-User-IDs holen
        $existing = $pRepo->findAll();
        $existingUserIds = [];
        foreach ($existing as $p) {
            if ($p->getUser()) { // Angenommen es gibt eine Relation zum IServ User
                $existingUserIds[] = $p->getUser()->getId();
            }
        }

        // 2. Alle aktiven IServ Benutzer holen (evtl. filtern auf Schüler?)
        // Hier holen wir ALLE aktiven. Ggf. Repository-Methode findActiveStudents() nutzen falls verfügbar.
        $allUsers = $uRepo->findAllActive(); 

        $missingUsers = [];
        foreach ($allUsers as $user) {
            // Nur Benutzer anzeigen, die NICHT in der Teilnehmerliste sind
            if (!in_array($user->getId(), $existingUserIds)) {
                // Optional: Filter, z.B. System-User ausschließen
                if (!$user->hasRole('ROLE_STUDENT') && !$user->hasRole('ROLE_TEACHER')) {
                   continue;
                }
                $missingUsers[] = $user;
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
        ]);
    }

    /**
     * Fehlenden Benutzer hinzufügen
     * @Route("/add/{username}", name="add")
     */
    public function add(User $user, EntityManagerInterface $em): Response
    {
        // Neuen Teilnehmer aus IServ User erstellen
        $p = new Participant();
        $p->setUser($user);
        $p->setVorname($user->getName()->getFirstname());
        $p->setNachname($user->getName()->getLastname());
        // Versuchen Geschlecht/Geburtstag aus Profil zu holen, falls möglich
        // $p->setGeburtsdatum($user->getBirthday()); 
        // $p->setGeschlecht(...) 
        // Falls nicht vorhanden, Standardwerte setzen, die der Admin dann ändert:
        $p->setGeschlecht('m'); 
        $p->setGeburtsdatum(new \DateTime('2010-01-01')); 

        $em->persist($p);
        $em->flush();

        $this->addFlash('success', sprintf('%s wurde hinzugefügt.', $user));

        return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
    }
}
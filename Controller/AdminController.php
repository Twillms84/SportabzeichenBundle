<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Participant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AdminController extends AbstractPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    #[Route('/participants', name: 'participants_index')]
    public function participantsIndex(Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $repo = $this->em->getRepository(Participant::class);

        $page = max(1, $request->query->getInt('page', 1));
        $limit = 50;

        // Gesamtanzahl für Pagination
        $totalCount = (int) $repo->createQueryBuilder('p')
            ->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // Teilnehmer laden inkl. User für Sortierung und Performance
        $participants = $repo->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u')
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $this->render('@PulsRSportabzeichen/admin/participants/index.html.twig', [
            'participants' => $participants,
            'activeTab'    => 'participants_manage',
            'currentPage'  => $page,
            'maxPages'     => $maxPages,
            'totalCount'   => $totalCount,
        ]);
    }

    #[Route('/participants/missing', name: 'participants_missing')]
    public function participantsMissing(Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        
        $participantRepo = $this->em->getRepository(Participant::class);
        $userRepo = $this->em->getRepository(User::class);

        $searchTerm = trim((string)$request->query->get('q'));
        $missingUsers = [];
        $limitReached = false;

        // Suche erst starten, wenn mind. 3 Zeichen eingegeben wurden
        if (strlen($searchTerm) >= 3) {
            // 1. IDs aller User holen, die schon Participant sind
            // Wir nutzen IDENTITY(), um nur die IDs zu laden, nicht die ganzen Objekte
            $existingIdsResult = $participantRepo->createQueryBuilder('p')
                ->select('IDENTITY(p.user)')
                ->where('p.user IS NOT NULL')
                ->getQuery()
                ->getScalarResult();
            
            // Flache Liste der IDs erstellen
            $excludeIds = array_column($existingIdsResult, 1);

            // 2. User suchen, die NICHT in dieser Liste sind
            $qb = $userRepo->createQueryBuilder('u')
                ->select('u') // Hier laden wir die ganzen User-Objekte für die Anzeige
                ->where('u.act = true') // Nur aktive Nutzer
                // Suche in Vorname, Nachname oder Username
                ->andWhere('u.username LIKE :s OR u.firstname LIKE :s OR u.lastname LIKE :s')
                ->setParameter('s', '%' . $searchTerm . '%')
                ->orderBy('u.lastname', 'ASC')
                ->addOrderBy('u.firstname', 'ASC')
                ->setMaxResults(51); // Eins mehr laden, um "limitReached" zu prüfen

            if (!empty($excludeIds)) {
                $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
            }

            $results = $qb->getQuery()->getResult();
            
            if (count($results) > 50) {
                $limitReached = true;
                array_pop($results); // Den 51. Eintrag entfernen
            }
            
            $missingUsers = $results;
        }

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(string $username): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $userRepo = $this->em->getRepository(User::class);
        $participantRepo = $this->em->getRepository(Participant::class);

        $user = $userRepo->findOneBy(['username' => $username]);
        
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // Doppelprüfung, falls jemand F5 drückt
        $exists = $participantRepo->findOneBy(['user' => $user]);
        if ($exists) {
            $this->addFlash('warning', sprintf('Benutzer "%s" ist bereits Teilnehmer.', $user->getName()));
        } else {
            $participant = new Participant();
            $participant->setUser($user);
            // Wir speichern Namen als Snapshot, falls der User später gelöscht wird
            // (vorausgesetzt deine Entity hat diese Felder, sonst diese Zeilen löschen)
            $participant->setVorname($user->getFirstname());
            $participant->setNachname($user->getLastname());
            
            $this->em->persist($participant);
            $this->em->flush();
            
            $this->addFlash('success', sprintf('%s wurde erfolgreich hinzugefügt.', $user->getName()));
        }

        // Weiterleitung zurück zur Suche, damit man weitermachen kann
        return $this->redirectToRoute('sportabzeichen_admin_participants_missing', ['q' => $user->getLastname()]);
    }

    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, int $id): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $participant = $this->em->getRepository(Participant::class)->find($id);
        
        if (!$participant) {
            throw $this->createNotFoundException('Teilnehmer nicht gefunden.');
        }

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {
                // Ungültiges Datum ignorieren oder Flash-Message setzen
            }
        }
        
        if ($gender) {
            // Validierung: Nur erlaubte Werte
            if (in_array($gender, ['MALE', 'FEMALE', 'DIVERSE'])) {
                 $participant->setGeschlecht($gender);
            }
        }

        $this->em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    // ... Import und Requirements Methoden bleiben einfach Render-Aufrufe ...
    #[Route('/import', name: 'import_index')]
    public function importIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 'error' => null, 'imported' => 0, 'skipped' => 0
        ]);
    }
}
<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Participant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
    public function participantsMissing(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        
        $participantRepo = $em->getRepository(Participant::class);
        $userRepo = $em->getRepository(User::class);

        $searchTerm = trim((string)$request->query->get('q'));
        $missingUsers = [];
        $limitReached = false;

        // 1. IDs der existierenden Teilnehmer holen
        $existingIdsResult = $participantRepo->createQueryBuilder('p')
            ->select('IDENTITY(p.user)')
            ->where('p.user IS NOT NULL')
            ->getQuery()
            ->getScalarResult();
        
        $excludeIds = array_column($existingIdsResult, 1);

        // 2. QueryBuilder für User
        $qb = $userRepo->createQueryBuilder('u')
            ->select('u')
            // FIX: Wir prüfen auf "nicht gelöscht" (deleted ist NULL), statt auf "active"
            ->where('u.deleted IS NULL') 
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setMaxResults(51);

        // Bereits vorhandene Teilnehmer ausschließen
        if (!empty($excludeIds)) {
            $qb->andWhere($qb->expr()->notIn('u.id', $excludeIds));
        }

        // Suche: Wir suchen in Name, Vorname, Benutzername (act) ODER Import-ID
        if (strlen($searchTerm) > 0) {
            $qb->andWhere('u.username LIKE :s OR u.firstname LIKE :s OR u.lastname LIKE :s OR u.importId LIKE :s')
               ->setParameter('s', '%' . $searchTerm . '%');
        }

        $results = $qb->getQuery()->getResult();
        
        if (count($results) > 50) {
            $limitReached = true;
            array_pop($results);
        }
        $missingUsers = $results;

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $missingUsers,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(string $username, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $conn = $em->getConnection();

        // SCHRITT 1: Die ECHTE NUMERISCHE ID herausfinden (Raw SQL)
        $sqlUserId = 'SELECT id FROM users WHERE act = :name';
        $realId = $conn->fetchOne($sqlUserId, ['name' => $username]);

        // Fallback: Import-ID
        if (!$realId) {
             $sqlImport = 'SELECT id FROM users WHERE import_id = :name';
             $realId = $conn->fetchOne($sqlImport, ['name' => $username]);
        }

        if (!$realId) {
            $this->addFlash('error', 'Benutzer "' . $username . '" nicht in der Datenbank gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // SCHRITT 2: Prüfen, ob Teilnehmer existiert (Raw SQL)
        $sqlCheck = 'SELECT 1 FROM sportabzeichen_participants WHERE user_id = :uid LIMIT 1';
        $exists = $conn->fetchOne($sqlCheck, ['uid' => $realId]);

        if ($exists) {
             $this->addFlash('warning', 'Benutzer ' . $username . ' ist bereits Teilnehmer.');
             return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // SCHRITT 3: Speichern per Referenz (Bypassing Loading Issues)
        try {
            // Wir holen uns eine Referenz auf den User (kein DB-Select nötig)
            // Stelle sicher, dass oben "use IServ\CoreBundle\Entity\User;" steht!
            $userRef = $em->getReference(User::class, $realId);

            $participant = new Participant();
            $participant->setUser($userRef);
            
            $em->persist($participant);
            $em->flush();

            $this->addFlash('success', 'Teilnehmer hinzugefügt: ' . $username);

        } catch (\Exception $e) {
            // Sollte eigentlich nicht passieren, da wir alles vorher geprüft haben
            $this->addFlash('error', 'Fehler beim Speichern: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
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
    #[Route('/upload', name: 'upload_participants')]
    public function importIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 'error' => null, 'imported' => 0, 'skipped' => 0
        ]);
    }
}
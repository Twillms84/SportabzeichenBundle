<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Domain\User\UserRepository;
use PulsR\SportabzeichenBundle\Entity\Participant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AdminController extends AbstractPageController
{
    /**
     * DASHBOARD: Startseite
     */
    #[Route('/', name: 'dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/dashboard.html.twig', [
            'activeTab' => 'dashboard',
        ]);
    }

    /**
     * TEILNEHMER: Liste anzeigen
     */
    #[Route('/participants', name: 'participants_index')]
    public function participantsIndex(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // Wir holen das Repo manuell via EntityManager, um DI-Fehler zu vermeiden
        $repo = $em->getRepository(Participant::class);

        $page = $request->query->getInt('page', 1);
        $limit = 50; 
        if ($page < 1) $page = 1;

        // Gesamtanzahl
        $totalCount = $repo->createQueryBuilder('p')
            ->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = (int) ceil($totalCount / $limit);
        if ($maxPages < 1) $maxPages = 1;

        // Daten als Array laden
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
            'activeTab'    => 'participants_manage',
            'currentPage'  => $page,
            'maxPages'     => $maxPages,
            'totalCount'   => $totalCount,
        ]);
    }

    /**
     * TEILNEHMER: Nacherfassen (Suche)
     */
    #[Route('/participants/missing', name: 'participants_missing')]
    public function participantsMissing(Request $request, EntityManagerInterface $em, UserRepository $uRepo): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        
        $repo = $em->getRepository(Participant::class);

        $searchTerm = $request->query->get('q');
        $missingUsers = [];
        $limitReached = false;

        if ($searchTerm && strlen($searchTerm) > 2) {
            
            // Bereits vorhandene IDs ausschließen
            $existingIds = $repo->createQueryBuilder('p')
                ->select('IDENTITY(p.user)')
                ->where('p.user IS NOT NULL')
                ->getQuery()
                ->getScalarResult();
            
            $excludeIds = array_column($existingIds, 1);

            $qb = $uRepo->createQueryBuilder('u')
                ->select('u.username, u.firstname, u.lastname')
                ->where('u.act = true')
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
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    /**
     * TEILNEHMER: Einen User hinzufügen
     */
    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(string $username, UserRepository $uRepo, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $user = $uRepo->findOneBy(['username' => $username]);
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        $exists = $em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($exists) {
            $this->addFlash('warning', 'Benutzer ist bereits Teilnehmer.');
        } else {
            $participant = new Participant();
            $participant->setUser($user);
            $participant->setVorname($user->getFirstname());
            $participant->setNachname($user->getLastname());
            
            // Klasse/Gruppen auslesen (Optional, falls möglich)
            // $participant->setKlasse(...);

            $em->persist($participant);
            $em->flush();
            
            $this->addFlash('success', $user->getName() . ' wurde hinzugefügt.');
        }

        return $this->redirectToRoute('sportabzeichen_admin_participants_missing', ['q' => $user->getLastname()]);
    }

    /**
     * TEILNEHMER: Update (per Modal / POST)
     */
    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, int $id, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $participant = $em->getRepository(Participant::class)->find($id);
        if (!$participant) {
            throw $this->createNotFoundException('Teilnehmer nicht gefunden.');
        }

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) { }
        }
        
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    /**
     * IMPORT: CSV Hochladen (Fix: message Variable hinzugefügt)
     */
    #[Route('/import', name: 'import_index')]
    public function importIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, // <--- WICHTIG: Leere Variable übergeben
        ]);
    }

    /**
     * ANFORDERUNGEN: DOSB Tabelle (Fix: message Variable hinzugefügt)
     */
    #[Route('/requirements', name: 'requirements_index')]
    public function requirementsIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload.html.twig', [
            'activeTab' => 'requirements',
            'message' => null, // <--- WICHTIG: Leere Variable übergeben
        ]);
    }
}
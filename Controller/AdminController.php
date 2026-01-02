<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\User; // <--- Wir nutzen jetzt direkt die Entity
use PulsR\SportabzeichenBundle\Entity\Participant;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AdminController extends AbstractPageController
{
    private EntityManagerInterface $em;

    // Nur noch der EntityManager wird injected - das klappt immer.
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
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

        $page = $request->query->getInt('page', 1);
        $limit = 50; 
        if ($page < 1) $page = 1;

        // Gesamtanzahl zählen
        $totalCount = $repo->createQueryBuilder('p')
            ->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = (int) ceil($totalCount / $limit);
        if ($maxPages < 1) $maxPages = 1;

        // Objekte laden!
        $participants = $repo->createQueryBuilder('p')
            ->leftJoin('p.user', 'u') // Join für Sortierung nötig
            ->addSelect('u')          // User gleich mitladen (Performance!)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult(); // <--- WICHTIG: getResult() statt getArrayResult()

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
        $userRepo = $this->em->getRepository(User::class); // <--- Hier holen wir es sicher

        $searchTerm = $request->query->get('q');
        $missingUsers = [];
        $limitReached = false;

        if ($searchTerm && strlen($searchTerm) > 2) {
            // Bereits vorhandene User-IDs holen
            $existingIds = $participantRepo->createQueryBuilder('p')
                ->select('IDENTITY(p.user)')
                ->where('p.user IS NOT NULL')
                ->getQuery()
                ->getScalarResult();
            
            $excludeIds = array_column($existingIds, 1);

            $qb = $userRepo->createQueryBuilder('u')
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

    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(string $username): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // Sicherer Zugriff auf User Repo
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
        
        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        $exists = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($exists) {
            $this->addFlash('warning', 'Benutzer ist bereits Teilnehmer.');
        } else {
            $participant = new Participant();
            $participant->setUser($user);
            $participant->setVorname($user->getFirstname());
            $participant->setNachname($user->getLastname());
            
            $this->em->persist($participant);
            $this->em->flush();
            
            $this->addFlash('success', $user->getName() . ' wurde hinzugefügt.');
        }

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
            } catch (\Exception $e) { }
        }
        
        if ($gender) {
            $participant->setGeschlecht($gender);
        }

        $this->em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    #[Route('/import', name: 'import_index')]
    public function importIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 
            'error'   => null,
        ]);
    }

    #[Route('/requirements', name: 'requirements_index')]
    public function requirementsIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload.html.twig', [
            'activeTab' => 'requirements',
            'message' => null, 
            'error'   => null,
        ]);
    }
}
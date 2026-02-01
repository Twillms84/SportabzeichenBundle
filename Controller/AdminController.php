<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Participant;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
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
        $searchTerm = trim((string)$request->query->get('q'));

        $qb = $repo->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')
            ->addSelect('u'); // Eager Loading des Users verhindert N+1 Probleme im Template

        if ($searchTerm !== '') {
            $qb->andWhere('LOWER(u.lastname) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.username) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($searchTerm) . '%');
        }

        // Pagination Count
        $countQb = clone $qb;
        $totalCount = (int) $countQb->select('count(p.id)')->getQuery()->getSingleScalarResult();
        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // Results
        $participants = $qb->orderBy('u.lastname', 'ASC')
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
            'searchTerm'   => $searchTerm,
        ]);
    }

    #[Route('/participants/missing', name: 'participants_missing')]
    public function participantsMissing(Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $userRepo = $this->em->getRepository(User::class);
        $searchTerm = trim((string)$request->query->get('q'));
        
        // --- 1. Subquery: Die "Fertigen" ---
        $completedSubQuery = $this->em->createQueryBuilder()
            ->select('IDENTITY(p.user)')
            ->from(Participant::class, 'p')
            ->where('p.user IS NOT NULL') // <--- WICHTIG: Verhindert SQL-Fehler bei verwaisten Einträgen
            ->andWhere('p.birthdate IS NOT NULL') 
            ->andWhere("p.gender IS NOT NULL AND p.gender <> ''")
            ->getDQL();

        // --- 2. Hauptquery ---
        $qb = $userRepo->createQueryBuilder('u')
            ->where('u.deleted IS NULL') // Nur aktive User
            ->andWhere($userRepo->createQueryBuilder('u')->expr()->notIn('u.id', $completedSubQuery));
        
        // Suchelogik verfeinert
        if ($searchTerm !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.username)', ':s'),
                    $qb->expr()->like('LOWER(u.firstname)', ':s'),
                    $qb->expr()->like('LOWER(u.lastname)', ':s'),
                    $qb->expr()->like('LOWER(u.importId)', ':s') // Falls ImportID existiert
                )
            )
            ->setParameter('s', '%' . mb_strtolower($searchTerm) . '%');
        }

        // Sortierung: Zuerst Nachname, dann Vorname, dann Username (für User ohne Namen wichtig!)
        $qb->orderBy('u.lastname', 'ASC')
           ->addOrderBy('u.firstname', 'ASC')
           ->addOrderBy('u.username', 'ASC') // Fallback für Testuser ohne Realnamen
           ->setMaxResults(51);

        $results = $qb->getQuery()->getResult();
        
        $limitReached = false;
        if (count($results) > 50) {
            $limitReached = true;
            array_pop($results);
        }

        return $this->render('@PulsRSportabzeichen/admin/participants/missing.html.twig', [
            'missingUsers' => $results,
            'searchTerm'   => $searchTerm,
            'limitReached' => $limitReached,
            'activeTab'    => 'participants_manage'
        ]);
    }

    #[Route('/participants/add/{username}', name: 'participants_add')]
    public function participantsAdd(Request $request, string $username): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // User via Entity laden statt Raw SQL
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]); // 'act' heißt in der Entity meist 'username'

        if (!$user) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // Check auf Existenz (über Entity Repository)
        $existing = $this->em->getRepository(Participant::class)->findOneBy(['user' => $user]);
        if ($existing) {
            $this->addFlash('warning', 'Teilnehmer existiert bereits.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // Neue Entity erstellen
        $participant = new Participant();
        $participant->setUser($user);
        // Fallback Import ID Logik (falls das im Setter der Entity nicht passiert)
        $importId = $user->getImportId() ?: 'MANUAL_' . $user->getUsername();
        $participant->setImportId($importId);
        // Defaults
        $participant->setGeburtsdatum(new \DateTime('2010-01-01'));
        $participant->setGeschlecht('MALE');

        // Formular direkt an die Entity binden
        $form = $this->createParticipantForm($participant, 'Teilnehmer hinzufügen');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->em->persist($participant);
                $this->em->flush();

                $this->addFlash('success', 'Gespeichert: ' . $user->getFirstname());
                return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/participants/add.html.twig', [
            'form' => $form->createView(),
            'user' => $user // Entity an View übergeben (Twig: user.firstname statt user['firstname'])
        ]);
    }

    #[Route('/participants/{id}/update', name: 'participants_update', methods: ['POST'])]
    public function participantsUpdate(Request $request, Participant $participant): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $dob = $request->request->get('dob');
        $gender = $request->request->get('gender');

        // Datum setzen
        if ($dob) {
            try {
                $participant->setGeburtsdatum(new \DateTime($dob));
            } catch (\Exception $e) {
                // Ignore invalid date
            }
        }

        // Geschlecht setzen: Wir erwarten jetzt direkt die DB-Werte MALE oder FEMALE
        if ($gender && in_array($gender, ['MALE', 'FEMALE'], true)) {
             $participant->setGeschlecht($gender);
        }

        $this->em->flush();
        $this->addFlash('success', 'Daten gespeichert.');

        return $this->redirectToRoute('sportabzeichen_admin_participants_index');
    }

    #[Route('/participants/edit/{id}', name: 'participants_edit')]
    public function participantsEdit(Request $request, Participant $participant): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // Formular an die existierende Entity binden
        $form = $this->createParticipantForm($participant, 'Änderungen speichern');
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                // persist ist bei Updates nicht zwingend nötig, schadet aber nicht
                $this->em->flush(); 

                $this->addFlash('success', 'Daten aktualisiert.');
                return $this->redirectToRoute('sportabzeichen_admin_participants_index');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/add.html.twig', [
            'form' => $form->createView(),
            'user' => $participant->getUser() // Entity statt Array
        ]);
    }

    #[Route('/upload', name: 'upload_participants')]
    public function importIndex(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message' => null, 'error' => null, 'imported' => 0, 'skipped' => 0
        ]);
    }

    /**
     * Hilfsmethode, um das Formular nicht doppelt zu definieren (DRY)
     */
    private function createParticipantForm(Participant $participant, string $btnLabel): \Symfony\Component\Form\FormInterface
    {
        return $this->createFormBuilder($participant)
            ->add('geburtsdatum', DateType::class, [ // Name muss exakt Property in Entity entsprechen
                'label' => 'Geburtsdatum',
                'widget' => 'single_text',
                'required' => false,
            ])
            ->add('geschlecht', ChoiceType::class, [
                'label' => 'Geschlecht',
                'choices' => [
                    'Männlich' => 'MALE',
                    'Weiblich' => 'FEMALE',
                    'Divers'   => 'DIVERSE',
                ],
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('save', SubmitType::class, [
                'label' => $btnLabel,
                'attr' => ['class' => 'btn btn-success']
            ])
            ->getForm();
    }
}
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
        $searchTerm = trim((string)$request->query->get('q'));

        // Basis-QueryBuilder erstellen (wird für Count UND Result genutzt)
        $qb = $repo->createQueryBuilder('p')
            ->leftJoin('p.user', 'u'); // Join für Namenssuche

        // Suchfilter anwenden, falls vorhanden
        if ($searchTerm) {
            $qb->andWhere('LOWER(u.lastname) LIKE :q OR LOWER(u.firstname) LIKE :q OR LOWER(u.username) LIKE :q')
               ->setParameter('q', '%' . strtolower($searchTerm) . '%');
        }

        // 1. Zählen (für Pagination) auf Basis des gefilterten QueryBuilders
        // Wir klonen den QB, damit das Original für die Ergebnissuche erhalten bleibt
        $countQb = clone $qb;
        $totalCount = (int) $countQb->select('count(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $maxPages = max(1, (int) ceil($totalCount / $limit));

        // 2. Ergebnisse laden
        $participants = $qb->addSelect('u') // User-Objekt mitladen
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
            'searchTerm'   => $searchTerm, // Wichtig für das Suchfeld im Template
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
    public function participantsAdd(Request $request, string $username, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        $conn = $em->getConnection();

        // 1. User-Daten per SQL holen (Sicherheits-Check wie vorher)
        $sqlUser = 'SELECT id, firstname, lastname, importid FROM users WHERE act = :name';
        $userData = $conn->fetchAssociative($sqlUser, ['name' => $username]);

        if (!$userData) {
            $this->addFlash('error', 'Benutzer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_missing');
        }

        // Import-ID Fallback (für Timo & Co)
        $importId = $userData['importid'] ?: 'MANUAL_' . $username;
        $realId = $userData['id'];

        // 2. Das Formular erstellen
        $form = $this->createFormBuilder()
            ->add('birthdate', \Symfony\Component\Form\Extension\Core\Type\DateType::class, [
                'label' => 'Geburtsdatum',
                'widget' => 'single_text', // HTML5 Datepicker
                'required' => false,
                'data' => new \DateTime('2010-01-01'), // Standardwert
            ])
            ->add('gender', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Geschlecht',
                'choices' => [
                    'Männlich' => 'm',
                    'Weiblich' => 'w',
                    'Divers' => 'd',
                ],
                'expanded' => true, // Radio-Buttons
                'multiple' => false,
                'data' => 'm',
            ])
            ->add('save', \Symfony\Component\Form\Extension\Core\Type\SubmitType::class, [
                'label' => 'Teilnehmer hinzufügen',
                'attr' => ['class' => 'btn btn-success']
            ])
            ->getForm();

        $form->handleRequest($request);

        // 3. Wenn Formular abgeschickt wurde -> Speichern
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            
            try {
                // Check auf Duplikate
                $exists = $conn->fetchOne('SELECT 1 FROM sportabzeichen_participants WHERE user_id = :uid', ['uid' => $realId]);
                
                if ($exists) {
                    $this->addFlash('warning', 'Bereits vorhanden!');
                } else {
                    // SQL INSERT mit den neuen Feldern
                    $conn->insert('sportabzeichen_participants', [
                        'user_id' => $realId,
                        'import_id' => $importId,
                        'geburtsdatum' => $data['birthdate'] ? $data['birthdate']->format('Y-m-d') : null,
                        'geschlecht' => $data['gender']
                    ]);
                    $this->addFlash('success', 'Gespeichert: ' . $userData['firstname']);
                }
                return $this->redirectToRoute('sportabzeichen_admin_participants_missing');

            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        // 4. Formular anzeigen
        return $this->render('@PulsRSportabzeichen/admin/participants/add.html.twig', [
            'form' => $form->createView(),
            'user' => $userData
        ]);
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

    #[Route('/participants/edit/{id}', name: 'participants_edit')]
    public function participantsEdit(int $id, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');
        $conn = $em->getConnection();

        // 1. Aktuelle Daten laden (Raw SQL)
        // Wir brauchen auch den Namen aus der Users Tabelle für die Anzeige
        $sql = '
            SELECT p.*, u.firstname, u.lastname 
            FROM sportabzeichen_participants p
            JOIN users u ON p.user_id = u.id
            WHERE p.id = :pid
        ';
        $participant = $conn->fetchAssociative($sql, ['pid' => $id]);

        if (!$participant) {
            $this->addFlash('error', 'Teilnehmer nicht gefunden.');
            return $this->redirectToRoute('sportabzeichen_admin_participants_index');
        }

        // Daten für Formular vorbereiten
        $currentDate = $participant['geburtsdatum'] ? new \DateTime($participant['geburtsdatum']) : null;

        // 2. Formular bauen
        $form = $this->createFormBuilder()
            ->add('birthdate', \Symfony\Component\Form\Extension\Core\Type\DateType::class, [
                'label' => 'Geburtsdatum',
                'widget' => 'single_text',
                'required' => false,
                'data' => $currentDate,
            ])
            ->add('gender', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Geschlecht',
                'choices' => ['Männlich' => 'm', 'Weiblich' => 'w', 'Divers' => 'd'],
                'expanded' => true,
                'data' => $participant['geschlecht'],
            ])
            ->add('save', \Symfony\Component\Form\Extension\Core\Type\SubmitType::class, [
                'label' => 'Änderungen speichern',
                'attr' => ['class' => 'btn btn-primary']
            ])
            ->getForm();

        $form->handleRequest($request);

        // 3. Update durchführen
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            try {
                $conn->update('sportabzeichen_participants', [
                    'geburtsdatum' => $data['birthdate'] ? $data['birthdate']->format('Y-m-d') : null,
                    'geschlecht' => $data['gender']
                ], ['id' => $id]); // WHERE id = $id

                $this->addFlash('success', 'Daten aktualisiert.');
                return $this->redirectToRoute('sportabzeichen_admin_participants_index');

            } catch (\Exception $e) {
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/add.html.twig', [
            'form' => $form->createView(),
            'user' => ['firstname' => $participant['firstname'], 'lastname' => $participant['lastname']]
        ]);
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
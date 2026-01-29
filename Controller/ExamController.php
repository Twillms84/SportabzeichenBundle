<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger
    ) {
    }

    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // Alle Prüfungen laden (neueste zuerst)
        $exams = $examRepository->findBy(
            [],
            ['year' => 'DESC', 'date' => 'DESC']
        );

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams' => $exams,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // 1. Daten für Dropdowns laden
        // Klassen laden (IServ Standard: auxinfo enthält die Klasse bei Schülern)
        $classes = $this->conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // Gruppen laden
        $groupRepo = $this->em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            if ($acc = $g->getAccount()) {
                $groupsForDropdown[$acc] = $g->getName();
            }
        }

        if ($request->isMethod('POST')) {
            $this->conn->beginTransaction();
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;

                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;

                $selectedClasses = $request->request->all()['classes'] ?? [];
                $selectedGroups  = $request->request->all()['groups'] ?? [];

                // Exam Entity erstellen
                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser()?->getUsername());

                $this->em->persist($exam);
                $this->em->flush(); // ID generieren

                $examId = $exam->getId();
                if (!$examId) {
                    throw new \RuntimeException("Prüfungs-ID konnte nicht generiert werden.");
                }

                // A. Klassen importieren
                foreach ((array)$selectedClasses as $singleClass) {
                    $this->importParticipantsFromClass($examId, $year, (string)$singleClass);
                }

                // B. Gruppen importieren
                foreach ((array)$selectedGroups as $groupAccount) {
                    $this->importParticipantsFromGroup($examId, $year, (string)$groupAccount);
                }

                $this->conn->commit();
                $this->addFlash('success', 'Prüfung erfolgreich angelegt.');
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $this->conn->rollBack();
                
                // Logging statt Crash-Report im Frontend
                $this->logger->error('Fehler beim Erstellen der Sportabzeichen-Prüfung', [
                    'exception' => $e,
                    'user' => $this->getUser()?->getUsername(),
                    'input_year' => $request->request->get('exam_year'),
                ]);

                $this->addFlash('error', 'Fehler beim Speichern: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes,
            'groups'  => $groupsForDropdown
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $this->conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('exam_name'));
            $year = (int)$request->request->get('exam_year');
            if ($year < 100) $year += 2000;
            $date = $request->request->get('exam_date') ?: null;

            $this->conn->update('sportabzeichen_exams', [
                'exam_name' => $name,
                'exam_year' => $year,
                'exam_date' => $date
            ], ['id' => $id]);

            $this->addFlash('success', 'Änderungen gespeichert.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        return $this->render('@PulsRSportabzeichen/exams/edit.html.twig', [
            'exam' => $exam
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        if (!$this->isCsrfTokenValid('delete' . $id, (string)$request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        $this->conn->beginTransaction();
        try {
            // 1. Ergebnisse löschen
            $this->conn->executeStatement("
                DELETE FROM sportabzeichen_exam_results 
                WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = :id)
            ", ['id' => $id]);

            // 2. Teilnehmer-Verknüpfungen löschen
            $this->conn->executeStatement("DELETE FROM sportabzeichen_exam_participants WHERE exam_id = :id", ['id' => $id]);

            // 3. Prüfung löschen
            $this->conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);

            $this->conn->commit();
            $this->addFlash('success', 'Prüfung und alle Ergebnisse gelöscht.');
        } catch (\Exception $e) {
            $this->conn->rollBack();
            $this->logger->error('Löschen fehlgeschlagen', ['id' => $id, 'error' => $e->getMessage()]);
            $this->addFlash('error', 'Fehler beim Löschen.');
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $this->conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        $mode = $request->query->get('mode', 'class'); 
        $filterValue = $request->query->get('filter_value');

        // Dropdowns laden
        $classes = $this->conn->fetchFirstColumn("SELECT DISTINCT auxinfo FROM users WHERE auxinfo IS NOT NULL AND auxinfo <> '' AND deleted IS NULL ORDER BY auxinfo");
        $groups = $this->conn->fetchAllAssociative("SELECT act, name FROM groups WHERE deleted IS NULL ORDER BY act ASC");

        // --- POST: User hinzufügen ---
        if ($request->isMethod('POST')) {
            $account = trim($request->request->get('account', ''));
            $gender  = $request->request->get('gender');
            $dobStr  = $request->request->get('dob');

            if ($account && $gender && $dobStr) {
                $userId = $this->conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                
                if ($userId) {
                    try {
                        $this->processParticipantByUserId((int)$id, (int)$exam['exam_year'], (int)$userId, $dobStr, $gender);
                        $this->addFlash('success', "$account hinzugefügt.");
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                    }
                }
            }
            return $this->redirectToRoute('sportabzeichen_exams_add_participant', ['id' => $id, 'mode' => $mode, 'filter_value' => $filterValue]);
        }

        // --- GET: Liste anzeigen ---
        $missingStudents = [];

        if ($filterValue) {
            // Basis Query
            $sql = "
                SELECT 
                    u.id, u.act, u.firstname, u.lastname,
                    sp.geburtsdatum, sp.geschlecht as sp_gender
                FROM users u
                LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            ";

            if ($mode === 'group') {
                // Hier nutzen wir die saubere IServ Logic (Primary Group + Memberships)
                $sql .= "
                    LEFT JOIN members m ON u.act = m.actuser
                    WHERE (
                        u.gid = (SELECT id FROM groups WHERE act = :val LIMIT 1) 
                        OR m.actgrp = :val
                    )
                ";
            } else {
                $sql .= " WHERE u.auxinfo = :val ";
            }

            $sql .= " AND u.deleted IS NULL ";

            // Bereits teilnehmende ausschließen
            $sql .= "
                AND NOT EXISTS (
                    SELECT 1 FROM sportabzeichen_exam_participants sep
                    JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                    WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
                )
                ORDER BY u.lastname, u.firstname
            ";

            $rows = $this->conn->fetchAllAssociative($sql, [
                'val' => $filterValue,
                'examId' => $id
            ]);

            foreach ($rows as $row) {
                $missingStudents[] = [
                    'account' => $row['act'],
                    'name'    => $row['firstname'] . ' ' . $row['lastname'],
                    'dob'     => $row['geburtsdatum'],
                    'gender'  => $row['sp_gender'] ?? 'MALE' // Default Fallback
                ];
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/add_participant.html.twig', [
            'exam' => $exam,
            'classes' => $classes,
            'groups' => $groups,
            'mode' => $mode,
            'filter_value' => $filterValue,
            'missing_students' => $missingStudents
        ]);
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPER METHODS
    // -------------------------------------------------------------------------

    private function importParticipantsFromClass(int $examId, int $examYear, string $class): void
    {
        // Spaltennamen dynamisch ermitteln (Kompatibilität für ältere/neuere DB Versionen)
        $column = 'import_id';
        try {
            // Test, ob import_id existiert (indirekt)
            $this->conn->executeQuery("SELECT import_id FROM users LIMIT 1");
        } catch (\Throwable) {
            $column = 'importid';
        }

        // IDs holen
        try {
            $importIds = $this->conn->fetchFirstColumn(
                "SELECT $column FROM users WHERE auxinfo = :cls AND $column IS NOT NULL AND $column <> '' AND deleted IS NULL",
                ['cls' => $class]
            );

            foreach ($importIds as $importId) {
                $this->importByImportId($examId, $examYear, (string)$importId);
            }
        } catch (\Throwable $e) {
            $this->logger->error("Fehler beim Klassenimport ($class): " . $e->getMessage());
        }
    }

    private function importParticipantsFromGroup(int $examId, int $examYear, string $groupAccount): void
    {
        // Alle User-IDs der Gruppe holen (Primärgruppe + Mitgliedschaften)
        $sql = "
            SELECT DISTINCT u.id 
            FROM users u
            LEFT JOIN members m ON u.act = m.actuser
            WHERE (
                u.gid = (SELECT id FROM groups WHERE act = :gname LIMIT 1) 
                OR 
                m.actgrp = :gname
            )
            AND u.deleted IS NULL
        ";

        $userIds = $this->conn->fetchFirstColumn($sql, ['gname' => $groupAccount]);

        foreach ($userIds as $userId) {
            if ($userId) {
                // Hier übergeben wir null für Datum/Geschlecht, damit es aus der DB geholt wird
                $this->processParticipantByUserId($examId, $examYear, (int)$userId, null, null);
            }
        }
    }

    /**
     * Kern-Logik: User -> Sportabzeichen Participant -> Exam Participant
     * Optional können dob/gender übergeben werden (z.B. aus Formular), sonst werden sie gesucht.
     */
    private function processParticipantByUserId(int $examId, int $examYear, int $userId, ?string $dob = null, ?string $gender = null): void
    {
        // 1. Wenn kein Geburtsdatum übergeben wurde, versuchen wir es zu finden
        if (empty($dob)) {
            // Erst im Pool schauen
            $poolData = $this->conn->fetchAssociative("SELECT geburtsdatum, geschlecht FROM sportabzeichen_participants WHERE user_id = :uid", ['uid' => $userId]);
            
            if ($poolData) {
                $dob = $poolData['geburtsdatum'] ?? null;
                $gender = $gender ?? $poolData['geschlecht'];
            }

            // Wenn immer noch leer, in users table schauen (Systemdaten)
            if (empty($dob)) {
                try {
                    $sysData = $this->conn->fetchAssociative("SELECT birthday FROM users WHERE id = :uid", ['uid' => $userId]);
                    if (!empty($sysData['birthday'])) {
                        $dob = $sysData['birthday'];
                    }
                } catch (\Throwable) {
                    // Spalte existiert ggf. nicht
                }
            }
        }

        // Wenn wir jetzt immer noch kein Datum haben, können wir den Schüler nicht anlegen
        if (empty($dob)) {
            return;
        }

        // 2. Participant im Pool anlegen oder aktualisieren
        // Default Gender, falls unbekannt
        $gender = $gender ?? 'MALE'; 

        // Wir brauchen den Account-Namen für das 'import_id' Feld im sportabzeichen_participants (Legacy Grund)
        $account = $this->conn->fetchOne("SELECT act FROM users WHERE id = :uid", ['uid' => $userId]);

        $this->conn->executeStatement("
            INSERT INTO sportabzeichen_participants (user_id, import_id, geburtsdatum, geschlecht)
            VALUES (:uid, :act, :dob, :gender)
            ON CONFLICT (user_id) DO UPDATE SET 
                geburtsdatum = EXCLUDED.geburtsdatum,
                geschlecht = CASE WHEN EXCLUDED.geschlecht IS NOT NULL THEN EXCLUDED.geschlecht ELSE sportabzeichen_participants.geschlecht END
        ", [
            'uid' => $userId,
            'act' => $account,
            'dob' => $dob,
            'gender' => $gender
        ]);

        // 3. Verknüpfung zur Prüfung
        $birthYear = (int)substr((string)$dob, 0, 4);
        $age = $examYear - $birthYear;

        // ID des Participants holen
        $pId = $this->conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = :uid", ['uid' => $userId]);

        if ($pId) {
            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (:eid, :pid, :age)
                ON CONFLICT DO NOTHING
            ", [
                'eid' => $examId,
                'pid' => $pId,
                'age' => $age
            ]);
        }
    }

    /**
     * Fallback für den Import via import_id (wenn man keine User-ID zur Hand hat, z.B. aus CSV Importen)
     */
    private function importByImportId(int $examId, int $examYear, string $importId): void
    {
        $row = $this->conn->fetchNumeric("SELECT user_id FROM sportabzeichen_participants WHERE import_id = :iid", ['iid' => $importId]);
        
        // Wenn im Pool gefunden, nutzen wir die UserID Logik
        if ($row && !empty($row[0])) {
            $this->processParticipantByUserId($examId, $examYear, (int)$row[0]);
        }
    }
}
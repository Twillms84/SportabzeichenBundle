<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
        
        // Zeige sicherheitshalber erstmal ALLE Prüfungen (Creator-Filter raus)
        $exams = $examRepository->findBy(
            [], 
            ['year' => 'DESC', 'date' => 'DESC']
        );

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams' => $exams,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // 1. Klassen laden
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // 2. Gruppen laden
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            // Sicherstellen, dass Account nicht null ist
            $acc = $g->getAccount();
            if ($acc) {
                $groupsForDropdown[$acc] = $g->getName();
            }
        }

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                // Array-Zugriff sicher machen mit '?? []'
                $postData = $request->request->all();
                $selectedClasses = $postData['classes'] ?? [];
                $selectedGroups  = $postData['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);

                $user = $this->getUser();
                $exam->setCreator($user ? $user->getUsername() : null);
                
                $em->persist($exam);
                $em->flush(); // Hier wird die ID generiert

                $examId = $exam->getId();
                if (!$examId) {
                    throw new \Exception("Prüfung konnte nicht gespeichert werden (keine ID).");
                }

                // A. Klassen importieren
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $examId, $year, (string)$singleClass);
                    }
                }

                // B. Gruppen importieren
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $this->importParticipantsFromGroup($em, $conn, $exam, (string)$groupAccount);
                    }
                }

                $this->addFlash('success', 'Prüfung erfolgreich angelegt.');
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                // 1. Infos über den aktuellen User (Creator) sammeln
                $currentUser = $this->getUser();
                $creatorInfo = 'NULL';
                $creatorId = 'n/a';
                $creatorClass = 'n/a';

                if ($currentUser) {
                    // Wir prüfen, ob der User überhaupt eine ID hat (wichtig für Doctrine Relationen)
                    $creatorId = $currentUser->getId() ?? 'NULL (Fehler!)';
                    $creatorInfo = $currentUser->getUsername();
                    // Zeigt an, ob es ein echtes User-Objekt oder ein Doctrine-Proxy ist
                    $creatorClass = get_class($currentUser); 
                }

                // 2. Infos über die Eingabedaten
                $inputName = $request->request->get('exam_name', '(leer)');
                $inputYear = $request->request->get('exam_year', '(leer)');

                // 3. Fehlerbericht zusammenbauen
                $errorMessage = sprintf(
                    "CRASH: %s (in Zeile %d).\n" .
                    "Creator: %s (ID: %s) | Class: %s\n" .
                    "Input: Name='%s', Year='%s'",
                    $e->getMessage(),
                    $e->getLine(),
                    $creatorInfo,
                    $creatorId,
                    $creatorClass,
                    $inputName,
                    $inputYear
                );

                // Optional: Den Fehler auch ins IServ-System-Log schreiben (hilft, wenn die Flash-Message zu lang ist)
                // error_log($errorMessage); 

                $this->addFlash('error', $errorMessage);
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes,
            'groups'  => $groupsForDropdown
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$id]);
        if (!$exam) throw $this->createNotFoundException();

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('exam_name'));
            $year = (int)$request->request->get('exam_year');
            if ($year < 100) $year += 2000;
            $date = $request->request->get('exam_date') ?: null;

            $conn->update('sportabzeichen_exams', [
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

    /**
     * DELETE: Prüfung löschen
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // CSRF Token Check (Sicherheit)
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $id, $token)) {
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        $conn->beginTransaction();
        try {
            // 1. Ergebnisse löschen
            $conn->executeStatement("
                DELETE FROM sportabzeichen_exam_results 
                WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?)
            ", [$id]);

            // 2. Teilnehmer-Verknüpfungen löschen
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_participants WHERE exam_id = ?", [$id]);

            // 3. Prüfung selbst löschen
            $conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = ?", [$id]);

            $conn->commit();
            $this->addFlash('success', 'Prüfung und alle zugehörigen Ergebnisse wurden gelöscht.');

        } catch (\Exception $e) {
            $conn->rollBack();
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        // KORREKTUR: 'import_id' statt 'importid' (IServ Standard)
        // Wir fangen auch Fehler ab, falls die Spalte doch anders heißt
        try {
            $importIds = $conn->fetchFirstColumn("
                SELECT import_id FROM users 
                WHERE auxinfo = ? AND import_id IS NOT NULL AND import_id <> ''
            ", [$class]);
        } catch (\Throwable $e) {
            // Fallback: Versuch es mit 'importid' (ohne Unterstrich), falls die DB alt ist
            try {
                 $importIds = $conn->fetchFirstColumn("
                    SELECT importid FROM users 
                    WHERE auxinfo = ? AND importid IS NOT NULL AND importid <> ''
                ", [$class]);
            } catch (\Throwable $e2) {
                return; // Aufgeben, wenn beide Spaltennamen nicht existieren
            }
        }

        foreach ($importIds as $importId) {
            if (empty($importId)) continue;

            $this->insertParticipantByImportId($conn, $examId, $examYear, $importId);
        }
    }

    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        $examId = (int) $exam->getId();
        $examYear = (int) $exam->getYear();

        // 1. Wir holen uns die echten User-IDs (Integer) aller Gruppenmitglieder.
        // Das funktioniert für ALLE (Lehrer, Schüler, Admin), egal ob sie eine import_id haben oder nicht.
        // Wir nutzen wieder den Subselect-Trick, damit es beim Abruf nicht crasht.
        
        $sqlFetchIds = "
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

        $userIds = $conn->fetchFirstColumn(
            $sqlFetchIds, 
            ['gname' => $groupAccount], 
            ['gname' => \PDO::PARAM_STR]
        );

        // 2. Jetzt gehen wir jeden User einzeln durch
        foreach ($userIds as $userId) {
            if (!$userId) continue;

            // Neue Hilfsmethode aufrufen, die mit der User-ID arbeitet
            $this->processParticipantByUserId($conn, $examId, $examYear, (int)$userId);
        }
    }

    /**
     * Diese Methode kümmert sich um einen einzelnen User anhand seiner ID.
     * Sie holt das Geburtsdatum direkt aus der IServ-Tabelle, falls es im Pool noch fehlt.
     */
    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        // A. Geburtsdatum ermitteln
        // Wir schauen erst im Sportabzeichen-Pool, dann in der IServ-System-Tabelle
        $dob = null;

        // 1. Check im Pool
        $poolData = $conn->fetchAssociative("SELECT geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        if ($poolData && !empty($poolData['geburtsdatum'])) {
            $dob = $poolData['geburtsdatum'];
        } 
        else {
            // 2. Check in der System-Tabelle (users.birthday), falls im Pool nichts steht
            // Hinweis: 'birthday' ist Standard in IServ, kann aber je nach Version variieren.
            try {
                $sysData = $conn->fetchAssociative("SELECT birthday FROM users WHERE id = ?", [$userId]);
                if ($sysData && !empty($sysData['birthday'])) {
                    $dob = $sysData['birthday'];
                    
                    // Wir aktualisieren den Pool sofort, damit wir es beim nächsten Mal haben
                    // Wir nutzen INSERT ... ON CONFLICT (oder simplen Insert Ignore Logik)
                    
                    // Erstmal sicherstellen, dass der Eintrag existiert
                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_participants (user_id, geburtsdatum)
                        VALUES (?, ?)
                        ON CONFLICT (user_id) DO UPDATE SET geburtsdatum = EXCLUDED.geburtsdatum
                    ", [$userId, $dob]);
                }
            } catch (\Throwable $e) {
                // Falls die Spalte 'birthday' nicht existiert oder Zugriff verweigert wird -> Pech
            }
        }

        // B. Wenn wir kein Geburtsdatum haben, können wir nichts tun -> Abbruch für diesen User
        if (empty($dob)) {
            return; 
        }

        // C. Alter berechnen
        // $dob ist meist ein String "YYYY-MM-DD"
        $birthYear = (int)substr((string)$dob, 0, 4);
        $age = $examYear - $birthYear;

        // D. In Prüfung eintragen
        $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        if ($participantId) {
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?)
                ON CONFLICT DO NOTHING
            ", [$examId, $participantId, $age]);
        }
    }

    /**
     * Zentrale Methode, um "Undefined Array Key" sicher zu verhindern
     */
    private function insertParticipantByImportId(Connection $conn, int $examId, int $examYear, string $importId): bool
    {
        $row = $conn->fetchNumeric("
            SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
        ", [$importId]);

        if ($this->isValidParticipantRow($row)) {
            $this->doInsert($conn, $examId, $examYear, $row);
            return true;
        }
        return false;
    }

    /**
     * Prüft extrem streng, ob das Ergebnis der DB brauchbar ist
     */
    private function isValidParticipantRow($row): bool
    {
        // 1. Ist es überhaupt ein Array? (fetchNumeric kann false liefern)
        if (!is_array($row)) return false;
        
        // 2. Existieren die Indexe 0 (ID) und 1 (Datum)?
        if (!array_key_exists(0, $row) || !array_key_exists(1, $row)) return false;

        // 3. Ist das Datum gefüllt?
        if (empty($row[1])) return false;

        return true;
    }

    private function doInsert(Connection $conn, int $examId, int $examYear, array $row): void
    {
        $pId = $row[0];
        $pDob = $row[1]; // String, z.B. "2010-05-01"

        // Sicherstellen, dass substr nicht crasht
        $dobYear = (int)substr((string)$pDob, 0, 4);
        $age = $examYear - $dobYear;

        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
            VALUES (?, ?, ?) ON CONFLICT DO NOTHING
        ", [$examId, $pId, $age]);
    }
    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, EntityManagerInterface $em, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // 1. Prüfung laden
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // 2. Klassenliste laden (Dropdown)
        // Wir nehmen natives SQL, da 'auxinfo' in der Entity oft nicht verfügbar ist
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // --- POST: SPEICHERN ---
        if ($request->isMethod('POST')) {
            $account = trim($request->request->get('account', ''));
            $gender  = $request->request->get('gender');
            $dobStr  = $request->request->get('dob');

            if (!empty($account) && !empty($gender) && !empty($dobStr)) {
                $userId = $conn->fetchOne("SELECT id FROM users WHERE act = ? AND deleted IS NULL", [$account]);
                
                if ($userId) {
                    try {
                        // A. User im "Pool" (sportabzeichen_participants) anlegen oder updaten
                        // ON CONFLICT sorgt dafür, dass wir das Datum aktualisieren, falls der User schon existiert
                        $conn->executeStatement(
                            "INSERT INTO sportabzeichen_participants (user_id, import_id, geschlecht, geburtsdatum)
                             VALUES (:uid, :act, :gender, :dob)
                             ON CONFLICT (user_id) DO UPDATE SET 
                                geschlecht = EXCLUDED.geschlecht,
                                geburtsdatum = EXCLUDED.geburtsdatum",
                            ['uid' => $userId, 'act' => $account, 'gender' => $gender, 'dob' => $dobStr]
                        );

                        // B. Zur aktuellen Prüfung hinzufügen
                        $examYear  = (int)$exam['exam_year'];
                        $birthYear = (int)substr($dobStr, 0, 4);
                        $age       = $examYear - $birthYear;

                        // Participant-ID holen (die wir gerade angelegt/aktualisiert haben)
                        $pId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
                        
                        $conn->executeStatement(
                            "INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                             VALUES (:eid, :pid, :age) ON CONFLICT DO NOTHING",
                            ['eid' => $id, 'pid' => $pId, 'age' => $age]
                        );

                        $this->addFlash('success', $account . ' hinzugefügt.');
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                    }
                }
            }
            // Redirect behält den Klassen-Filter bei
            $currentFilter = $request->query->get('filter_class');
            return $this->redirectToRoute('sportabzeichen_exams_add_participant', ['id' => $id, 'filter_class' => $currentFilter]);
        }

        // --- GET: TEILNEHMERLISTE LADEN ---
        $missingStudents = [];
        $selectedClass = $request->query->get('filter_class');

        if ($selectedClass) {
            // Wir nutzen SQL statt QueryBuilder, um Probleme mit 'auxinfo' und 'User'-Entity zu vermeiden.
            // Logik: 
            // 1. Hole alle User aus der Klasse (u.auxinfo).
            // 2. JOIN auf den Pool (sp), um evtl. vorhandenes Geburtsdatum zu holen.
            // 3. Filtere User raus, die schon in DIESER Prüfung (sep) sind.
            
            $sql = "
                SELECT 
                    u.act, 
                    u.firstname, 
                    u.lastname, 
                    u.sex, 
                    sp.geburtsdatum
                FROM users u
                LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
                WHERE u.auxinfo = :class
                AND u.deleted IS NULL
                AND u.id NOT IN (
                    SELECT sp_inner.user_id 
                    FROM sportabzeichen_exam_participants sep
                    JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                    WHERE sep.exam_id = :examId
                )
                ORDER BY u.lastname, u.firstname
            ";

            $rows = $conn->fetchAllAssociative($sql, [
                'class' => $selectedClass,
                'examId' => $id
            ]);

            foreach ($rows as $row) {
                // Geschlecht normalisieren (In der DB steht meist 1/2 oder m/w)
                $gender = 'MALE';
                $sexDb = isset($row['sex']) ? strtolower((string)$row['sex']) : '';
                
                // 2 = weiblich, w = weiblich, female
                if ($sexDb == '2' || $sexDb == 'w' || $sexDb == 'female') {
                    $gender = 'FEMALE';
                }

                $missingStudents[] = [
                    'account' => $row['act'],
                    'name'    => $row['firstname'] . ' ' . $row['lastname'],
                    'dob'     => $row['geburtsdatum'], // Ist NULL, wenn User noch nie erfasst wurde
                    'gender'  => $gender
                ];
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/add_participant.html.twig', [
            'exam' => $exam,
            'classes' => $classes,
            'selected_class' => $selectedClass,
            'missing_students' => $missingStudents
        ]);
    }
}
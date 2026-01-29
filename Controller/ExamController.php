<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group;
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

        // 1. Nur noch Gruppen laden (keine Klassen mehr)
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
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
                
                $postData = $request->request->all();
                // Wir schauen nur noch auf 'groups'
                $selectedGroups  = $postData['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);

                $user = $this->getUser();
                $exam->setCreator($user ? $user->getUsername() : null);
                
                $em->persist($exam);
                $em->flush(); // Generiert die ID

                $examId = $exam->getId();
                if (!$examId) {
                    throw new \Exception("Prüfung konnte nicht gespeichert werden (keine ID).");
                }

                // Gruppen importieren und Zuordnung speichern
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $groupAccount = (string)$groupAccount;
                        
                        // 1. Zuordnung in DB speichern (WICHTIG für addParticipant Filter!)
                        // Wir nutzen Insert Ignore / On Conflict Do Nothing
                        $conn->executeStatement("
                            INSERT INTO sportabzeichen_exam_groups (exam_id, act) 
                            VALUES (?, ?) ON CONFLICT DO NOTHING
                        ", [$examId, $groupAccount]);

                        // 2. Teilnehmer importieren
                        $this->importParticipantsFromGroup($conn, $exam, $groupAccount);
                    }
                }

                $this->addFlash('success', 'Prüfung erfolgreich angelegt.');
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'groups'  => $groupsForDropdown
            // 'classes' wurde entfernt
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // Fall A: Prüfung bearbeiten (Erkennbar am Feld 'exam_year')
            if ($request->request->has('exam_year')) {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                $date = $request->request->get('exam_date') ?: null;

                $conn->update('sportabzeichen_exams', [
                    'exam_name' => $name,
                    'exam_year' => $year,
                    'exam_date' => $date
                ], ['id' => $id]);

                $this->addFlash('success', 'Stammdaten gespeichert.');
                
                // Redirect auf sich selbst, um POST-Resubmission zu verhindern
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }

            // Fall B: Teilnehmer hinzufügen (Erkennbar am Feld 'account')
            if ($request->request->has('account')) {
                $account = trim($request->request->get('account', ''));
                $gender  = $request->request->get('gender');
                $dobStr  = $request->request->get('dob');

                if ($account && $gender && $dobStr) {
                    $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                    if ($userId) {
                        try {
                            // 1. Ggf. Pool-Daten updaten (falls manuell Datum eingegeben wurde)
                            $conn->executeStatement("
                                INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht)
                                VALUES (?, ?, ?)
                                ON CONFLICT (user_id) DO UPDATE SET geburtsdatum = EXCLUDED.geburtsdatum, geschlecht = EXCLUDED.geschlecht
                            ", [$userId, $dobStr, $gender]);

                            // 2. In Prüfung einfügen
                            $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                            
                            $this->addFlash('success', "Teilnehmer hinzugefügt.");
                        } catch (\Throwable $e) {
                            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                        }
                    }
                }
                // Redirect auf sich selbst mit Suchparameter
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }
        }

        // --- GET: Liste der fehlenden Schüler laden ---
        
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // Die gleiche SQL-Logik wie zuvor, ohne 'auxinfo'
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender
            FROM users u
            INNER JOIN members m ON u.act = m.user
            INNER JOIN sportabzeichen_exam_groups seg ON m.group = seg.act 
            LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            
            WHERE u.deleted IS NULL
            AND seg.exam_id = :examId
            
            AND NOT EXISTS (
                SELECT 1 FROM sportabzeichen_exam_participants sep
                JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
            )
        ";

        $params = ['examId' => $id];

        if (!empty($searchTerm)) {
            $sql .= " AND (u.lastname ILIKE :search OR u.firstname ILIKE :search) ";
            $params['search'] = '%' . $searchTerm . '%';
        }

        $sql .= " ORDER BY (sp.geburtsdatum IS NULL) DESC, u.lastname ASC, u.firstname ASC LIMIT 500";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => $row['firstname'] . ' ' . $row['lastname'],
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE'
            ];
        }

        return $this->render('@PulsRSportabzeichen/exams/edit.html.twig', [
            'exam' => $exam,
            'missing_students' => $missingStudents,
            'search_term' => $searchTerm
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

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
            
            // 3. Gruppen-Verknüpfungen löschen (Neu, der Sauberkeit halber)
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ?", [$id]);

            // 4. Prüfung selbst löschen
            $conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = ?", [$id]);

            $conn->commit();
            $this->addFlash('success', 'Prüfung gelöscht.');

        } catch (\Exception $e) {
            $conn->rollBack();
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        // SCHRITT 1: Die Gruppe der Prüfung zuordnen (WICHTIG für die Edit-Ansicht!)
        // Damit später die Abfrage "Wer fehlt noch aus dieser Gruppe?" funktioniert.
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_groups (exam_id, act)
            VALUES (?, ?)
            ON CONFLICT (exam_id, act) DO NOTHING
        ", [$exam->getId(), $groupAccount]);

        // SCHRITT 2: IServ Gruppe laden
        $groupRepo = $em->getRepository(Group::class);
        $group = $groupRepo->findOneBy(['account' => $groupAccount]);
        
        if (!$group) return;

        // SCHRITT 3: Alle User der Gruppe durchgehen
        foreach ($group->getUsers() as $user) {
            
            // IServ User Daten holen
            // 'act' ist im IServ-Kontext meist der eindeutige Identifier (ImportID)
            $importId = $user->getUsername(); 
            $dob = $user->getBirthday(); // DateTime|null
            
            // Geschlecht normalisieren (IServ speichert oft m/w/f oder 1/2/null)
            $genderRaw = $user->getGender();
            $gender = null;
            // Einfache Mapping-Logik (anpassen je nach IServ Version)
            if (in_array($genderRaw, ['m', 'MALE', 1])) $gender = 'MALE';
            if (in_array($genderRaw, ['w', 'f', 'FEMALE', 2])) $gender = 'FEMALE';

            // --- A) Teilnehmer (Participant) finden oder erstellen ---
            
            // Prüfen, ob Participant existiert
            $existingData = $conn->fetchAssociative(
                "SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?", 
                [$importId]
            );

            $participantId = null;
            $participantDob = null;

            if ($existingData) {
                // UPDATE: Existiert bereits
                $participantId = $existingData['id'];
                
                // Datum aus DB nehmen
                if ($existingData['geburtsdatum']) {
                    $participantDob = new \DateTime($existingData['geburtsdatum']);
                }
                
                // OPTIONAL: Wenn DB leer, aber User hat jetzt Daten -> Updaten
                if (!$participantDob && $dob) {
                    $conn->executeStatement(
                        "UPDATE sportabzeichen_participants SET geburtsdatum = ?, geschlecht = ?, updated_at = NOW() WHERE id = ?",
                        [$dob->format('Y-m-d'), $gender, $participantId]
                    );
                    $participantDob = $dob;
                }

            } else {
                // INSERT: Neu anlegen
                // Wir nutzen RETURNING id (Postgres Feature), um direkt die ID zu bekommen
                $participantId = $conn->fetchOne("
                    INSERT INTO sportabzeichen_participants (import_id, username, geburtsdatum, geschlecht, user_id)
                    VALUES (?, ?, ?, ?, ?)
                    ON CONFLICT (import_id) DO UPDATE SET updated_at = NOW() -- Fallback Race Condition
                    RETURNING id
                ", [
                    $importId,
                    $importId, // Username als Fallback Name
                    $dob ? $dob->format('Y-m-d') : null,
                    $gender,
                    $user->getId() // Verknüpfung zur IServ Users Tabelle
                ]);
                
                $participantDob = $dob;
            }

            // --- B) Prüfungsteilnahme (Exam Participant) eintragen ---

            // Alter berechnen (Benötigt für Anforderungen)
            $ageYear = 0;
            if ($participantDob) {
                $ageYear = $exam->getYear() - (int)$participantDob->format('Y');
            } elseif ($dob) {
                // Fallback, falls gerade frisch importiert
                $ageYear = $exam->getYear() - (int)$dob->format('Y');
            }

            // In die Exam-Tabelle eintragen
            if ($participantId) {
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                    VALUES (?, ?, ?)
                    ON CONFLICT (exam_id, participant_id) DO NOTHING
                ", [
                    $exam->getId(),
                    $participantId,
                    $ageYear
                ]);
            }
        }
    }

    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        // 1. Geburtsdatum ermitteln
        $dob = null;
        $poolData = $conn->fetchAssociative("SELECT geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        if ($poolData && !empty($poolData['geburtsdatum'])) {
            $dob = $poolData['geburtsdatum'];
        } else {
            // Fallback auf System-Daten
            try {
                $sysData = $conn->fetchAssociative("SELECT birthday FROM users WHERE id = ?", [$userId]);
                if ($sysData && !empty($sysData['birthday'])) {
                    $dob = $sysData['birthday'];
                    
                    // Sofort in Pool übernehmen
                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_participants (user_id, geburtsdatum)
                        VALUES (?, ?)
                        ON CONFLICT (user_id) DO UPDATE SET geburtsdatum = EXCLUDED.geburtsdatum
                    ", [$userId, $dob]);
                }
            } catch (\Throwable $e) {
                // Ignore errors if column doesn't exist
            }
        }

        if (empty($dob)) return; 

        // 2. Alter berechnen
        $birthYear = (int)substr((string)$dob, 0, 4);
        $age = $examYear - $birthYear;

        // 3. In Prüfung eintragen
        $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        if ($participantId) {
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?)
                ON CONFLICT DO NOTHING
            ", [$examId, $participantId, $age]);
        }
    }

    // Hilfsmethode für Import via ID (Legacy Code, falls noch woanders genutzt, sonst könnte man sie auch löschen)
    private function insertParticipantByImportId(Connection $conn, int $examId, int $examYear, string $importId): bool
    {
        $row = $conn->fetchNumeric("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?", [$importId]);
        if ($this->isValidParticipantRow($row)) {
            $this->doInsert($conn, $examId, $examYear, $row);
            return true;
        }
        return false;
    }

    private function isValidParticipantRow($row): bool
    {
        if (!is_array($row)) return false;
        if (!array_key_exists(0, $row) || !array_key_exists(1, $row)) return false;
        if (empty($row[1])) return false;
        return true;
    }

    private function doInsert(Connection $conn, int $examId, int $examYear, array $row): void
    {
        $pId = $row[0];
        $dobYear = (int)substr((string)$row[1], 0, 4);
        $age = $examYear - $dobYear;

        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
            VALUES (?, ?, ?) ON CONFLICT DO NOTHING
        ", [$examId, $pId, $age]);
    }

    // --- Add Participant ---
    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        // --- POST: User manuell hinzufügen ---
        if ($request->isMethod('POST')) {
            $account = trim($request->request->get('account', ''));
            $gender  = $request->request->get('gender');
            $dobStr  = $request->request->get('dob');

            if ($account && $gender && $dobStr) {
                $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                
                if ($userId) {
                    try {
                        $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                        // Falls wir ein manuelles Update des Datums brauchen, müsste man das hier erweitern, 
                        // aber processParticipantByUserId verlässt sich auf DB-Daten.
                        // Wenn der User im Formular ein Datum angibt, wollen wir das ggf. in den Pool schreiben:
                        
                        $conn->executeStatement("
                            INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht)
                            VALUES (?, ?, ?)
                            ON CONFLICT (user_id) DO UPDATE SET geburtsdatum = EXCLUDED.geburtsdatum, geschlecht = EXCLUDED.geschlecht
                        ", [$userId, $dobStr, $gender]);

                        // Nochmal prozessieren, damit er ins Exam kommt
                        $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                        
                        $this->addFlash('success', "Teilnehmer hinzugefügt.");
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                    }
                }
            }
            return $this->redirectToRoute('sportabzeichen_exams_add_participant', [
                'id' => $id, 
                'q' => $request->query->get('q')
            ]);
        }

        // --- GET: Liste laden ---
        
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // SQL: Nur User laden, die in einer zugeordneten Gruppe sind (Klasse auxinfo komplett entfernt)
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender
            FROM users u
            INNER JOIN members m ON u.act = m.user
            INNER JOIN sportabzeichen_exam_groups seg ON m.group = seg.act 
            LEFT JOIN sportabzeichen_participants sp ON u.id = sp.user_id
            
            WHERE u.deleted IS NULL
            AND seg.exam_id = :examId
            
            AND NOT EXISTS (
                SELECT 1 FROM sportabzeichen_exam_participants sep
                JOIN sportabzeichen_participants sp_inner ON sep.participant_id = sp_inner.id
                WHERE sp_inner.user_id = u.id AND sep.exam_id = :examId
            )
        ";

        $params = ['examId' => $id];

        if (!empty($searchTerm)) {
            $sql .= " AND (u.lastname ILIKE :search OR u.firstname ILIKE :search) ";
            $params['search'] = '%' . $searchTerm . '%';
        }

        // Sortierung: Ohne Geburtsdatum zuerst, dann Nachname
        $sql .= " ORDER BY (sp.geburtsdatum IS NULL) DESC, u.lastname ASC, u.firstname ASC LIMIT 500";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => $row['firstname'] . ' ' . $row['lastname'],
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE'
            ];
        }

        return $this->render('@PulsRSportabzeichen/exams/add_participant.html.twig', [
            'exam' => $exam,
            'missing_students' => $missingStudents,
            'search_term' => $searchTerm
        ]);
    }
}
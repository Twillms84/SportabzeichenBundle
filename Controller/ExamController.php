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

        // Gruppen für Dropdown laden
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            if ($g->getAccount()) {
                $groupsForDropdown[$g->getAccount()] = $g->getName();
            }
        }

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name', ''));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $selectedGroups = $request->request->all()['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser() ? $this->getUser()->getUsername() : null);
                
                $em->persist($exam);
                $em->flush();

                // --- DEBUGGING STARTEN ---
                $debugLog = ['added' => [], 'skipped' => []];

                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        // Übergabe von $debugLog per Referenz (&)
                        $this->importParticipantsFromGroup($em, $conn, $exam, (string)$groupAccount, $debugLog);
                    }
                }

                // --- MELDUNG ZUSAMMENBAUEN ---
                $countAdded = count($debugLog['added']);
                $countSkipped = count($debugLog['skipped']);
                
                $msg = "Prüfung angelegt. <strong>$countAdded</strong> Teilnehmer erfolgreich hinzugefügt.";
                
                if ($countAdded > 0) {
                    // Die ersten 5 Namen als Beispiel
                    $names = array_slice($debugLog['added'], 0, 5);
                    $msg .= " (z.B. " . implode(', ', $names) . ")";
                }

                if ($countSkipped > 0) {
                    $msg .= "<br><br><span style='color:red'><strong>$countSkipped übersprungen</strong> (kein Geburtsdatum gefunden):</span> ";
                    // Die ersten 10 fehlenden anzeigen, damit man weiß, wer fehlt
                    $skippedNames = array_slice($debugLog['skipped'], 0, 15);
                    $msg .= implode(', ', $skippedNames) . ($countSkipped > 15 ? '...' : '');
                    $msg .= "<br><em>Bitte Geburtsdaten in 'sportabzeichen_participants' prüfen oder manuell nachtragen.</em>";
                }

                $this->addFlash('success', $msg);
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'groups'  => $groupsForDropdown
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, Connection $conn, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // Wir brauchen das Entity für die Helper-Methode importParticipantsFromGroup
        $examEntity = $em->getRepository(Exam::class)->find($id);
        if (!$examEntity) throw $this->createNotFoundException('Prüfung nicht gefunden');
        
        // Array-Daten für DBAL-Operationen (Legacy-Support für deinen Code)
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);

        // --- POST HANDLING ---
        if ($request->isMethod('POST')) {
            
            // 1. STAMMDATEN BEARBEITEN
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
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }

            // 2. GRUPPE HINZUFÜGEN
            if ($request->request->has('add_group')) {
                $groupAct = $request->request->get('group_act');
                if ($groupAct) {
                    try {
                        $this->importParticipantsFromGroup($em, $conn, $examEntity, $groupAct);
                        $this->addFlash('success', 'Gruppe hinzugefügt und Mitglieder importiert.');
                    } catch (\Throwable $e) {
                        $this->addFlash('error', 'Fehler beim Import: ' . $e->getMessage());
                    }
                }
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id]);
            }

            // 3. GRUPPE ENTFERNEN
            if ($request->request->has('remove_group')) {
                $groupAct = $request->request->get('remove_group');
                $conn->executeStatement(
                    "DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ? AND act = ?", 
                    [$id, $groupAct]
                );
                $this->addFlash('success', 'Gruppe aus der Prüfung entfernt (bereits importierte Teilnehmer bleiben erhalten).');
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id]);
            }

            // 4. EINZELNEN TEILNEHMER HINZUFÜGEN (aus der "Fehlende"-Liste)
            if ($request->request->has('account')) {
                $account = trim($request->request->get('account', ''));
                $gender  = $request->request->get('gender');
                $dobStr  = $request->request->get('dob');

                if ($account && $gender && $dobStr) {
                    $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
                    if ($userId) {
                        try {
                            // A) Pool-Daten updaten/anlegen (OHNE ON CONFLICT, da kein Unique-Index existiert)
                            
                            // 1. Prüfen, ob Eintrag schon existiert
                            $existingPartId = $conn->fetchOne(
                                "SELECT id FROM sportabzeichen_participants WHERE user_id = ?", 
                                [$userId]
                            );

                            if ($existingPartId) {
                                // Update existierender Eintrag
                                $conn->update('sportabzeichen_participants', [
                                    'geburtsdatum' => $dobStr,
                                    'geschlecht' => $gender
                                ], ['id' => $existingPartId]);
                            } else {
                                // Neu anlegen
                                $conn->insert('sportabzeichen_participants', [
                                    'user_id' => $userId,
                                    'geburtsdatum' => $dobStr,
                                    'geschlecht' => $gender,
                                    'username' => $account // <--- DAS HIER EINFÜGEN
                                ]);
                            }

                            // B) In Prüfung einfügen
                            $this->processParticipantByUserId($conn, (int)$id, (int)$exam['exam_year'], (int)$userId);
                            
                            $this->addFlash('success', "Teilnehmer hinzugefügt.");
                        } catch (\Throwable $e) {
                            $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                        }
                    }
                }
                // Redirect um Formular-Resubmission zu verhindern
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }
        }

        // --- GET DATEN LADEN ---

        // A) Zugeordnete Gruppen laden
        $assignedGroups = $conn->fetchAllAssociative("
            SELECT seg.act, g.name 
            FROM sportabzeichen_exam_groups seg
            LEFT JOIN groups g ON seg.act = g.act
            WHERE seg.exam_id = ?
            ORDER BY g.name ASC
        ", [$id]);
        
        $assignedActs = array_column($assignedGroups, 'act');

        // B) Alle Gruppen für Dropdown laden (die noch nicht zugeordnet sind)
        $allGroupsObj = $em->getRepository(Group::class)->findBy([], ['name' => 'ASC']);
        $availableGroups = [];
        foreach ($allGroupsObj as $g) {
            if ($g->getAccount() && !in_array($g->getAccount(), $assignedActs)) {
                $availableGroups[$g->getAccount()] = $g->getName();
            }
        }

        // C) Liste der fehlenden Schüler laden
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // KORREKTUR: "user" muss in Anführungszeichen stehen (maskiert als \"user\"), 
        // da es ein reserviertes SQL-Wort ist.
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender,
                g.name as group_name,
                (sp.geburtsdatum IS NULL) as is_missing_dob
            FROM users u
            INNER JOIN members m ON u.act = m.actuser
            INNER JOIN sportabzeichen_exam_groups seg ON m.actgrp = seg.act 
            LEFT JOIN groups g ON seg.act = g.act
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

        // KORREKTUR: Wir sortieren jetzt nach der oben definierten Hilfsspalte 'is_missing_dob'
        $sql .= " ORDER BY is_missing_dob DESC, u.lastname ASC, u.firstname ASC LIMIT 300";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => $row['firstname'] . ' ' . $row['lastname'],
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE',
                'group'     => $row['group_name'] // Damit man sieht, warum der hier auftaucht
            ];
        }

        return $this->render('@PulsRSportabzeichen/exams/edit.html.twig', [
            'exam' => $exam,
            'assigned_groups' => $assignedGroups,
            'available_groups' => $availableGroups,
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

    private function importParticipantsFromGroup(
        EntityManagerInterface $em, 
        Connection $conn, 
        Exam $exam, 
        string $groupAccount, 
        array &$debugLog = [] // Referenz für Logging
    ): void
    {
        // 1. Gruppe für Prüfung registrieren
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_groups (exam_id, act) VALUES (?, ?)
            ON CONFLICT (exam_id, act) DO NOTHING
        ", [$exam->getId(), $groupAccount]);

        // 2. Gruppe laden
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        // 3. User iterieren
        foreach ($group->getUsers() as $user) {
            
            $iservUserId = $user->getId();
            // Name holen (Existiert sicher in der Entity)
            $fullName = $user->getName() ?? $user->getUsername(); 

            // --- KORREKTUR: Geburtsdatum direkt aus der DB holen ---
            // Wir umgehen die Entity und fragen die Tabelle direkt, da die Spalte 'birthday' dort existiert.
            $dobString = $conn->fetchOne("SELECT birthday FROM users WHERE id = ?", [$iservUserId]);

            // Wenn kein Geburtsdatum da ist (false oder null) -> Skip
            if (!$dobString) {
                $debugLog['skipped'][] = $fullName;
                continue;
            }

            // Geschlecht holen (Entity hat oft getGender(), falls nicht, holen wir es auch per SQL)
            // Versuch über Entity:
            $gender = method_exists($user, 'getGender') ? $user->getGender() : null;
            
            // Fallback SQL, falls Entity das nicht hergibt
            if (!$gender) {
                $genderVal = $conn->fetchOne("SELECT gender FROM users WHERE id = ?", [$iservUserId]);
                $gender = $genderVal ?: 'MALE'; // Default
            }

            // Normalisierung für DB
            $genderString = ($gender === 'FEMALE' || $gender === 'female') ? 'FEMALE' : 'MALE';

            try {
                // A) POOL SYNCHRONISIEREN (Upsert)
                $participantId = $conn->fetchOne("
                    INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht)
                    VALUES (?, ?, ?)
                    ON CONFLICT (user_id) 
                    DO UPDATE SET 
                        geburtsdatum = EXCLUDED.geburtsdatum, 
                        geschlecht = EXCLUDED.geschlecht
                    RETURNING id
                ", [$iservUserId, $dobString, $genderString]);

                // Fallback für alte Postgres-Versionen ohne RETURNING
                if (!$participantId) {
                    $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$iservUserId]);
                }

                // B) IN PRÜFUNG EINTRAGEN
                // Alter berechnen
                // $dobString ist z.B. "2010-05-15" -> Die ersten 4 Zeichen sind das Jahr
                $birthYear = (int)substr((string)$dobString, 0, 4);
                $age = $exam->getYear() - $birthYear;

                $inserted = $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year, created_at)
                    VALUES (?, ?, ?, NOW())
                    ON CONFLICT (exam_id, participant_id) DO NOTHING
                ", [$exam->getId(), $participantId, $age]);

                if ($inserted > 0) {
                    $debugLog['added'][] = $fullName;
                }

            } catch (\Exception $e) {
                // Optional: Fehler loggen, aber nicht abbrechen
            }
        }
    }

    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        // 1. Prüfen, ob User schon im Pool ist
        // Wir holen auch gleich die ID mit, falls vorhanden
        $poolData = $conn->fetchAssociative("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        $dob = null;
        $participantId = null;

        if ($poolData) {
            $dob = $poolData['geburtsdatum'];
            $participantId = $poolData['id'];
        }

        // 2. Wenn kein Geburtsdatum im Pool bekannt ist -> Aus System (users Tabelle) holen und Pool updaten/anlegen
        if (empty($dob)) {
            try {
                // HIER GEÄNDERT: Wir holen auch 'act' (den Username)
                $sysData = $conn->fetchAssociative("SELECT birthday, act FROM users WHERE id = ?", [$userId]);
                
                if ($sysData && !empty($sysData['birthday'])) {
                    $dob = $sysData['birthday'];
                    $act = $sysData['act']; // Der Username
                    
                    // Sofort in Pool übernehmen (Insert oder Update)
                    // HIER GEÄNDERT: Wir schreiben act in die Spalte 'username'
                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, username)
                        VALUES (?, ?, ?)
                        ON CONFLICT (user_id) DO UPDATE SET 
                            geburtsdatum = EXCLUDED.geburtsdatum,
                            username     = EXCLUDED.username
                    ", [$userId, $dob, $act]);

                    // ID neu holen, da sie jetzt existiert
                    $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
                }
            } catch (\Throwable $e) {
                // Ignore errors
            }
        }

        if (empty($dob) || empty($participantId)) return; 

        // 3. Alter berechnen
        $birthYear = (int)substr((string)$dob, 0, 4);
        $age = $examYear - $birthYear;

        // 4. In Prüfung eintragen
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
            VALUES (?, ?, ?)
            ON CONFLICT (exam_id, participant_id) DO NOTHING
        ", [$examId, $participantId, $age]);
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
                            INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, username)
                            VALUES (?, ?, ?, ?)
                            ON CONFLICT (user_id) DO UPDATE SET 
                                geburtsdatum = EXCLUDED.geburtsdatum, 
                                geschlecht = EXCLUDED.geschlecht,
                                username = EXCLUDED.username
                        ", [$userId, $dobStr, $gender, $account]); // <--- $account am Ende hinzufügen!

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
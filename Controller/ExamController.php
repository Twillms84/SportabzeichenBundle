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

        // Gruppen laden (Für Dropdown)
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
                $selectedGroups  = $postData['groups'] ?? [];

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser() ? $this->getUser()->getUsername() : null);
                
                $em->persist($exam);
                $em->flush();

                $debugLog = [
                    'added' => [], 
                    'skipped' => [],
                    'errors' => [] 
                ];

                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $groupAccount = (string)$groupAccount;
                        $this->importParticipantsFromGroup($em, $conn, $exam, $groupAccount, $debugLog);
                    }
                }

                // --- FEEDBACK MELDUNG ---
                $countAdded = count($debugLog['added']);
                $countErrors = count($debugLog['errors']);
                
                $msg = "Prüfung angelegt. ";
                
                if ($countAdded > 0) {
                    $msg .= "<strong>$countAdded</strong> Teilnehmer hinzugefügt. ";
                    $names = array_slice($debugLog['added'], 0, 5);
                    $msg .= "(z.B. " . implode(', ', $names) . ")";
                } else {
                    $msg .= "<strong>Keine Teilnehmer hinzugefügt.</strong>";
                }

                if ($countErrors > 0) {
                    $msg .= "<br><br><span style='color:red'><strong>$countErrors Fehler/Warnungen:</strong></span><br>";
                    $msg .= implode('<br>', $debugLog['errors']);
                }

                if ($countAdded > 0) {
                     $this->addFlash('success', $msg);
                } else {
                     $this->addFlash('warning', $msg);
                }
               
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

        $examEntity = $em->getRepository(Exam::class)->find($id);
        if (!$examEntity) throw $this->createNotFoundException('Prüfung nicht gefunden');
        
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

            // 4. EINZELNEN TEILNEHMER HINZUFÜGEN
            if ($request->request->has('account')) {
                $this->handleAddSingleParticipant($request, $conn, $id, (int)$exam['exam_year']);
                return $this->redirectToRoute('sportabzeichen_exams_edit', ['id' => $id, 'q' => $request->query->get('q')]);
            }
        }

        // --- GET DATEN LADEN ---

        // A) Zugeordnete Gruppen
        $assignedGroups = $conn->fetchAllAssociative("
            SELECT seg.act, g.name 
            FROM sportabzeichen_exam_groups seg
            LEFT JOIN groups g ON seg.act = g.act
            WHERE seg.exam_id = ?
            ORDER BY g.name ASC
        ", [$id]);
        
        $assignedActs = array_column($assignedGroups, 'act');

        // B) Verfügbare Gruppen
        $allGroupsObj = $em->getRepository(Group::class)->findBy([], ['name' => 'ASC']);
        $availableGroups = [];
        foreach ($allGroupsObj as $g) {
            if ($g->getAccount() && !in_array($g->getAccount(), $assignedActs)) {
                $availableGroups[$g->getAccount()] = $g->getName();
            }
        }

        // C) Liste der fehlenden Schüler laden
        // REPARIERT: Case-Insensitive Suche + Standard IServ Spalten
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

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
            // VERBESSERUNG: Suche auch im Account-Namen (für dulli) und Case-Insensitive
            $sql .= " AND (LOWER(u.lastname) LIKE :search OR LOWER(u.firstname) LIKE :search OR LOWER(u.act) LIKE :search) ";
            $params['search'] = '%' . mb_strtolower($searchTerm) . '%';
        }

        // VERBESSERUNG: Fallback Sortierung nach 'u.act'
        $sql .= " ORDER BY is_missing_dob DESC, u.lastname ASC, u.firstname ASC, u.act ASC LIMIT 300";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => trim($row['firstname'] . ' ' . $row['lastname']) ?: $row['act'], // Name oder Account als Fallback
                'dob'       => $row['geburtsdatum'],
                'gender'    => $row['sp_gender'] ?? 'MALE',
                'group'     => $row['group_name']
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
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_results WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?)", [$id]);
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_participants WHERE exam_id = ?", [$id]);
            $conn->executeStatement("DELETE FROM sportabzeichen_exam_groups WHERE exam_id = ?", [$id]);
            $conn->executeStatement("DELETE FROM sportabzeichen_exams WHERE id = ?", [$id]);

            $conn->commit();
            $this->addFlash('success', 'Prüfung gelöscht.');

        } catch (\Exception $e) {
            $conn->rollBack();
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    // --- Helper für addParticipant & edit ---
    private function handleAddSingleParticipant(Request $request, Connection $conn, int $examId, int $examYear): void
    {
        $account = trim($request->request->get('account', ''));
        $gender  = $request->request->get('gender');
        $dobStr  = $request->request->get('dob');

        if ($account && $gender && $dobStr) {
            $userId = $conn->fetchOne("SELECT id FROM users WHERE act = :act AND deleted IS NULL", ['act' => $account]);
            if ($userId) {
                try {
                    // Update/Create Participant Pool Entry
                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, username)
                        VALUES (?, ?, ?, ?)
                        ON CONFLICT (user_id) DO UPDATE SET 
                            geburtsdatum = EXCLUDED.geburtsdatum, 
                            geschlecht = EXCLUDED.geschlecht,
                            username = EXCLUDED.username
                    ", [$userId, $dobStr, $gender, $account]);

                    // Add to Exam
                    $this->processParticipantByUserId($conn, $examId, $examYear, (int)$userId);
                    
                    $this->addFlash('success', "Teilnehmer hinzugefügt.");
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Fehler: ' . $e->getMessage());
                }
            }
        }
    }

    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount, array &$debugLog = []): void
    {
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_groups (exam_id, act) VALUES (?, ?)
            ON CONFLICT (exam_id, act) DO NOTHING
        ", [$exam->getId(), $groupAccount]);

        // REPARIERT: m.actgrp statt m.group
        $sql = "
            SELECT u.id, u.act, u.firstname, u.lastname
            FROM users u
            JOIN members m ON u.act = m.actuser
            WHERE m.actgrp = ?
        ";

        $users = $conn->fetchAllAssociative($sql, [$groupAccount]);

        if (empty($users)) {
            $debugLog['errors'][] = "Gruppe '$groupAccount' scheint leer zu sein.";
            return;
        }

        foreach ($users as $row) {
            $realUserId = $row['id'];
            $accountName = $row['act'];
            $displayName = trim(($row['firstname'] ?? '') . ' ' . ($row['lastname'] ?? '')) ?: $accountName;

            // Pool Entry
            $conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username) VALUES (?, ?)
                ON CONFLICT (user_id) DO UPDATE SET username = EXCLUDED.username
            ", [$realUserId, $accountName]);

            // Add to Exam
            try {
                // Wir nutzen die Hilfsfunktion, damit das Alter korrekt berechnet wird
                $this->processParticipantByUserId($conn, $exam->getId(), $exam->getYear(), $realUserId);
                $debugLog['added'][] = $displayName;
            } catch (\Exception $e) {
                $debugLog['errors'][] = "Fehler bei $displayName: " . $e->getMessage();
            }
        }
    }

    private function processParticipantByUserId(Connection $conn, int $examId, int $examYear, int $userId): void
    {
        $data = $conn->fetchAssociative("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        
        if (!$data) {
             // Sollte durch vorigen Insert eigentlich da sein, aber zur Sicherheit:
             $conn->executeStatement("INSERT INTO sportabzeichen_participants (user_id) VALUES (?)", [$userId]);
             $participantId = $conn->fetchOne("SELECT id FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
             $dob = null;
        } else {
            $participantId = $data['id'];
            $dob = $data['geburtsdatum'];
        }

        // Alter berechnen
        $age = 0;
        if ($dob) {
            $birthYear = (int)substr((string)$dob, 0, 4);
            $age = $examYear - $birthYear;
        }

        // In Prüfung eintragen
        $conn->executeStatement("
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year, created_at)
            VALUES (?, ?, ?, NOW())
            ON CONFLICT (exam_id, participant_id) DO NOTHING
        ", [$examId, $participantId, $age]);
    }

    // --- Add Participant (Manuelles Fenster) ---
    #[Route('/{id}/add_participant', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = :id", ['id' => $id]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden');

        if ($request->isMethod('POST')) {
            $this->handleAddSingleParticipant($request, $conn, $id, (int)$exam['exam_year']);
            return $this->redirectToRoute('sportabzeichen_exams_add_participant', [
                'id' => $id, 
                'q' => $request->query->get('q')
            ]);
        }

        // --- GET: Liste laden ---
        $searchTerm = trim($request->query->get('q', ''));
        $missingStudents = [];

        // REPARIERT: m.actuser und m.actgrp verwenden! (Vorher war es user/group)
        $sql = "
            SELECT DISTINCT
                u.id, u.act, u.firstname, u.lastname,
                sp.geburtsdatum, sp.geschlecht as sp_gender
            FROM users u
            INNER JOIN members m ON u.act = m.actuser
            INNER JOIN sportabzeichen_exam_groups seg ON m.actgrp = seg.act 
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
            // REPARIERT: Case-Insensitive + Suche in Account-Namen
            $sql .= " AND (LOWER(u.lastname) LIKE :search OR LOWER(u.firstname) LIKE :search OR LOWER(u.act) LIKE :search) ";
            $params['search'] = '%' . mb_strtolower($searchTerm) . '%';
        }

        // REPARIERT: Fallback Sortierung nach Account
        $sql .= " ORDER BY (sp.geburtsdatum IS NULL) DESC, u.lastname ASC, u.firstname ASC, u.act ASC LIMIT 500";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as $row) {
            $missingStudents[] = [
                'account'   => $row['act'],
                'name'      => trim($row['firstname'] . ' ' . $row['lastname']) ?: $row['act'],
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
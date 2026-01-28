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
        // 1. Gruppe laden
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        $groupId = $group->getId();        // z.B. 10133
        $groupName = $group->getAccount(); // z.B. 'fachgruppe.sport'
        $examId = $exam->getId();

        // ----------------------------------------------------------------
        // SCHRITT 1: User in Pool holen
        // ----------------------------------------------------------------
        // Strategie: Wir prüfen zwei Fälle getrennt mit OR.
        // 1. Primärgruppe: u.gid = 10133
        // 2. Mitglieder: m.actgrp = 'fachgruppe.sport'
        // WICHTIG: Wir nutzen explizite Parameter-Typen, um den Crash zu verhindern.
        
        $sqlEnsure = "
            INSERT INTO sportabzeichen_participants (user_id)
            SELECT DISTINCT u.id 
            FROM users u
            LEFT JOIN members m ON u.act = m.actuser
            WHERE (u.gid = :gid OR m.actgrp = :gname)
              AND u.deleted IS NULL
              AND u.id NOT IN (SELECT user_id FROM sportabzeichen_participants)
        ";
        
        $conn->executeStatement(
            $sqlEnsure, 
            ['gid' => $groupId, 'gname' => $groupName], 
            ['gid' => \PDO::PARAM_INT, 'gname' => \PDO::PARAM_STR]
        );

        // ----------------------------------------------------------------
        // SCHRITT 2: User mit Prüfung verknüpfen
        // ----------------------------------------------------------------
        $sqlLink = "
            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id)
            SELECT DISTINCT :eid, p.id
            FROM users u
            JOIN sportabzeichen_participants p ON u.id = p.user_id
            LEFT JOIN members m ON u.act = m.actuser
            WHERE (u.gid = :gid OR m.actgrp = :gname)
              AND u.deleted IS NULL
              AND p.id NOT IN (
                  SELECT participant_id FROM sportabzeichen_exam_participants WHERE exam_id = :eid
              )
        ";
        
        $conn->executeStatement(
            $sqlLink, 
            ['eid' => $examId, 'gid' => $groupId, 'gname' => $groupName],
            ['eid' => \PDO::PARAM_INT, 'gid' => \PDO::PARAM_INT, 'gname' => \PDO::PARAM_STR]
        );
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
}
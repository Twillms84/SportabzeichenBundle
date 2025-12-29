<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Zentrale Verwaltung der Prüfungen (CRUD: Create, Read, Update, Delete)
 */
#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    /**
     * DASHBOARD: Liste aller Prüfungen
     */
    #[Route('/', name: 'dashboard')]
    public function index(Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exams = $conn->fetchAllAssociative(
            'SELECT e.id, e.exam_name, e.exam_year, e.exam_date
             FROM public.sportabzeichen_exams e
             ORDER BY e.exam_year DESC'
        );

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams' => $exams,
        ]);
    }

    /**
     * CREATE: Neue Prüfung erstellen
     * (Integriert die Logik aus deinem ExamCreateController)
     */
    #[Route('/new', name: 'new')]
    public function new(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // Klassen laden für das Dropdown
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        if ($request->isMethod('POST')) {
            $conn->beginTransaction();
            try {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                
                // Jahr normalisieren (25 -> 2025)
                if ($year < 100) $year += 2000;
                
                $date = $request->request->get('exam_date') ?: null;
                $classFilter = $request->request->get('class'); // Optional: Klasse direkt importieren

                // 1. Prüfung anlegen
                $conn->insert('sportabzeichen_exams', [
                    'exam_name' => $name,
                    'exam_year' => $year,
                    'exam_date' => $date,
                ]);
                $examId = (int)$conn->lastInsertId();

                // 2. Falls eine Klasse gewählt wurde, Teilnehmer importieren
                if ($classFilter) {
                    $this->importParticipantsFromClass($conn, $examId, $year, $classFilter);
                    $this->addFlash('success', 'Prüfung angelegt und Klasse ' . $classFilter . ' importiert.');
                } else {
                    $this->addFlash('success', 'Prüfung erfolgreich angelegt.');
                }

                $conn->commit();
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $conn->rollBack();
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes
        ]);
    }

    /**
     * EDIT: Prüfung bearbeiten
     */
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

    // --- HILFSMETHODE ---

    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL
        ", [$class]);

        foreach ($users as $u) {
            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$u['importid']]);

            if (!$participant || !$participant['geburtsdatum']) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }
}
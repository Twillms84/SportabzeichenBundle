<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams/results', name: 'sportabzeichen_results_')]
final class ExamResultController extends AbstractPageController
{
    /**
     * Lädt alle verfügbaren Klassen für das Filter-Dropdown
     */
    private function loadClasses(Connection $conn): array
    {
        return $conn->fetchAllAssociative("
            SELECT DISTINCT auxinfo AS klasse
            FROM users
            WHERE auxinfo IS NOT NULL AND auxinfo <> ''
            ORDER BY auxinfo
        ");
    }

    /**
     * Übersicht der vorhandenen Prüfungen
     */
    #[Route('/', name: 'exams', methods: ['GET'])]
    public function examSelection(Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exams = $conn->fetchAllAssociative("
            SELECT id, exam_name, exam_year, exam_date
            FROM sportabzeichen_exams
            ORDER BY exam_year DESC, exam_date DESC
        ");

        return $this->render('@PulsRSportabzeichen/results/index.html.twig', [
            'exams' => $exams,
        ]);
    }

    /**
     * Die Ergebnistabelle für eine spezifische Prüfung
     */
    #[Route('/exam/{examId}', name: 'index', methods: ['GET'])]
    public function index(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$examId]);
        if (!$exam) {
            throw $this->createNotFoundException('Prüfung nicht gefunden.');
        }

        $selectedClass = $request->query->get('class');
        $classes = $this->loadClasses($conn);

        // Teilnehmer laden
        $sql = "
            SELECT ep.id AS ep_id, ep.age_year, p.import_id, p.geschlecht,
                   u.firstname AS vorname, u.lastname AS nachname, u.auxinfo AS klasse
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            JOIN users u ON u.importid = p.import_id
            WHERE ep.exam_id = ?
        ";
        
        $params = [$examId];
        if ($selectedClass) {
            $sql .= " AND u.auxinfo = ?";
            $params[] = $selectedClass;
        }
        $sql .= " ORDER BY u.auxinfo, u.lastname, u.firstname";

        $participants = $conn->fetchAllAssociative($sql, $params);

        // Gender-Mapping für Twig
        foreach ($participants as &$p) {
            $g = strtolower(trim((string) $p['geschlecht']));
            $p['gender'] = ($g === 'm' || $g === 'male' || $g === 'male') ? 'MALE' : 'FEMALE';
        }
        unset($p);

        // Disziplinen und Anforderungen laden
        $rows = $conn->fetchAllAssociative("
            SELECT d.id, d.name, d.kategorie, d.einheit, r.geschlecht, r.age_min, r.age_max, r.auswahlnummer
            FROM sportabzeichen_disciplines d
            JOIN sportabzeichen_requirements r ON r.discipline_id = d.id
            WHERE r.jahr = ?
            ORDER BY d.kategorie, r.auswahlnummer, d.name
        ", [$exam['exam_year']]);

        $disciplines = [];
        foreach ($rows as $row) {
            $disciplines[$row['kategorie']][] = $row;
        }

        // Bestehende Ergebnisse inkl. POINTS und STUFE laden (wichtig für Initial-Farben)
        $epIds = array_column($participants, 'ep_id');
        $results = [];
        if (!empty($epIds)) {
            $resultsRaw = $conn->fetchAllAssociative("
                SELECT ep_id, discipline_id, leistung, points, stufe 
                FROM sportabzeichen_exam_results
                WHERE ep_id IN (?)
            ", [$epIds], [Connection::PARAM_INT_ARRAY]);

            foreach ($resultsRaw as $r) {
                $results[$r['ep_id']][$r['discipline_id']] = $r;
            }
        }

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam'          => $exam,
            'participants'  => $participants,
            'disciplines'   => $disciplines,
            'results'       => $results,
            'classes'       => $classes,
            'selectedClass' => $selectedClass,
        ]);
    }

    /**
     * AJAX Autosave: Wird bei jeder Feldänderung aufgerufen
     */
    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request, Connection $conn): JsonResponse
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $content = json_decode($request->getContent(), true);
        $epId = $content['ep_id'] ?? null;
        $disciplineId = $content['discipline_id'] ?? null;
        $rawLeistung = $content['leistung'] ?? null;

        if (!$epId || !$disciplineId) {
            return new JsonResponse(['error' => 'Fehlende Daten'], 400);
        }

        $leistung = ($rawLeistung === '' || $rawLeistung === null) 
            ? null 
            : (float)str_replace(',', '.', (string)$rawLeistung);

        try {
            // DB-INSERT/UPDATE
            // Der Trigger 'trg_calculate_points' berechnet automatisch Points und Stufe
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung)
                VALUES (:ep, :disc, :leistung)
                ON CONFLICT (ep_id, discipline_id)
                DO UPDATE SET leistung = EXCLUDED.leistung
            ", ['ep' => (int)$epId, 'disc' => (int)$disciplineId, 'leistung' => $leistung]);

            // Frisch berechnete Werte vom Trigger aus der DB abholen
            $updated = $conn->fetchAssociative("
                SELECT points, stufe FROM sportabzeichen_exam_results 
                WHERE ep_id = ? AND discipline_id = ?
            ", [$epId, $disciplineId]);

            return new JsonResponse([
            'status' => 'ok',
            'points' => (int)$resultFromDb['points'], // Hier kommen die 3, 2 oder 1 her
            'medal'  => strtolower($resultFromDb['stufe'])
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fallback: Manueller Save-Button für alle Felder
     */
    #[Route('/exam/{examId}/save-all', name: 'save_all', methods: ['POST'])]
    public function saveAll(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
        $formData = $request->request->all('results');

        foreach ($formData as $epId => $categories) {
            foreach ($categories as $data) {
                $disciplineId = $data['discipline'] ?? null;
                if (!$disciplineId) continue;

                $rawLeistung = $data['leistung'] ?? '';
                $leistung = $rawLeistung === '' ? null : (float)str_replace(',', '.', (string)$rawLeistung);

                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung)
                    VALUES (:ep, :disc, :leistung)
                    ON CONFLICT (ep_id, discipline_id)
                    DO UPDATE SET leistung = EXCLUDED.leistung
                ", ['ep' => (int)$epId, 'disc' => (int)$disciplineId, 'leistung' => $leistung]);
            }
        }

        $this->addFlash('success', 'Alle Ergebnisse gespeichert.');
        return $this->redirectToRoute('sportabzeichen_results_index', ['examId' => $examId]);
    }
}
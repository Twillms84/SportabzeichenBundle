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
    private function loadClasses(Connection $conn): array
    {
        return $conn->fetchAllAssociative("
            SELECT DISTINCT auxinfo AS klasse
            FROM users
            WHERE auxinfo IS NOT NULL AND auxinfo <> ''
            ORDER BY auxinfo
        ");
    }

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
        $sql .= " ORDER BY u.lastname, u.firstname";

        $participants = $conn->fetchAllAssociative($sql, $params);

        foreach ($participants as &$p) {
            $g = strtolower(trim((string) $p['geschlecht']));
            $p['gender'] = ($g === 'm' || $g === 'male') ? 'MALE' : 'FEMALE';
        }
        unset($p);

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

        $resultsRaw = $conn->fetchAllAssociative("
            SELECT * FROM sportabzeichen_exam_results
            WHERE ep_id IN (SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?)
        ", [$examId]);

        $results = [];
        foreach ($resultsRaw as $r) {
            $results[$r['ep_id']][$r['discipline_id']] = $r;
        }

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam'          => $exam,
            'participants'  => $participants,
            'disciplines'   => $disciplines,
            'results'       => $results,
            'classes'       => $classes,
            'selectedClass' => $selectedClass,
            'nonce'         => $request->attributes->get('csp_nonce'),
        ]);
    }

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

    /**
     * AJAX Autosave mit Scoring
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

        $leistung = ($rawLeistung === '' || $rawLeistung === null) ? null : (float)str_replace(',', '.', (string)$rawLeistung);

        try {
            // 1. Leistung speichern
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung)
                VALUES (:ep, :disc, :leistung)
                ON CONFLICT (ep_id, discipline_id)
                DO UPDATE SET leistung = EXCLUDED.leistung
            ", ['ep' => (int)$epId, 'disc' => (int)$disciplineId, 'leistung' => $leistung]);

            // 2. Anforderungen UND Berechnungsart laden
            $scoreData = $conn->fetchAssociative("
                SELECT r.bronze, r.silber, r.gold, d.berechnungsart
                FROM sportabzeichen_requirements r
                JOIN sportabzeichen_disciplines d ON d.id = r.discipline_id
                JOIN sportabzeichen_exam_participants ep ON ep.id = :ep
                WHERE r.discipline_id = :disc 
                  AND r.jahr = (SELECT exam_year FROM sportabzeichen_exams WHERE id = ep.exam_id)
                  AND r.geschlecht = (
                      SELECT CASE WHEN p.geschlecht = 'MALE' THEN 'MALE' ELSE 'FEMALE' END 
                      FROM sportabzeichen_participants p 
                      JOIN sportabzeichen_exam_participants ep2 ON ep2.participant_id = p.id 
                      WHERE ep2.id = :ep
                  )
                  AND ep.age_year BETWEEN r.age_min AND r.age_max
            ", ['ep' => $epId, 'disc' => $disciplineId]);

            $points = 0;
            $medal = 'none';

            if ($scoreData && $leistung !== null) {
                $isGreater = (strtoupper($scoreData['berechnungsart']) === 'GREATER');
                
                // Hilfsfunktion für den Vergleich
                $check = function($val, $target) use ($isGreater) {
                    if ($target === null) return false;
                    return $isGreater ? ($val >= $target) : ($val <= $target);
                };

                if ($check($leistung, $scoreData['gold'])) { $points = 3; $medal = 'gold'; }
                elseif ($check($leistung, $scoreData['silber'])) { $points = 2; $medal = 'silver'; }
                elseif ($check($leistung, $scoreData['bronze'])) { $points = 1; $medal = 'bronze'; }
            }

            // 3. Stufe und Points in DB schreiben
            $conn->executeStatement("
                UPDATE sportabzeichen_exam_results 
                SET points = ?, stufe = ? 
                WHERE ep_id = ? AND discipline_id = ?
            ", [$points, strtoupper($medal), $epId, $disciplineId]);

            return new JsonResponse([
                'status' => 'ok',
                'points' => $points,
                'medal' => $medal
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
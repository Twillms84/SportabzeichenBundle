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

        /**
     * Zentrale Berechnungslogik für Punkte und Stufe
     */
    private function calculatePointsAndStufe(Connection $conn, int $epId, int $disciplineId, ?float $leistung): array
    {
        if ($leistung === null || $leistung <= 0) {
            return ['points' => 0, 'stufe' => 'NONE'];
        }

        $data = $conn->fetchAssociative("
            SELECT ep.age_year, ex.exam_year, p.geschlecht, d.berechnungsart
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            JOIN sportabzeichen_disciplines d ON d.id = ?
            WHERE ep.id = ?
        ", [$disciplineId, $epId]);

        if (!$data) return ['points' => 0, 'stufe' => 'NONE'];

        $gender = str_starts_with(strtolower((string)$data['geschlecht']), 'm') ? 'MALE' : 'FEMALE';
        
        $req = $conn->fetchAssociative("
            SELECT gold, silber, bronze 
            FROM sportabzeichen_requirements 
            WHERE discipline_id = ? AND jahr = ? AND geschlecht = ? AND ? BETWEEN age_min AND age_max
            LIMIT 1
        ", [$disciplineId, (int)$data['exam_year'], $gender, (int)$data['age_year']]);

        if (!$req) return ['points' => 0, 'stufe' => 'NONE'];

        $calcType = strtoupper((string)$data['berechnungsart']);
        $levels = [(float)$req['gold'], (float)$req['silber'], (float)$req['bronze']];
        $levels = array_filter($levels, fn($v) => $v > 0);
        if (empty($levels)) return ['points' => 0, 'stufe' => 'NONE'];

        $points = 0;
        $stufe = 'NONE';

        if ($calcType === 'SMALLER') {
            // ZEIT-LOGIK: Kleinster Wert = Gold, Größter Wert = Bronze
            sort($levels); 
            $goldVal   = $levels[0] ?? 0; // kleinster
            $silberVal = $levels[1] ?? $goldVal;
            $bronzeVal = $levels[2] ?? $silberVal; // größter

            if ($leistung <= $goldVal) { $points = 3; $stufe = 'GOLD'; }
            elseif ($leistung <= $silberVal) { $points = 2; $stufe = 'SILBER'; }
            elseif ($leistung <= $bronzeVal) { $points = 1; $stufe = 'BRONZE'; }
            
        } else {
            // WEITEN-LOGIK: Größter Wert = Gold, Kleinster Wert = Bronze
            rsort($levels); 
            $goldVal   = $levels[0] ?? 0; // größter
            $silberVal = $levels[1] ?? $goldVal;
            $bronzeVal = $levels[2] ?? $silberVal; // kleinster

            if ($leistung >= $goldVal) { $points = 3; $stufe = 'GOLD'; }
            elseif ($leistung >= $silberVal) { $points = 2; $stufe = 'SILBER'; }
            elseif ($leistung >= $bronzeVal) { $points = 1; $stufe = 'BRONZE'; }
        }

        return ['points' => $points, 'stufe' => $stufe];
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
        $sql .= " ORDER BY u.auxinfo, u.lastname, u.firstname";

        $participants = $conn->fetchAllAssociative($sql, $params);

        foreach ($participants as &$p) {
            $g = strtolower(trim((string) $p['geschlecht']));
            $p['gender'] = (str_starts_with($g, 'm')) ? 'MALE' : 'FEMALE';
        }
        unset($p);

        $rows = $conn->fetchAllAssociative("
            SELECT d.id, d.name, d.kategorie, d.einheit, 
                r.geschlecht, r.age_min, r.age_max, r.auswahlnummer,
                r.gold, r.silber, r.bronze r.schwimmnachweis  -- <--- DIESE DREI FELDER HINZUFÜGEN
            FROM sportabzeichen_disciplines d
            JOIN sportabzeichen_requirements r ON r.discipline_id = d.id
            WHERE r.jahr = ?
            ORDER BY d.kategorie, r.auswahlnummer, d.name
        ", [$exam['exam_year']]);

        $disciplines = [];
        foreach ($rows as $row) {
            $disciplines[$row['kategorie']][] = $row;
        }

        $epIds = array_column($participants, 'ep_id');
        $results = [];
        if (!empty($epIds)) {
            // Wir joinen die Requirements passend zum Teilnehmer (Alter/Geschlecht) und dem Prüfungsjahr
            $resultsRaw = $conn->fetchAllAssociative("
                SELECT 
                    res.ep_id, 
                    res.discipline_id, 
                    res.leistung, 
                    res.points, 
                    res.stufe,
                    req.schwimmnachweis, -- Das Flag aus den Requirements
                    d.kategorie
                FROM sportabzeichen_exam_results res
                JOIN sportabzeichen_exam_participants ep ON res.ep_id = ep.id
                JOIN sportabzeichen_participants p ON ep.participant_id = p.id
                JOIN sportabzeichen_disciplines d ON res.discipline_id = d.id
                JOIN sportabzeichen_requirements req ON (
                    req.discipline_id = res.discipline_id AND 
                    req.jahr = ? AND 
                    p.geschlecht = req.geschlecht AND 
                    ep.age_year BETWEEN req.age_min AND req.age_max
                )
                WHERE res.ep_id IN (?)
            ", [$exam['exam_year'], $epIds], [null, Connection::PARAM_INT_ARRAY]);

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

    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request, Connection $conn): JsonResponse
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $content = json_decode($request->getContent(), true);
        $epId = (int)($content['ep_id'] ?? 0);
        $disciplineId = (int)($content['discipline_id'] ?? 0);
        $rawLeistung = $content['leistung'] ?? null;

        if (!$epId || !$disciplineId) {
            return new JsonResponse(['error' => 'Fehlende Daten'], 400);
        }

        $leistung = ($rawLeistung === '' || $rawLeistung === null) 
            ? null 
            : (float)str_replace(',', '.', (string)$rawLeistung);

        // PHP-Berechnung statt SQL-Trigger
        $calc = $this->calculatePointsAndStufe($conn, $epId, $disciplineId, $leistung);

        try {
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung, points, stufe)
                VALUES (:ep, :disc, :leistung, :points, :stufe)
                ON CONFLICT (ep_id, discipline_id)
                DO UPDATE SET 
                    leistung = EXCLUDED.leistung,
                    points = EXCLUDED.points,
                    stufe = EXCLUDED.stufe
            ", [
                'ep'       => $epId, 
                'disc'     => $disciplineId, 
                'leistung' => $leistung,
                'points'   => $calc['points'],
                'stufe'    => $calc['stufe']
            ]);

            return new JsonResponse([
                'status' => 'ok',
                'points' => $calc['points'],
                'medal'  => strtolower($calc['stufe'])
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/exam/{examId}/save-all', name: 'save_all', methods: ['POST'])]
    public function saveAll(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
        $formData = $request->request->all('results');

        foreach ($formData as $epId => $categories) {
            foreach ($categories as $data) {
                $disciplineId = (int)($data['discipline'] ?? 0);
                if (!$disciplineId) continue;
                
                $rawLeistung = $data['leistung'] ?? '';
                $leistung = $rawLeistung === '' ? null : (float)str_replace(',', '.', (string)$rawLeistung);

                // Auch hier: PHP-Berechnung nutzen
                $calc = $this->calculatePointsAndStufe($conn, (int)$epId, $disciplineId, $leistung);

                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung, points, stufe)
                    VALUES (:ep, :disc, :leistung, :points, :stufe)
                    ON CONFLICT (ep_id, discipline_id)
                    DO UPDATE SET 
                        leistung = EXCLUDED.leistung,
                        points = EXCLUDED.points,
                        stufe = EXCLUDED.stufe
                ", [
                    'ep'       => (int)$epId, 
                    'disc'     => $disciplineId, 
                    'leistung' => $leistung,
                    'points'   => $calc['points'],
                    'stufe'    => $calc['stufe']
                ]);
            }
        }

        $this->addFlash('success', 'Alle Ergebnisse gespeichert und berechnet.');
        return $this->redirectToRoute('sportabzeichen_results_index', ['examId' => $examId]);
    }
}
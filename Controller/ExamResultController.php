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

    private function getSwimmingStatus(Connection $conn, int $participantId): bool
    {
        return (bool)$conn->fetchOne("
            SELECT EXISTS (
                SELECT 1 FROM sportabzeichen_swimming_proofs 
                WHERE participant_id = ? AND valid_until >= CURRENT_DATE
            )
        ", [$participantId]);
    }

    #[Route('/', name: 'exams', methods: ['GET'])]
    public function examSelection(Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
        $exams = $conn->fetchAllAssociative("SELECT id, exam_name, exam_year, exam_date FROM sportabzeichen_exams ORDER BY exam_year DESC");
        return $this->render('@PulsRSportabzeichen/results/index.html.twig', ['exams' => $exams]);
    }

    #[Route('/exam/{examId}', name: 'index', methods: ['GET'])]
    public function index(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$examId]);
        if (!$exam) throw $this->createNotFoundException();

        $selectedClass = $request->query->get('class');
        $classes = $this->loadClasses($conn);

        $sql = "
            SELECT ep.id AS ep_id, ep.participant_id, ep.age_year, ep.total_points, ep.final_medal,
                   p.geschlecht, u.firstname AS vorname, u.lastname AS nachname, u.auxinfo AS klasse,
                   EXISTS(SELECT 1 FROM sportabzeichen_swimming_proofs sp 
                          WHERE sp.participant_id = ep.participant_id AND sp.valid_until >= CURRENT_DATE) as has_swimming
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            JOIN users u ON u.importid = p.import_id
            WHERE ep.exam_id = ?
        ";
        
        $params = [$examId];
        if ($selectedClass) { $sql .= " AND u.auxinfo = ?"; $params[] = $selectedClass; }
        $participants = $conn->fetchAllAssociative($sql . " ORDER BY u.lastname, u.firstname", $params);

        $rows = $conn->fetchAllAssociative("
            SELECT d.id, d.name, d.kategorie, d.einheit, r.geschlecht, r.age_min, r.age_max, r.gold, r.silber, r.bronze
            FROM sportabzeichen_disciplines d
            JOIN sportabzeichen_requirements r ON r.discipline_id = d.id
            WHERE r.jahr = ? ORDER BY d.kategorie, r.auswahlnummer
        ", [$exam['exam_year']]);

        $disciplines = [];
        foreach ($rows as $row) { $disciplines[$row['kategorie']][] = $row; }

        $epIds = array_column($participants, 'ep_id');
        $results = [];
        if (!empty($epIds)) {
            $resRaw = $conn->fetchAllAssociative("
                SELECT res.*, d.kategorie FROM sportabzeichen_exam_results res 
                JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id 
                WHERE res.ep_id IN (?)", [$epIds], [Connection::PARAM_INT_ARRAY]);
            foreach ($resRaw as $r) { $results[$r['ep_id']][$r['discipline_id']] = $r; }
        }

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam' => $exam, 'participants' => $participants, 'disciplines' => $disciplines, 
            'results' => $results, 'classes' => $classes, 'selectedClass' => $selectedClass
        ]);
    }

    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request, Connection $conn): JsonResponse
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
        $content = json_decode($request->getContent(), true);
        $epId = (int)($content['ep_id'] ?? 0);
        $disciplineId = (int)($content['discipline_id'] ?? 0);
        $leistung = $content['leistung'] === '' ? null : (float)str_replace(',', '.', (string)$content['leistung']);

        try {
            // 1. Speichern (Trigger erledigt Punkte/Stufe/Gesamtsumme)
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung)
                VALUES (?, ?, ?) ON CONFLICT (ep_id, discipline_id) 
                DO UPDATE SET leistung = EXCLUDED.leistung
            ", [$epId, $disciplineId, $leistung]);

            // 2. Schwimmnachweis-Logik (DOSB)
            $res = $conn->fetchAssociative("
                SELECT res.stufe, d.kategorie, ep.participant_id, ep.age_year, ex.exam_year
                FROM sportabzeichen_exam_results res
                JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id
                JOIN sportabzeichen_exam_participants ep ON ep.id = res.ep_id
                JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
                WHERE res.ep_id = ? AND res.discipline_id = ?
            ", [$epId, $disciplineId]);

            if ($res && in_array(strtoupper($res['kategorie']), ['SWIMMING', 'SCHWIMMEN']) && $res['stufe'] !== 'NONE') {
                $validUntil = ($res['age_year'] <= 17) 
                    ? ($res['exam_year'] + (18 - $res['age_year'])) . "-12-31" 
                    : ($res['exam_year'] + 4) . "-12-31";

                $conn->executeStatement("
                    INSERT INTO sportabzeichen_swimming_proofs (participant_id, confirmed_at, valid_until, requirement_met_via)
                    VALUES (?, CURRENT_DATE, ?, 'DISCIPLINE')
                    ON CONFLICT (participant_id) DO UPDATE SET valid_until = EXCLUDED.valid_until
                ", [$res['participant_id'], $validUntil]);
            }

            // 3. Neue berechnete Werte laden
            $updated = $conn->fetchAssociative("
                SELECT r.points, r.stufe, ep.total_points, ep.final_medal,
                EXISTS(SELECT 1 FROM sportabzeichen_swimming_proofs sp WHERE sp.participant_id = ep.participant_id AND sp.valid_until >= CURRENT_DATE) as has_swimming
                FROM sportabzeichen_exam_results r
                JOIN sportabzeichen_exam_participants ep ON ep.id = r.ep_id
                WHERE r.ep_id = ? AND r.discipline_id = ?
            ", [$epId, $disciplineId]);

            return new JsonResponse([
                'status' => 'ok',
                'points' => $updated['points'] ?? 0,
                'medal' => strtolower($updated['stufe'] ?? 'none'),
                'total_points' => $updated['total_points'] ?? 0,
                'final_medal' => strtolower($updated['final_medal'] ?? 'none'),
                'has_swimming' => (bool)$updated['has_swimming']
            ]);

        } catch (\Throwable $e) { return new JsonResponse(['error' => $e->getMessage()], 500); }
    }
}
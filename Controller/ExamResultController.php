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
            SELECT d.id, d.name, d.kategorie, d.einheit, r.geschlecht, r.age_min, r.age_max, r.gold, r.silber, r.bronze, r.schwimmnachweis
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
        $leistungInput = trim((string)($content['leistung'] ?? ''));
        $leistung = ($leistungInput === '') ? null : (float)str_replace(',', '.', $leistungInput);

        try {
            $pData = $conn->fetchAssociative("
                SELECT ep.participant_id, ep.age_year, ex.exam_year, p.geschlecht 
                FROM sportabzeichen_exam_participants ep
                JOIN sportabzeichen_participants p ON p.id = ep.participant_id
                JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
                WHERE ep.id = ?
            ", [$epId]);

            if (!$pData) return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
            $gender = (str_starts_with(strtoupper($pData['geschlecht'] ?? ''), 'M')) ? 'MALE' : 'FEMALE';

            $req = $conn->fetchAssociative("
                SELECT r.*, d.einheit, d.kategorie, d.name as discipline_name
                FROM sportabzeichen_requirements r
                JOIN sportabzeichen_disciplines d ON d.id = r.discipline_id
                WHERE r.discipline_id = ? AND r.jahr = ? AND r.geschlecht = ? 
                  AND ? BETWEEN r.age_min AND r.age_max
            ", [$disciplineId, (int)$pData['exam_year'], $gender, (int)$pData['age_year']]);

            $points = 0;
            $stufe = 'none';

            // --- START DER PUNKTEBERECHNUNG ---
            if ($req && $leistung !== null && $leistung > 0) {
                $einheit = strtoupper($req['einheit'] ?? '');
                
                // Zeit-Disziplinen erkennen (kleiner ist besser)
                $lowerIsBetter = (
                    str_contains($einheit, 'MIN') || 
                    str_contains($einheit, 'SEK') || 
                    str_contains($einheit, 'TIME') ||
                    str_contains($einheit, 'ZEIT')
                );

                $valGold = (float)str_replace(',', '.', (string)$req['gold']);
                $valSilber = (float)str_replace(',', '.', (string)$req['silber']);
                $valBronze = (float)str_replace(',', '.', (string)$req['bronze']);

                if ($lowerIsBetter) {
                    if ($leistung <= $valGold) { $points = 3; $stufe = 'gold'; }
                    elseif ($leistung <= $valSilber) { $points = 2; $stufe = 'silber'; }
                    elseif ($leistung <= $valBronze) { $points = 1; $stufe = 'bronze'; }
                } else {
                    if ($leistung >= $valGold) { $points = 3; $stufe = 'gold'; }
                    elseif ($leistung >= $valSilber) { $points = 2; $stufe = 'silber'; }
                    elseif ($leistung >= $valBronze) { $points = 1; $stufe = 'bronze'; }
                }
            }
            // --- ENDE DER PUNKTEBERECHNUNG ---

            // 1. Alte Ergebnisse derselben Kategorie entfernen (Nur 1 Disziplin pro Kat)
            if ($req) {
                $conn->executeStatement("
                    DELETE FROM sportabzeichen_exam_results 
                    WHERE ep_id = ? 
                    AND discipline_id != ? 
                    AND discipline_id IN (
                        SELECT id FROM sportabzeichen_disciplines WHERE kategorie = ?
                    )
                ", [$epId, $disciplineId, $req['kategorie']]);
            }

            // 2. Aktuelles Ergebnis speichern
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung, points, stufe)
                VALUES (?, ?, ?, ?, ?) ON CONFLICT (ep_id, discipline_id) 
                DO UPDATE SET leistung = EXCLUDED.leistung, points = EXCLUDED.points, stufe = EXCLUDED.stufe
            ", [$epId, $disciplineId, $leistung, $points, $stufe]);

            // 3. Schwimmnachweis-Logik
            $isSwimmingDiscipline = (
                ($req['schwimmnachweis'] ?? false) === true || 
                str_contains(strtoupper($req['kategorie'] ?? ''), 'SCHWIMM') ||
                str_contains(strtoupper($req['discipline_name'] ?? ''), 'SCHWIMM')
            );

            if ($isSwimmingDiscipline && $points > 0) {
                $validUntilYear = ($pData['age_year'] <= 17) ? ($pData['exam_year'] + (18 - $pData['age_year'])) : ($pData['exam_year'] + 4);
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_swimming_proofs (participant_id, confirmed_at, valid_until, requirement_met_via)
                    VALUES (?, CURRENT_DATE, ?, ?)
                    ON CONFLICT (participant_id) DO UPDATE SET 
                        valid_until = EXCLUDED.valid_until,
                        confirmed_at = CURRENT_DATE,
                        requirement_met_via = EXCLUDED.requirement_met_via
                ", [(int)$pData['participant_id'], $validUntilYear . "-12-31", 'DISCIPLINE:' . $disciplineId]);
            }

            // 4. Zusammenfassung aktualisieren
            $this->updateParticipantSummary($epId, (int)$pData['participant_id'], $conn);

            return new JsonResponse(['status' => 'ok', 'points' => $points, 'stufe' => $stufe]);
        } catch (\Throwable $e) { 
            return new JsonResponse(['error' => $e->getMessage()], 500); 
        }
    }

    #[Route('/exam/result/delete', name: 'exam_result_delete', methods: ['POST'])]
    public function deleteResult(Request $request, Connection $conn): JsonResponse
    {
        $content = json_decode($request->getContent(), true);
        $epId = (int)$content['ep_id'];
        $disciplineId = (int)$content['discipline_id'];

        $participantId = $conn->fetchOne("SELECT participant_id FROM sportabzeichen_exam_participants WHERE id = ?", [$epId]);

        // 1. Ergebnis löschen
        $conn->executeStatement("DELETE FROM sportabzeichen_exam_results WHERE ep_id = ? AND discipline_id = ?", [$epId, $disciplineId]);

        // 2. Schwimmnachweis löschen, FALLS er von genau dieser Disziplin stammte
        $conn->executeStatement("
            DELETE FROM sportabzeichen_swimming_proofs 
            WHERE participant_id = ? AND requirement_met_via = ?
        ", [$participantId, 'DISCIPLINE:' . $disciplineId]);

        // 3. Gesamtstatus neu berechnen
        $this->updateParticipantSummary($epId, (int)$participantId, $conn);

        return new JsonResponse(['status' => 'ok']);
    }

    private function updateParticipantSummary(int $epId, int $participantId, Connection $conn): void 
    {
        // Wir summieren nur die 4 Kernkategorien
        $totalPoints = (int)$conn->fetchOne("
            SELECT SUM(max_p) FROM (
                SELECT MAX(res.points) as max_p 
                FROM sportabzeichen_exam_results res
                JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id
                WHERE res.ep_id = ? AND d.kategorie IN ('Ausdauer','Kraft','Schnelligkeit','Koordination')
                GROUP BY d.kategorie
            ) as sub
        ", [$epId]);

        $hasSwimming = (bool)$conn->fetchOne("
            SELECT 1 FROM sportabzeichen_swimming_proofs 
            WHERE participant_id = ? AND valid_until >= CURRENT_DATE
        ", [$participantId]);

        $medal = 'none';
        if ($hasSwimming) {
            if ($totalPoints >= 11) $medal = 'gold';
            elseif ($totalPoints >= 8) $medal = 'silber';
            elseif ($totalPoints >= 4) $medal = 'bronze';
        }

        $conn->executeStatement("UPDATE sportabzeichen_exam_participants SET total_points = ?, final_medal = ? WHERE id = ?", [$totalPoints, $medal, $epId]);
    }
}
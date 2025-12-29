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

    Das sind zwei sehr wichtige Punkte. Das CSS-Problem liegt an einer kleinen Unstimmigkeit zwischen dem Variablennamen im PHP (stufe) und im JS (medal). Das Schwimmnachweis-Problem liegt daran, dass wir ihn zwar setzen, aber beim Wechsel der Disziplin (oder bei 0 Punkten) nicht wieder löschen.

Hier ist die Korrektur.

1. PHP Controller (ExamResultController.php)
Ich habe die Methode saveExamResult angepasst. Die wichtige Änderung: Am Ende der Methode wird jetzt "aufgeräumt". Wir prüfen, ob für diesen Teilnehmer noch Schwimmnachweise existieren, die auf Disziplinen basieren, für die es gar keine gültigen Punkte mehr gibt (z.B. weil man von Schwimmen auf Laufen gewechselt hat).

PHP

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
            // 1. Teilnehmer-Stammdaten
            $pData = $conn->fetchAssociative("
                SELECT ep.participant_id, ep.age_year, ex.exam_year, p.geschlecht 
                FROM sportabzeichen_exam_participants ep
                JOIN sportabzeichen_participants p ON p.id = ep.participant_id
                JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
                WHERE ep.id = ?
            ", [$epId]);

            if (!$pData) return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);

            $gender = (str_starts_with(strtoupper($pData['geschlecht'] ?? ''), 'M')) ? 'MALE' : 'FEMALE';
            
            // 2. Anforderung & Berechnungsart
            $req = $conn->fetchAssociative("
                SELECT r.*, d.einheit, d.kategorie, d.berechnungsart
                FROM sportabzeichen_requirements r
                JOIN sportabzeichen_disciplines d ON d.id = r.discipline_id
                WHERE r.discipline_id = ? AND r.jahr = ? AND r.geschlecht = ? 
                  AND ? BETWEEN r.age_min AND r.age_max
            ", [$disciplineId, (int)$pData['exam_year'], $gender, (int)$pData['age_year']]);

            $points = 0;
            $stufe = 'none';

            // 3. Punkte berechnen (SMALLER / BIGGER Logik)
            if ($req && $leistung !== null && $leistung > 0) {
                $direction = strtoupper($req['berechnungsart'] ?? 'BIGGER'); 
                $vGold = (float)str_replace(',', '.', (string)$req['gold']);
                $vSilber = (float)str_replace(',', '.', (string)$req['silber']);
                $vBronze = (float)str_replace(',', '.', (string)$req['bronze']);

                if ($direction === 'SMALLER') {
                    if ($leistung <= $vGold) { $points = 3; $stufe = 'gold'; }
                    elseif ($leistung <= $vSilber) { $points = 2; $stufe = 'silber'; }
                    elseif ($leistung <= $vBronze) { $points = 1; $stufe = 'bronze'; }
                } else {
                    if ($leistung >= $vGold) { $points = 3; $stufe = 'gold'; }
                    elseif ($leistung >= $vSilber) { $points = 2; $stufe = 'silber'; }
                    elseif ($leistung >= $vBronze) { $points = 1; $stufe = 'bronze'; }
                }
            }

            // 4. Alte Ergebnisse dieser Kategorie löschen
            // (Damit löschen wir auch das Ergebnis einer Schwimm-Disziplin, falls man auf Laufen wechselt)
            if ($req) {
                $conn->executeStatement("
                    DELETE FROM sportabzeichen_exam_results 
                    WHERE ep_id = ? AND discipline_id != ? 
                    AND discipline_id IN (SELECT id FROM sportabzeichen_disciplines WHERE kategorie = ?)
                ", [$epId, $disciplineId, $req['kategorie']]);
            }

            // 5. Neues Ergebnis speichern
            if ($leistung === null || $leistung <= 0) {
                $conn->executeStatement("DELETE FROM sportabzeichen_exam_results WHERE ep_id = ? AND discipline_id = ?", [$epId, $disciplineId]);
            } else {
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung, points, stufe) 
                    VALUES (?, ?, ?, ?, ?) 
                    ON CONFLICT (ep_id, discipline_id) DO UPDATE SET leistung = EXCLUDED.leistung, points = EXCLUDED.points, stufe = EXCLUDED.stufe
                ", [$epId, $disciplineId, $leistung, $points, $stufe]);
            }

            // 6. SCHWIMMNACHWEIS LOGIK (Setzen UND Aufräumen)
            
            // A) Setzen: Wenn Punkte > 0 und es eine Schwimm-Disziplin ist
            if ($req && (($req['schwimmnachweis'] ?? false) || str_contains(strtoupper($req['kategorie']), 'SCHWIMM')) && $points > 0) {
                $validUntil = ($pData['age_year'] <= 17) ? ($pData['exam_year'] + (18 - $pData['age_year'])) : ($pData['exam_year'] + 4);
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_swimming_proofs (participant_id, confirmed_at, valid_until, requirement_met_via) 
                    VALUES (?, CURRENT_DATE, ?, ?) 
                    ON CONFLICT (participant_id) DO UPDATE SET valid_until = EXCLUDED.valid_until, requirement_met_via = EXCLUDED.requirement_met_via
                ", [(int)$pData['participant_id'], $validUntil . "-12-31", 'DISCIPLINE:' . $disciplineId]);
            }
            
            // B) Aufräumen: Lösche Schwimmnachweise, die auf einer Disziplin basieren ("DISCIPLINE:ID"),
            //    für die es kein gültiges Ergebnis mit Punkten > 0 mehr gibt.
            //    Das passiert, wenn man von Schwimmen auf Laufen wechselt (Schritt 4 löscht das Ergebnis) 
            //    oder wenn die Leistung auf 0 gesetzt wird (Schritt 5).
            $conn->executeStatement("
                DELETE FROM sportabzeichen_swimming_proofs
                WHERE participant_id = ? 
                AND requirement_met_via LIKE 'DISCIPLINE:%'
                AND split_part(requirement_met_via, ':', 2)::int NOT IN (
                    SELECT discipline_id FROM sportabzeichen_exam_results 
                    WHERE ep_id = ? AND points > 0
                )
            ", [(int)$pData['participant_id'], $epId]);


            // 7. Zusammenfassung berechnen
            $summary = $this->updateParticipantSummary($epId, (int)$pData['participant_id'], $conn);

            return new JsonResponse([
                'status' => 'ok',
                'points' => $points,
                'stufe' => $stufe, // 'none', 'bronze', 'silber', 'gold'
                'kategorie' => $req['kategorie'] ?? '',
                'total_points' => $summary['total_points'],
                'final_medal' => $summary['final_medal'],
                'has_swimming' => $summary['has_swimming']
            ]);

        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    private function updateParticipantSummary(int $epId, int $participantId, Connection $conn): array 
    {
        $totalPoints = (int)$conn->fetchOne("
            SELECT SUM(max_p) FROM (
                SELECT MAX(res.points) as max_p 
                FROM sportabzeichen_exam_results res
                JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id
                WHERE res.ep_id = ? AND d.kategorie IN ('Ausdauer','Kraft','Schnelligkeit','Koordination')
                GROUP BY d.kategorie
            ) as sub
        ", [$epId]);

        $hasSwimming = (bool)$conn->fetchOne("SELECT 1 FROM sportabzeichen_swimming_proofs WHERE participant_id = ? AND valid_until >= CURRENT_DATE", [$participantId]);

        $medal = 'none';
        if ($hasSwimming) {
            if ($totalPoints >= 11) $medal = 'gold';
            elseif ($totalPoints >= 8) $medal = 'silber';
            elseif ($totalPoints >= 4) $medal = 'bronze';
        }

        $conn->executeStatement("UPDATE sportabzeichen_exam_participants SET total_points = ?, final_medal = ? WHERE id = ?", [$totalPoints, $medal, $epId]);

        return ['total_points' => $totalPoints, 'final_medal' => $medal, 'has_swimming' => $hasSwimming];
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

}
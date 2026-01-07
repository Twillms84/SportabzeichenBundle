<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
use PulsR\SportabzeichenBundle\Entity\ExamResult;
use PulsR\SportabzeichenBundle\Entity\Requirement;
use PulsR\SportabzeichenBundle\Entity\SwimmingProof;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Doctrine\DBAL\Connection;

#[Route('/sportabzeichen/exams/results', name: 'sportabzeichen_results_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class ExamResultController extends AbstractPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    #[Route('/', name: 'exams', methods: ['GET'])]
    public function examSelection(): Response
    {
        // ANPASSUNG: Property heißt jetzt 'year' in der Entity
        $exams = $this->em->getRepository(Exam::class)->findBy([], ['year' => 'DESC']);
        return $this->render('@PulsRSportabzeichen/results/index.html.twig', ['exams' => $exams]);
    }

    #[Route('/exam/{id}', name: 'index', methods: ['GET'])]
    public function index(Exam $exam, Request $request): Response
    {
        $selectedClass = $request->query->get('class');

        $qb = $this->em->createQueryBuilder();
        $qb->select('ep', 'p', 'u', 'sp', 'res', 'd')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->leftJoin('p.swimmingProofs', 'sp')
            ->leftJoin('ep.results', 'res')
            ->leftJoin('res.discipline', 'd')
            ->where('ep.exam = :exam')
            ->setParameter('exam', $exam)
            ->orderBy('u.lastname', 'ASC')
            ->addOrderBy('u.firstname', 'ASC');

        if ($selectedClass) {
            $qb->andWhere('u.auxinfo = :class')->setParameter('class', $selectedClass);
        }

        /** @var ExamParticipant[] $examParticipants */
        $examParticipants = $qb->getQuery()->getResult();

        // 2. Daten aufbereiten für Twig
        $participantsData = [];
        $resultsData = [];

        foreach ($examParticipants as $ep) {
            $hasSwimming = false;
            $swimmingExpiry = null;
            $today = new \DateTime();
            
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getValidUntil() >= $today) {
                    $hasSwimming = true;
                    if ($swimmingExpiry === null || $proof->getValidUntil() > $swimmingExpiry) {
                        $swimmingExpiry = $proof->getValidUntil();
                    }
                }
            }

            foreach ($ep->getResults() as $res) {
                $resultsData[$ep->getId()][$res->getDiscipline()->getId()] = [
                    'leistung' => $res->getLeistung(),
                    'points' => $res->getPoints(),
                    'stufe' => $res->getPoints() === 3 ? 'gold' : ($res->getPoints() === 2 ? 'silber' : 'bronze'),
                    'category' => $res->getDiscipline()->getCategory()
                ];
            }
            
            $participantsData[] = [
                'entity' => $ep,
                'ep_id' => $ep->getId(),
                'vorname' => $ep->getParticipant()->getUser()->getFirstname(),
                'nachname' => $ep->getParticipant()->getUser()->getLastname(),
                'klasse' => $ep->getParticipant()->getUser()->getAuxinfo(),
                'geschlecht' => $ep->getParticipant()->getGender(),
                'age_year' => $ep->getAgeYear(), 
                'total_points' => $ep->getTotalPoints(),
                'final_medal' => $ep->getFinalMedal(),
                'has_swimming' => $hasSwimming,
                'swimming_expiry' => $swimmingExpiry
            ];
        }

        // 3. Anforderungen/Disziplinen laden
        // Wir laden Requirements UND Disziplinen flach als Array
        $requirementsData = $this->em->createQueryBuilder()
            ->select('r', 'd')
            ->from(Requirement::class, 'r')
            ->join('r.discipline', 'd')
            ->where('r.year = :year') 
            ->setParameter('year', $exam->getYear()) 
            ->orderBy('d.category', 'ASC')
            ->addOrderBy('r.selectionId', 'ASC') 
            ->getQuery()
            ->getArrayResult(); 

        $disciplines = [];
        
        // HIER IST DER FIX: Struktur so bauen, wie Twig sie erwartet
        foreach ($requirementsData as $reqRow) {
            // $reqRow enthält die Requirement-Daten UND unter dem Key 'discipline' die Disziplin-Daten
            $d = $reqRow['discipline'];
            
            $cat = $d['category'];
            $dId = $d['id'];
            
            if (!isset($disciplines[$cat])) {
                 $disciplines[$cat] = [];
            }

            // Wenn wir diese Disziplin in dieser Kategorie noch nicht haben -> anlegen
            if (!isset($disciplines[$cat][$dId])) {
                $disciplines[$cat][$dId] = $d;
                // WICHTIG: Wir initialisieren ein leeres Array für die Requirements
                $disciplines[$cat][$dId]['requirements'] = [];
            }
            
            // Jetzt fügen wir das aktuelle Requirement zu dieser Disziplin hinzu
            // Twig greift später darauf zu mit: discipline.requirements
            $disciplines[$cat][$dId]['requirements'][] = $reqRow;
        }
        
        // Indizes glätten (optional, aber sauberer für Twig loop)
        foreach($disciplines as $kat => $vals) {
            $disciplines[$kat] = array_values($vals);
        }

        $classes = $this->em->getConnection()->fetchFirstColumn("SELECT DISTINCT auxinfo FROM users WHERE auxinfo != '' ORDER BY auxinfo");

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam' => $exam,
            'participants' => $participantsData,
            'disciplines' => $disciplines,
            'results' => $resultsData,
            'classes' => $classes,
            'selectedClass' => $selectedClass
        ]);
    }

    #[Route('/exam/discipline/save', name: 'exam_discipline_save', methods: ['POST'])]
    public function saveExamDiscipline(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ep = $this->em->getRepository(ExamParticipant::class)->createQueryBuilder('ep')
            ->where('ep.id = :id')->setParameter('id', (int)($data['ep_id'] ?? 0))
            ->getQuery()->getOneOrNullResult();

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));

        if (!$ep || !$discipline) return new JsonResponse(['error' => 'Daten unvollständig'], 404);

        $currentCat = $discipline->getCategory();
        $leistung = ($data['leistung'] !== '') ? (float)str_replace(',', '.', (string)$data['leistung']) : null;

        // 1. Alle ANDEREN Ergebnisse dieser Kategorie löschen (Wechsel-Logik)
        foreach ($ep->getResults() as $existingRes) {
            if ($existingRes->getDiscipline()->getCategory() === $currentCat) {
                $this->em->remove($existingRes);
            }
        }
        $this->em->flush();

        // 2. Neues Ergebnis anlegen, falls Leistung vorhanden oder VERBAND
        $points = 0;
        $stufe = 'none';
        $calc = strtoupper($discipline->getBerechnungsart() ?? '');

        if ($calc === 'VERBAND' || ($leistung !== null && $leistung > 0)) {
            $res = new ExamResult();
            $res->setExamParticipant($ep);
            $res->setDiscipline($discipline);
            
            if ($calc === 'VERBAND') {
                $points = 3;
                $stufe = 'gold';
                $res->setLeistung(1.0); // Dummy
            } else {
                // Hier deine findMatchingRequirement Logik aufrufen um Punkte für $leistung zu holen
                $pointsData = $this->calculatePoints($ep, $discipline, $leistung);
                $points = $pointsData['points'];
                $stufe = $pointsData['stufe'];
                $res->setLeistung($leistung);
            }

            $res->setPoints($points);
            $res->setStufe($stufe);
            $this->em->persist($res);
            $this->em->flush();
        }

        return $this->generateSummaryResponse($ep, $points, $stufe, $currentCat);
    }

    /**
     * Wird aufgerufen, wenn die Leistung im Textfeld geändert wird.
     */
    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)($data['ep_id'] ?? 0));
        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));

        if (!$ep || !$discipline) return new JsonResponse(['error' => 'Daten unvollständig'], 404);

        $leistungInput = trim((string)($data['leistung'] ?? ''));
        $leistung = ($leistungInput === '') ? null : (float)str_replace(',', '.', $leistungInput);

        $result = $this->em->getRepository(ExamResult::class)->findOneBy([
            'examParticipant' => $ep,
            'discipline' => $discipline
        ]);

        if ($leistung === null) {
            if ($result) $this->em->remove($result);
            $points = 0;
            $stufe = 'none';
        } else {
            if (!$result) {
                $result = new ExamResult();
                $result->setExamParticipant($ep);
                $result->setDiscipline($discipline);
                $this->em->persist($result);
            }
            $pointsData = $this->calculatePoints($ep, $discipline, $leistung);
            $points = $pointsData['points'];
            $stufe = $pointsData['stufe'];

            $result->setLeistung($leistung);
            $result->setPoints($points);
            $result->setStufe($stufe);
        }

        $this->em->flush();
        return $this->generateSummaryResponse($ep, $points, $stufe, $discipline->getCategory());
    }

    private function generateSummaryResponse(ExamParticipant $ep, int $points, string $stufe, string $category): JsonResponse 
    {
        // 1. Schwimm-Logik triggern
        // Wir suchen die Disziplin, die gerade gespeichert wurde
        $results = $ep->getResults();
        foreach ($results as $res) {
            if ($res->getDiscipline()->getCategory() === $category) {
                $this->updateSwimmingProof($ep, $res->getDiscipline(), $res->getPoints());
                break;
            }
        }
        
        $this->em->flush();

        // 2. Gesamtergebnis (Medaille/Punkte) berechnen
        $summary = $this->calculateSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'points' => $points,
            'stufe' => $stufe,
            'category' => $category,
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal'],
            'has_swimming' => $summary['has_swimming']
        ]);
    }

    private function calculatePoints(ExamParticipant $ep, Discipline $discipline, ?float $leistung): array 
    {
        if ($leistung === null || $leistung <= 0) return ['points' => 0, 'stufe' => 'none'];
        
        $gender = (str_starts_with(strtoupper($ep->getParticipant()->getGender() ?? 'W'), 'M')) ? 'MALE' : 'FEMALE';
        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement(
            $discipline, (int)$ep->getExam()->getYear(), $gender, (int)$ep->getAgeYear()
        );

        if (!$req) return ['points' => 0, 'stufe' => 'none'];

        $calc = strtoupper($discipline->getBerechnungsart() ?? 'BIGGER');
        $vG = (float)$req->getGold(); $vS = (float)$req->getSilver(); $vB = (float)$req->getBronze();
        $p = 0; $s = 'none';

        if ($calc === 'SMALLER') {
            if ($leistung <= $vG && $vG > 0) { $p = 3; $s = 'gold'; }
            elseif ($leistung <= $vS && $vS > 0) { $p = 2; $s = 'silber'; }
            elseif ($leistung <= $vB && $vB > 0) { $p = 1; $s = 'bronze'; }
        } else {
            if ($leistung >= $vG) { $p = 3; $s = 'gold'; }
            elseif ($leistung >= $vS) { $p = 2; $s = 'silber'; }
            elseif ($leistung >= $vB) { $p = 1; $s = 'bronze'; }
        }
        return ['points' => $p, 'stufe' => $s];
    }

    private function generateSummaryResponse(ExamParticipant $ep, int $points, string $stufe, string $cat): JsonResponse 
    {
        $this->updateSwimmingProof($ep, $this->em->getRepository(Discipline::class)->findOneBy(['category' => $cat]), $points);
        $this->em->flush();
        $summary = $this->calculateSummary($ep);

        return new JsonResponse([
            'status' => 'ok', 'points' => $points, 'stufe' => $stufe, 'category' => $cat,
            'total_points' => $summary['total'], 'final_medal' => $summary['medal'], 'has_swimming' => $summary['has_swimming']
        ]);
    }
    private function updateSwimmingProof(ExamParticipant $ep, Discipline $disc, int $points): void
    {
        $year = $ep->getExam()->getYear(); 
        $p = $ep->getParticipant();

        // Wenn Punkte > 0 und es eine Schwimmdisziplin ist -> Nachweis eintragen
        if ($points > 0 && $disc->isSwimmingCategory()) {
            $proof = null;
            foreach ($p->getSwimmingProofs() as $sp) {
                if ($sp->getExamYear() === $year) {
                    $proof = $sp; 
                    break;
                }
            }
            
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($p);
                $proof->setExamYear($year);
                $this->em->persist($proof);
            }
            
            $validYear = ($ep->getAgeYear() <= 17) ? ($year + (18 - $ep->getAgeYear())) : ($year + 4);
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validYear-12-31"));
            $proof->setRequirementMetVia('DISCIPLINE:' . $disc->getId());
        }

        // Falls die Leistung gelöscht wurde, prüfen ob noch eine ANDERE Schwimmdisziplin vorliegt
        $hasValidSwim = false;
        foreach ($ep->getResults() as $res) {
            if ($res->getPoints() > 0 && $res->getDiscipline()->isSwimmingCategory()) {
                $hasValidSwim = true;
                break;
            }
        }

        if (!$hasValidSwim) {
            foreach ($p->getSwimmingProofs() as $sp) {
                if ($sp->getExamYear() === $year && str_starts_with($sp->getRequirementMetVia() ?? '', 'DISCIPLINE:')) {
                    $this->em->remove($sp);
                }
            }
        }
    }

    private function calculateSummary(ExamParticipant $ep): array
    {
        $cats = ['Ausdauer' => 0, 'Kraft' => 0, 'Schnelligkeit' => 0, 'Koordination' => 0];
        
        foreach ($ep->getResults() as $res) {
            $k = $res->getDiscipline()->getCategory();
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        $hasSwimming = false;
        $today = new \DateTime();
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            if ($sp->getValidUntil() >= $today) {
                $hasSwimming = true;
                break;
            }
        }

        $medal = 'none';
        if ($hasSwimming) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silber';
            elseif ($total >= 4) $medal = 'bronze';
        }

        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        return ['total' => $total, 'medal' => $medal, 'has_swimming' => $hasSwimming];
    }
    
    #[Route('/exam/{examId}/print_groupcard', name: 'print_groupcard', methods: ['GET'])]
    public function printGroupcard(int $examId, Request $request, Connection $conn): Response
    {
    $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
    $selectedClass = $request->query->get('class');
    
    $conn = $this->em->getConnection();

    // 1. Prüfungsdaten laden
    $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$examId]);
    if (!$exam) {
        throw $this->createNotFoundException('Prüfung nicht gefunden.');
    }

    // 2. Teilnehmer laden
    // Wir holen sp.confirmed_at als 'swimming_date'
    $sql = "
        SELECT 
            ep.id as ep_id, 
            u.lastname, 
            u.firstname, 
            p.geburtsdatum, 
            p.geschlecht, 
            ep.age_year, 
            ep.total_points, 
            ep.final_medal, 
            ep.participant_id,
            (SELECT sp.confirmed_at FROM sportabzeichen_swimming_proofs sp 
             WHERE sp.participant_id = ep.participant_id AND sp.valid_until >= CURRENT_DATE 
             ORDER BY sp.confirmed_at DESC LIMIT 1) as swimming_date
        FROM sportabzeichen_exam_participants ep
        JOIN sportabzeichen_participants p ON p.id = ep.participant_id
        JOIN users u ON u.importid = p.import_id
        WHERE ep.exam_id = ? 
          AND ep.final_medal IN ('bronze', 'silber', 'gold')
    ";
    
    $params = [$examId];
    if ($selectedClass) {
        $sql .= " AND u.auxinfo = ?";
        $params[] = $selectedClass;
    }
    $participants = $conn->fetchAllAssociative($sql . " ORDER BY u.lastname, u.firstname", $params);

    // Mappings
    $unitMap = [
        'UNIT_MINUTES' => 'min', 
        'UNIT_SECONDS' => 's', 
        'UNIT_METERS' => 'm',
        'UNIT_CENTIMETERS' => 'cm', 
        'UNIT_HOURS' => 'h', 
        'UNIT_NUMBER' => 'x'
    ];
    $catMap = ['Ausdauer' => 1, 'Kraft' => 2, 'Schnelligkeit' => 3, 'Koordination' => 4];

    $enrichedParticipants = [];
    
    // 3. Daten aufbereiten
    foreach ($participants as $p) {
        // A. Basis-Mappings
        $p['geschlecht_kurz'] = ($p['geschlecht'] === 'FEMALE') ? 'w' : 'm';
        $p['birthday_fmt'] = $p['geburtsdatum'] ? (new \DateTime($p['geburtsdatum']))->format('d.m.Y') : '';
        
        // B. Schwimm-Logik (Hier lag der Fehler)
        // Wir berechnen has_swimming einfach daraus, ob ein Datum gefunden wurde
        $p['has_swimming'] = !empty($p['swimming_date']);
        $p['swimming_year'] = $p['swimming_date'] ? (new \DateTime($p['swimming_date']))->format('y') : '';

        // C. Disziplin-Ergebnisse laden
        $resultsRaw = $conn->fetchAllAssociative("
            SELECT r.auswahlnummer, res.leistung, res.points, d.kategorie, d.einheit
            FROM sportabzeichen_exam_results res
            JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id
            JOIN sportabzeichen_exam_participants ep ON ep.id = res.ep_id
            JOIN sportabzeichen_exams ex ON ex.id = ep.exam_id
            JOIN sportabzeichen_participants part ON part.id = ep.participant_id
            LEFT JOIN sportabzeichen_requirements r ON r.discipline_id = d.id 
                AND r.jahr = ex.exam_year 
                AND r.geschlecht = (CASE WHEN part.geschlecht = 'MALE' THEN 'MALE' ELSE 'FEMALE' END)
                AND ep.age_year BETWEEN r.age_min AND r.age_max
            WHERE res.ep_id = ?
            ORDER BY CASE d.kategorie 
                WHEN 'Ausdauer' THEN 1 WHEN 'Kraft' THEN 2 
                WHEN 'Schnelligkeit' THEN 3 WHEN 'Koordination' THEN 4 ELSE 5 END
        ", [$p['ep_id']]);

        // Raster befüllen
        $p['disciplines'] = array_fill(1, 4, ['nr' => '', 'res' => '', 'pts' => '']);
        foreach ($resultsRaw as $res) {
            if (isset($catMap[$res['kategorie']])) {
                $idx = $catMap[$res['kategorie']];
                $einheit = $unitMap[$res['einheit']] ?? '';
                
                // Deutsche Zahlenformatierung
                $p['disciplines'][$idx] = [
                    'nr'  => $res['auswahlnummer'],
                    'res' => str_replace('.', ',', (string)$res['leistung']) . ' ' . $einheit,
                    'pts' => $res['points']
                ];
            }
        }
        $enrichedParticipants[] = $p;
    }

    // 4. Batches bilden (10 pro Seite)
    $batches = array_chunk($enrichedParticipants, 10);
    
    if (count($batches) > 0) {
        $lastIndex = count($batches) - 1;
        while (count($batches[$lastIndex]) < 10) {
            $batches[$lastIndex][] = null;
        }
    }

    // 5. Rendern
    return $this->render('@PulsRSportabzeichen/exams/print_groupcard.html.twig', [
        'batches' => $batches,
        'exam' => $exam,
        'exam_year_short' => substr((string)$exam['exam_year'], -2), // "26"
        'selectedClass' => $selectedClass,
        'today' => new \DateTime(),
        'userNumber' => '', 
    ]);
}

}
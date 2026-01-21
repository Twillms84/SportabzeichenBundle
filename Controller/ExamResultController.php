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
use PulsR\SportabzeichenBundle\Service\SportabzeichenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sportabzeichen/exams/results', name: 'sportabzeichen_results_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class ExamResultController extends AbstractPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SportabzeichenService $service
    ) {
    }

    /**
     * Jahresauswahl (Startseite)
     */
    #[Route('/', name: 'exams', methods: ['GET'])]
    public function examSelection(): Response
    {
        $exams = $this->em->getRepository(Exam::class)->findBy([], ['year' => 'DESC']);
        return $this->render('@PulsRSportabzeichen/results/index.html.twig', ['exams' => $exams]);
    }

    /**
     * Hauptansicht der Ergebnisse für eine Prüfung
     */
    #[Route('/exam/{id}', name: 'index', methods: ['GET'])]
    public function index(Exam $exam, Request $request): Response
    {
        $selectedClass = $request->query->get('class');

        // 1. Teilnehmer mit allen relevanten Daten laden (Eager Loading)
        $qb = $this->em->createQueryBuilder();
        $qb->select('ep', 'p', 'u', 'sp', 'res', 'd')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->leftJoin('p.swimmingProofs', 'sp')
            ->leftJoin('ep.results', 'res')
            ->leftJoin('res.discipline', 'd')
            ->where('ep.exam = :exam')
            ->setParameter('exam', $exam);

        // Sortierung verarbeiten
        $sort = $request->query->get('sort', 'lastname');
        $order = strtoupper($request->query->get('order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        if ($sort === 'lastname') {
            $qb->orderBy('u.lastname', $order)->addOrderBy('u.firstname', 'ASC');
        } else {
            $qb->orderBy('u.lastname', 'ASC'); // Default fallback
        }

        if ($selectedClass) {
            $qb->andWhere('u.auxinfo = :class')->setParameter('class', $selectedClass);
        }

        $examParticipants = $qb->getQuery()->getResult();

        // 2. Daten für Twig aufbereiten
        $participantsData = [];
        $resultsData = [];
        $today = new \DateTime();

        foreach ($examParticipants as $ep) {
            $hasSwimming = false;
            $swimmingExpiry = null;
            $metVia = null; 
            
            // Schwimmstatus prüfen (Gültigkeit oder aktuelles Jahr)
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getExamYear() == $exam->getYear() || $proof->getValidUntil() >= $today) {
                    $hasSwimming = true;
                    $metVia = $proof->getRequirementMetVia(); 
                    if ($swimmingExpiry === null || $proof->getValidUntil() > $swimmingExpiry) {
                        $swimmingExpiry = $proof->getValidUntil();
                    }
                }
            }

            // Ergebnisse indizieren: [ep_id][discipline_id] => ResultData
            foreach ($ep->getResults() as $res) {
                $resultsData[$ep->getId()][$res->getDiscipline()->getId()] = [
                    'leistung' => $res->getLeistung(),
                    'points' => $res->getPoints(),
                    'stufe' => $res->getStufe(),
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
                'swimming_expiry' => $swimmingExpiry,
                'swimming_met_via' => $metVia,
            ];
        }

        // 3. Anforderungen/Disziplinen strukturiert laden
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
        foreach ($requirementsData as $reqRow) {
            $d = $reqRow['discipline'];
            $cat = $d['category'];
            $dId = $d['id'];
            
            if (!isset($disciplines[$cat])) $disciplines[$cat] = [];
            if (!isset($disciplines[$cat][$dId])) {
                $disciplines[$cat][$dId] = $d;
                $disciplines[$cat][$dId]['requirements'] = [];
            }
            $disciplines[$cat][$dId]['requirements'][] = $reqRow;
        }
        
        // Indizes für Twig-Loop glätten
        foreach($disciplines as $kat => $vals) {
            $disciplines[$kat] = array_values($vals);
        }

        // 4. Listen für Filter und Dropdowns
        $classes = $this->em->getConnection()->fetchFirstColumn("SELECT DISTINCT auxinfo FROM users WHERE auxinfo != '' ORDER BY auxinfo");

        // Spezielle Liste nur für Schwimm-Disziplinen im Dropdown
        $swimmingDisciplines = $this->em->getRepository(Discipline::class)->findBy(
            ['category' => 'Schwimmen'], 
            ['name' => 'ASC']
        );

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam' => $exam,
            'participants' => $participantsData,
            'disciplines' => $disciplines,
            'results' => $resultsData,
            'classes' => $classes,
            'selectedClass' => $selectedClass,
            'swimming_disciplines' => $swimmingDisciplines,
        ]);
    }

    /**
     * AJAX-Speicherung: Wechsel der Disziplin + Berechnung
     */
    #[Route('/exam/discipline/save', name: 'exam_discipline_save', methods: ['POST'])]
    public function saveExamDiscipline(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $ep = $this->getExamParticipant((int)($data['ep_id'] ?? 0));
        if (!$ep) return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        if (!$discipline) return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);

        // 1. Alte Ergebnisse dieser Kategorie entfernen
        $currentCat = $discipline->getCategory();
        foreach ($ep->getResults() as $existingRes) {
            if ($existingRes->getDiscipline()->getCategory() === $currentCat) {
                // Falls vorher Schwimmen ausgewählt war, Proof entfernen
                $this->service->updateSwimmingProof($ep, $existingRes->getDiscipline(), 0); 
                $this->em->remove($existingRes);
            }
        }
        $this->em->flush(); 

        // 2. Berechnung der Punkte
        $leistung = $this->formatLeistung($data['leistung'] ?? null);
        
        $pData = $this->service->calculateResult(
            $discipline, 
            (int)$ep->getExam()->getYear(), 
            $this->getGenderString($ep), 
            (int)$ep->getAgeYear(), 
            $leistung
        );

        $newResult = new ExamResult();
        $newResult->setExamParticipant($ep);
        $newResult->setDiscipline($discipline);

        // Sonderfall: DLRG / Verbandsabzeichen (Einheit NONE) -> Wert immer 1.0 (Gold)
        $unit = $discipline->getUnit();
        $isUnitNone = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));
        
        if ($isUnitNone) {
            $newResult->setLeistung(1.0); 
        } else {
            $newResult->setLeistung($leistung ?? 0.0);
        }

        $newResult->setPoints($pData['points']);
        $newResult->setStufe($pData['stufe']);
        $this->em->persist($newResult);

        // 3. Schwimm-Proof Update (falls Disziplin = Schwimmen)
        $this->service->updateSwimmingProof($ep, $discipline, $pData['points'], $pData['req'] ?? null);

        $this->em->flush();
        
        return $this->generateSummaryResponse($ep, $pData['points'], $pData['stufe']);
    }

    /**
     * AJAX-Speicherung: Update reiner Leistungswert
     */
    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $ep = $this->getExamParticipant((int)($data['ep_id'] ?? 0));
        if (!$ep) return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        if (!$discipline) return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);

        $leistung = $this->formatLeistung($data['leistung'] ?? null);

        // Check auf DLRG/Verband
        $unit = $discipline->getUnit();
        $isUnitNone = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));
        if ($isUnitNone) {
            $leistung = 1.0; 
        }

        $result = $this->em->getRepository(ExamResult::class)->findOneBy([
            'examParticipant' => $ep, 
            'discipline' => $discipline
        ]);

        $points = 0; 
        $stufe = 'none';

        if ($leistung === null && !$isUnitNone) {
            // Wert gelöscht -> Ergebnis entfernen
            if ($result) {
                $this->service->updateSwimmingProof($ep, $discipline, 0);
                $this->em->remove($result);
            }
        } else {
            // Wert gesetzt oder Update
            if (!$result) {
                $result = new ExamResult();
                $result->setExamParticipant($ep);
                $result->setDiscipline($discipline);
                $this->em->persist($result);
            }

            $pData = $this->service->calculateResult(
                $discipline,
                (int)$ep->getExam()->getYear(),
                $this->getGenderString($ep),
                (int)$ep->getAgeYear(),
                $leistung
            );

            $result->setLeistung($leistung);
            $result->setPoints($pData['points']);
            $result->setStufe($pData['stufe']);
            
            $points = $pData['points'];
            $stufe = $pData['stufe'];

            $this->service->updateSwimmingProof($ep, $discipline, $points, $pData['req']);
        }

        $this->em->flush();
        $this->em->refresh($ep);

        return $this->generateSummaryResponse($ep, $points, $stufe);
    }

    /**
     * PDF/Druckansicht der Prüfkarte
     */
    #[Route('/exam/{examId}/print_groupcard', name: 'print_groupcard', methods: ['GET'])]
    public function printGroupcard(int $examId, Request $request): Response
    {
        $selectedClass = $request->query->get('class');
        $conn = $this->em->getConnection();

        // 1. Prüfungsdaten
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$examId]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden.');

        $examYear = (int)$exam['exam_year'];
        $examYearEnd = $examYear . '-12-31';

        // 2. Teilnehmer laden (nur Bronze/Silber/Gold)
        $sql = "
            SELECT 
                ep.id as ep_id, 
                u.lastname, u.firstname, 
                p.geburtsdatum, p.geschlecht, 
                ep.age_year, ep.total_points, ep.final_medal, ep.participant_id,
                (SELECT sp.exam_year 
                 FROM sportabzeichen_swimming_proofs sp 
                 WHERE sp.participant_id = ep.participant_id 
                   AND (sp.exam_year = :year OR sp.valid_until >= :yearEnd)
                 ORDER BY sp.confirmed_at DESC LIMIT 1
                ) as swimming_proof_year
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            JOIN users u ON u.importid = p.import_id
            WHERE ep.exam_id = :examId 
              AND ep.final_medal IN ('bronze', 'silber', 'silver', 'gold')
        ";
        
        $params = ['examId' => $examId, 'year' => $examYear, 'yearEnd' => $examYearEnd];

        if ($selectedClass) {
            $sql .= " AND u.auxinfo = :cls";
            $params['cls'] = $selectedClass;
        }
        
        $participants = $conn->fetchAllAssociative($sql . " ORDER BY u.lastname, u.firstname", $params);

        // Mappings
        $unitMap = [
            'UNIT_MINUTES' => 'min', 'UNIT_SECONDS' => 's', 
            'UNIT_METERS' => 'm', 'UNIT_CENTIMETERS' => 'cm', 
            'UNIT_HOURS' => 'h', 'UNIT_NUMBER' => 'x', 'NONE' => ''
        ];
        $catMap = ['Ausdauer' => 1, 'Kraft' => 2, 'Schnelligkeit' => 3, 'Koordination' => 4];

        $enrichedParticipants = [];
        
        foreach ($participants as $p) {
            $p['geschlecht_kurz'] = ($p['geschlecht'] === 'FEMALE') ? 'w' : 'm';
            $p['birthday_fmt'] = $p['geburtsdatum'] ? (new \DateTime($p['geburtsdatum']))->format('d.m.Y') : '';
            $p['has_swimming'] = !empty($p['swimming_proof_year']);
            $p['swimming_year'] = $p['swimming_proof_year'] ? substr((string)$p['swimming_proof_year'], -2) : '';

            // Ergebnisse laden (inkl. Verbands-Info)
            $resultsRaw = $conn->fetchAllAssociative("
                SELECT r.auswahlnummer, res.leistung, res.points, res.stufe, 
                       d.kategorie, d.einheit, d.name as d_name, d.verband
                FROM sportabzeichen_exam_results res
                JOIN sportabzeichen_disciplines d ON d.id = res.discipline_id
                LEFT JOIN sportabzeichen_requirements r ON r.discipline_id = d.id 
                    AND r.jahr = :year
                    AND r.geschlecht = (CASE WHEN :gender = 'MALE' THEN 'MALE' ELSE 'FEMALE' END)
                    AND :age BETWEEN r.age_min AND r.age_max
                WHERE res.ep_id = :epId
                ORDER BY d.kategorie ASC
            ", [
                'epId' => $p['ep_id'],
                'year' => $examYear,
                'gender' => $p['geschlecht'],
                'age' => $p['age_year']
            ]);

            $p['disciplines'] = array_fill(1, 4, ['nr' => '', 'res' => '', 'pts' => '']);
            
            foreach ($resultsRaw as $res) {
                if (isset($catMap[$res['kategorie']])) {
                    $idx = $catMap[$res['kategorie']];
                    
                    $unit = $res['einheit'];
                    $isUnitNone = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));

                    if (!empty($res['verband']) && $isUnitNone) {
                        // FALL 1: Verbandsabzeichen (z.B. DLRG) -> "A" und Verbandsname
                        $displayNr = 'A';
                        $displayRes = $res['verband'];
                    } else {
                        // FALL 2: Normale Disziplin
                        $einheit = $unitMap[$res['einheit']] ?? '';
                        $displayNr = $res['auswahlnummer'] ?? '-';
                        $valStr = str_replace('.', ',', (string)$res['leistung']);
                        
                        if ((empty($valStr) || $valStr === '0') && $res['points'] > 0) {
                             $displayRes = ucfirst($res['stufe'] ?? 'Ok');
                        } else {
                             $displayRes = $valStr . ' ' . $einheit;
                        }
                    }

                    $p['disciplines'][$idx] = [
                        'nr'  => $displayNr,
                        'res' => $displayRes,
                        'pts' => $res['points']
                    ];
                }
            }
            $enrichedParticipants[] = $p;
        }

        // Batches für Seitenumbruch (je 10)
        $batches = array_chunk($enrichedParticipants, 10);
        if (count($batches) > 0) {
            $lastIndex = count($batches) - 1;
            while (count($batches[$lastIndex]) < 10) {
                $batches[$lastIndex][] = null;
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/print_groupcard.html.twig', [
            'batches' => $batches,
            'exam' => $exam,
            'exam_year_short' => substr((string)$examYear, -2),
            'selectedClass' => $selectedClass,
            'today' => new \DateTime(),
        ]);
    }

    // --- HELPER ---

    private function getExamParticipant(int $id): ?ExamParticipant
    {
        return $this->em->createQueryBuilder()
             ->select('ep', 'p', 'u', 'ex')
             ->from(ExamParticipant::class, 'ep')
             ->join('ep.participant', 'p')
             ->join('p.user', 'u')
             ->join('ep.exam', 'ex')
             ->where('ep.id = :id')
             ->setParameter('id', $id)
             ->getQuery()
             ->getOneOrNullResult();
    }

    private function formatLeistung($input): ?float
    {
        if ($input === null || $input === '') return null;
        return (float)str_replace(',', '.', (string)$input);
    }

    private function getGenderString(ExamParticipant $ep): string
    {
        $raw = $ep->getParticipant()->getGender() ?? 'W';
        return (str_starts_with(strtoupper($raw), 'M')) ? 'MALE' : 'FEMALE';
    }

    private function generateSummaryResponse(ExamParticipant $ep, int $points, string $stufe): JsonResponse
    {
        $summary = $this->service->syncSummary($ep);
        return new JsonResponse([
            'status' => 'ok',
            'points' => $points,
            'stufe' => $stufe,
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal'],
            'has_swimming' => $summary['has_swimming'],
            'swimming_met_via' => $summary['met_via'] ?? ($summary['swimming_met_via'] ?? ''),
            'swimming_expiry'  => $summary['expiry'] ?? ($summary['swimming_expiry'] ?? null),
        ]);
    }
}
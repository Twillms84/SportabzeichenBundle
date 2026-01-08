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

        // 1. Teilnehmer mit allen relevanten Daten laden
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

        $examParticipants = $qb->getQuery()->getResult();

        // 2. Daten für Twig transformieren
        $participantsData = [];
        $resultsData = [];
        $today = new \DateTime();

        foreach ($examParticipants as $ep) {
            $hasSwimming = false;
            $swimmingExpiry = null;
            $metVia = null; 
            
            // Schwimmstatus prüfen
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getExamYear() == $exam->getYear() || $proof->getValidUntil() >= $today) {
                    $hasSwimming = true;
                    $metVia = $proof->getRequirementMetVia(); 
                    if ($swimmingExpiry === null || $proof->getValidUntil() > $swimmingExpiry) {
                        $swimmingExpiry = $proof->getValidUntil();
                    }
                }
            }

            // Ergebnisse indizieren
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

        // 4. Klassenliste für Filter laden
        $classes = $this->em->getConnection()->fetchFirstColumn("SELECT DISTINCT auxinfo FROM users WHERE auxinfo != '' ORDER BY auxinfo");

        // --- NEU: Schwimm-Disziplinen für das Dropdown laden ---
        $swimmingDisciplines = $this->em->getRepository(Discipline::class)->findBy(
            ['category' => 'SWIMMING'], // Filtert nach Kategorie "Swimming"
            ['name' => 'ASC']
        );
        // -------------------------------------------------------

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam' => $exam,
            'participants' => $participantsData,
            'disciplines' => $disciplines,
            'results' => $resultsData,
            'classes' => $classes,
            'selectedClass' => $selectedClass,
            'swimming_disciplines' => $swimmingDisciplines, // NEU: Hier übergeben!
        ]);
    }

    /**
     * AJAX-Speicherung einer Disziplinwahl + Leistung
     */
    #[Route('/exam/discipline/save', name: 'exam_discipline_save', methods: ['POST'])]
public function saveExamDiscipline(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);
    
    // Eager Loading: Wir laden ep, participant (p), user (u) und exam (ex) in einem Rutsch
    $ep = $this->em->createQueryBuilder()
        ->select('ep', 'p', 'u', 'ex')
        ->from(ExamParticipant::class, 'ep')
        ->join('ep.participant', 'p')
        ->join('p.user', 'u')
        ->join('ep.exam', 'ex')
        ->where('ep.id = :id')
        ->setParameter('id', (int)($data['ep_id'] ?? 0))
        ->getQuery()
        ->getOneOrNullResult();

    if (!$ep) {
        return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
    }

    // Spezialfall: Manueller Schwimm-Haken (AJAX)
    if (isset($data['type']) && $data['type'] === 'swimming') {
        return $this->handleManualSwimming($ep, $data);
    }

    $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
    if (!$discipline) {
        return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
    }

    // 1. Bereinigung der alten Disziplin in dieser Kategorie
    $currentCat = $discipline->getCategory();
    foreach ($ep->getResults() as $existingRes) {
        if ($existingRes->getDiscipline()->getCategory() === $currentCat) {
            $this->service->updateSwimmingProof($ep, $existingRes->getDiscipline(), 0); 
            $this->em->remove($existingRes);
        }
    }
    // Flush hier wichtig, um Platz für das neue Result zu machen (Unique Constraints)
    $this->em->flush(); 

    // 2. Berechnung & Speicherung
    $leistung = $this->formatLeistung($data['leistung'] ?? null);
    
    // getGenderString greift jetzt auf den fertig geladenen User zu
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
    // Verbands-Disziplinen (z.B. Schwimmabzeichen) bekommen pauschal 1.0 Leistung
    $newResult->setLeistung(!empty($discipline->getVerband()) ? 1.0 : ($leistung ?? 0.0));
    $newResult->setPoints($pData['points']);
    $newResult->setStufe($pData['stufe']);
    $this->em->persist($newResult);

    // 3. Schwimm-Proof Update (Automatischer Haken durch Disziplin)
    $this->service->updateSwimmingProof($ep, $discipline, $pData['points'], $pData['req'] ?? false);

    $this->em->flush();
    
    return $this->generateSummaryResponse($ep, $pData['points'], $pData['stufe']);
}

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Wichtig: Teilnehmer mit Join laden (User-Proxy-Schutz!)
        $ep = $this->em->createQueryBuilder()
            ->select('ep', 'p', 'u')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->where('ep.id = :id')
            ->setParameter('id', (int)($data['ep_id'] ?? 0))
            ->getQuery()
            ->getOneOrNullResult();

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));

        if (!$ep || !$discipline) {
            return new JsonResponse(['error' => 'Daten unvollständig'], 400);
        }

        // Hier wird der Service aufgerufen
        $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
        
        // In der DB speichern
        $this->em->flush();

        // Alles neu berechnen (Medaille wird jetzt durch has_swimming = true gültig)
        $summary = $this->service->syncSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => true,
            'swimming_met_via' => $discipline->getName(),
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }
    /**
     * Speichert die reine Leistung (Update eines Textfeldes)
     */
    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Eager Loading des Teilnehmers inkl. Relationen
        $ep = $this->em->createQueryBuilder()
            ->select('ep', 'p', 'u', 'ex')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->join('ep.exam', 'ex')
            ->where('ep.id = :id')
            ->setParameter('id', (int)($data['ep_id'] ?? 0))
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        if (!$discipline) {
            return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
        }

        $leistung = $this->formatLeistung($data['leistung'] ?? null);
        $result = $this->em->getRepository(ExamResult::class)->findOneBy([
            'examParticipant' => $ep, 
            'discipline' => $discipline
        ]);

        $points = 0; 
        $stufe = 'none';

        if ($leistung === null) {
            if ($result) {
                $this->service->updateSwimmingProof($ep, $discipline, 0);
                $this->em->remove($result);
            }
        } else {
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

        return $this->generateSummaryResponse($ep, $points, $stufe);
    }
    
    // --- HELPER ---

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
        // Service synchronisiert Gesamtpunkte und Medaille
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
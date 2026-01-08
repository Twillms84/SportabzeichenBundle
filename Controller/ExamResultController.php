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
            $metVia = null; 
            $today = new \DateTime();
            
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getExamYear() == $exam->getYear() || $proof->getValidUntil() >= $today) {
                    $hasSwimming = true;
                    $metVia = $proof->getRequirementMetVia(); 
                    
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
                'swimming_expiry' => $swimmingExpiry,
                'swimming_met_via' => $metVia,
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
        
        $ep = $this->em->createQueryBuilder()
            ->select('ep', 'p', 'u')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->leftJoin('p.user', 'u')
            ->where('ep.id = :id')
            ->setParameter('id', (int)($data['ep_id'] ?? 0))
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ep) return new JsonResponse(['error' => 'Not found'], 404);

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        if (!$discipline) return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);

        $year = (int)$ep->getExam()->getYear();
        $age  = (int)$ep->getAgeYear();
        $gender = (str_starts_with(strtoupper($ep->getParticipant()->getGender() ?? 'W'), 'M')) ? 'MALE' : 'FEMALE';

        // 1. Alte Ergebnisse dieser Kategorie entfernen
        $currentCat = $discipline->getCategory();
        foreach ($ep->getResults() as $existingRes) {
            if ($existingRes->getDiscipline()->getCategory() === $currentCat) {
                // Bevor wir löschen: Wenn es eine Schwimmdisziplin war, Trigger auf 0 setzen
                $oldDisc = $existingRes->getDiscipline();
                $this->updateSwimmingProof($ep, $oldDisc, 0); 
                
                $ep->removeResult($existingRes);
                $this->em->remove($existingRes);
            }
        }
        $this->em->flush();

        // 2. Neue Berechnung mit der korrekten Methode
        $leistungInput = $data['leistung'] ?? null;
        $leistung = ($leistungInput !== null && $leistungInput !== '') 
            ? (float)str_replace(',', '.', (string)$leistungInput) : null;

        // HIER WAR DER FEHLER: Name muss internalCalculate sein
        $pData = $this->internalCalculate($discipline, $year, $gender, $age, $leistung);
        
        $points = $pData['points'];
        $stufe = $pData['stufe'];
        $req = $pData['req'];

        // 3. Neues Ergebnis anlegen
        $newResult = new ExamResult();
        $newResult->setDiscipline($discipline);
        $newResult->setLeistung(!empty($discipline->getVerband()) ? 1.0 : ($leistung ?? 0.0));
        $newResult->setPoints($points);
        $newResult->setStufe($stufe);
        $ep->addResult($newResult);
        $this->em->persist($newResult);

        // 4. Schwimm-Trigger (VERBESSERT)
        $nameLower = strtolower($discipline->getName() ?? '');
        $istVerband = !empty($discipline->getVerband());

        $this->updateSwimmingProof($ep, $discipline, $points, $req);

        $this->em->flush();

        return $this->generateSummaryResponse($ep, $points, $stufe, $discipline);
    }

    /**
     * Ändern der Leistung (Textfeld)
     */
    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Eager Loading wie oben
        $ep = $this->em->createQueryBuilder()
            ->select('ep', 'p', 'u')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->leftJoin('p.user', 'u')
            ->where('ep.id = :id')
            ->setParameter('id', (int)($data['ep_id'] ?? 0))
            ->getQuery()
            ->getOneOrNullResult();

        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        if (!$ep || !$discipline) return new JsonResponse(['error' => 'Daten unvollständig'], 404);

        $leistungInput = $data['leistung'] ?? null;
        $leistung = ($leistungInput !== null && $leistungInput !== '') 
            ? (float)str_replace(',', '.', (string)$leistungInput) 
            : null;

        $result = $this->em->getRepository(ExamResult::class)->findOneBy([
            'examParticipant' => $ep,
            'discipline' => $discipline
        ]);

        $points = 0; $stufe = 'none';

        if ($leistung === null) {
            if ($result) {
                // Falls gelöscht wird, müssen wir den Schwimmnachweis evtl. entfernen
                $this->updateSwimmingProof($ep, $discipline, 0); 
                $this->em->remove($result);
            }
        } else {
            if (!$result) {
                $result = new ExamResult();
                $result->setExamParticipant($ep);
                $result->setDiscipline($discipline);
                $this->em->persist($result);
            }
            
            $year = (int)$ep->getExam()->getYear();
            $age  = (int)$ep->getAgeYear();
            $rawGender = $ep->getParticipant()->getGender() ?? 'W';
            $gender = (str_starts_with(strtoupper($rawGender), 'M')) ? 'MALE' : 'FEMALE';

            // Nutzt die neue zentrale Methode inkl. Verbands-Check
            $pData = $this->internalCalculate($discipline, $year, $gender, $age, $leistung);
            $points = $pData['points'];
            $stufe = $pData['stufe'];
            $req = $pData['req']; // Das Requirement für den Schwimm-Check

            $result->setLeistung($leistung);
            $result->setPoints($points);
            $result->setStufe($stufe);

            // Trigger Schwimmnachweis
            $nameLower = strtolower($discipline->getName() ?? '');
            $this->updateSwimmingProof($ep, $discipline, $points, $req);
        }

        $this->em->flush();
        return $this->generateSummaryResponse($ep, $points, $stufe, $discipline);
    }

    #[Route('/exam/swimming/toggle', name: 'exam_swimming_toggle', methods: ['POST'])]
    public function toggleSwimming(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $epId = (int)($data['ep_id'] ?? 0);
        $isChecked = (bool)($data['swimming'] ?? false);

        $ep = $this->em->getRepository(ExamParticipant::class)->find($epId);
        if (!$ep) return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);

        $participant = $ep->getParticipant();
        $year = $ep->getExam()->getYear();
        
        $proofRepo = $this->em->getRepository(SwimmingProof::class);
        $proof = $proofRepo->findOneBy(['participant' => $participant, 'examYear' => $year]);

        if ($isChecked) {
            // Einschalten
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($participant);
                $proof->setExamYear($year);
                $this->em->persist($proof);
            }
            
            // Nur ändern, wenn nicht schon eine Disziplin drinsteht (Sicherheits-Check)
            if (!$proof->getRequirementMetVia() || !str_starts_with($proof->getRequirementMetVia(), 'DISCIPLINE:')) {
                $proof->setConfirmedAt(new \DateTime());
                
                // Gültigkeit berechnen
                $age = $ep->getAgeYear();
                $validUntilYear = ($age <= 17) ? ($year + (18 - $age)) : ($year + 4);
                $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
                $proof->setRequirementMetVia('MANUAL');
            }
        } else {
            // Ausschalten: Nur löschen, wenn es MANUELL gesetzt wurde
            if ($proof && $proof->getRequirementMetVia() === 'MANUAL') {
                $this->em->remove($proof);
            }
        }

        $this->em->flush();

        // Wir nutzen die Summary-Response, um alle UI-Elemente (Punkte, Medaille) zu aktualisieren
        return $this->generateSummaryResponse($ep, 0, 'none', new Discipline()); 
    }
    // --- HELPER METHODEN ---

    private function internalCalculate(Discipline $discipline, int $year, string $gender, int $age, ?float $leistung): array
    {
        $istVerband = !empty($discipline->getVerband());
        
        // 1. Requirement immer suchen (wegen swimmingProof Flag)
        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement(
            $discipline, $year, $gender, $age
        );

        // 2. Sonderfall Verband
        if ($istVerband) {
            return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
        }

        if ($leistung === null || $leistung <= 0 || !$req) {
            return ['points' => 0, 'stufe' => 'none', 'req' => $req];
        }

        // 3. Normale Berechnung
        $calc = strtoupper($discipline->getBerechnungsart() ?? 'GREATER');
        $vG = (float)$req->getGold(); 
        $vS = (float)$req->getSilver(); 
        $vB = (float)$req->getBronze();
        
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
        
        return ['points' => $p, 'stufe' => $s, 'req' => $req];
    }

    private function generateSummaryResponse(ExamParticipant $ep, int $points, string $stufe, Discipline $discipline): JsonResponse
    {
        $summary = $this->calculateSummary($ep);
        
        // Wir holen den aktuellen Nachweis, um den 'met_via' String zu erhalten
        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $ep->getParticipant(),
            'examYear' => $ep->getExam()->getYear()
        ]);

        return new JsonResponse([
            'status' => 'ok',
            'points' => $points,
            'stufe' => $stufe,
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal'],
            'has_swimming' => $summary['has_swimming'],
            // NEU: Damit JS weiß, ob der Switch gesperrt werden muss
            'swimming_met_via' => $proof ? $proof->getRequirementMetVia() : null,
        ]);
    }

    private function updateSwimmingProof(ExamParticipant $examParticipant, Discipline $discipline, int $points, ?Requirement $requirement): void
    {
        $examYear = $examParticipant->getExam()->getYear();
        
        // 1. Check if the requirement is marked as a swimming proof
        // Or if it's an association (they often don't have explicit req-flags but are swimming associations)
        $isSwimmingRelevant = ($requirement && $requirement->isSwimmingProof()) || !empty($discipline->getVerband());

        if (!$isSwimmingRelevant) {
            return; 
        }

        $swimmingProofRepository = $this->em->getRepository(SwimmingProof::class);
        $proof = $swimmingProofRepository->findOneBy([
            'participant' => $examParticipant->getParticipant(),
            'examYear' => $examYear
        ]);

        // Scenario A: Achievement accomplished (points > 0)
        if ($points > 0) {
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($examParticipant->getParticipant());
                $proof->setExamYear($examYear);
                $this->em->persist($proof);
            }
            
            $age = $examParticipant->getAgeYear();
            // Validity calculation: Minors until 18th birthday, adults +4 years
            $validUntilYear = ($age <= 17) ? ($examYear + (18 - $age)) : ($examYear + 4);
            
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
            $proof->setRequirementMetVia('DISCIPLINE:' . $discipline->getId());
            
            $this->em->flush();
        } 
        // Scenario B: Result reset to 0 (delete the proof if it came from this discipline)
        elseif ($points === 0 && $proof) {
            // Only remove if the proof was actually provided by THIS specific discipline
            if ($proof->getRequirementMetVia() === 'DISCIPLINE:' . $discipline->getId()) {
                $this->em->remove($proof);
                $this->em->flush();
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
        $currentYear = $ep->getExam()->getYear();

        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            // Ein Nachweis gilt, wenn er im aktuellen Jahr erbracht wurde ODER noch in der Zukunft gültig ist
            if ($sp->getExamYear() == $currentYear || $sp->getValidUntil() >= $today) {
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
    
    private function calculatePoints(ExamParticipant $ep, Discipline $discipline, ?float $leistung): array 
    {
        if ($leistung === null || $leistung <= 0) return ['points' => 0, 'stufe' => 'none'];
        
        $gender = (str_starts_with(strtoupper($ep->getParticipant()->getGender() ?? 'W'), 'M')) ? 'MALE' : 'FEMALE';
        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement(
            $discipline, (int)$ep->getExam()->getYear(), $gender, (int)$ep->getAgeYear()
        );

        if (!$req) return ['points' => 0, 'stufe' => 'none'];

        $calc = strtoupper($discipline->getBerechnungsart() ?? 'BIGGER');
        $vG = (float)$req->getGold(); 
        $vS = (float)$req->getSilver(); 
        $vB = (float)$req->getBronze();
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
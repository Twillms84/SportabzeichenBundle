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
        // Der gewünschte Filter aus der URL (z.B. ?class=5a)
        $selectedFilter = $request->query->get('class'); 

        // ---------------------------------------------------------
        // 0. PRÜFUNGSGRUPPEN LADEN
        // ---------------------------------------------------------
        // Wir holen uns die Acts der Gruppen, die explizit zugeordnet sind.
        $allowedGroupActs = $this->em->getConnection()->fetchFirstColumn(
            'SELECT act FROM sportabzeichen_exam_groups WHERE exam_id = ?',
            [$exam->getId()]
        );

        // ---------------------------------------------------------
        // 1. TEILNEHMER DATENBANKABFRAGE
        // ---------------------------------------------------------
        $qb = $this->em->createQueryBuilder();
        
        $qb->select('ep', 'p', 'u', 'sp', 'res', 'd', 'ug') 
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->leftJoin('u.groups', 'ug') // IServ User -> Groups Relation
            ->leftJoin('p.swimmingProofs', 'sp')
            ->leftJoin('ep.results', 'res')
            ->leftJoin('res.discipline', 'd')
            ->where('ep.exam = :exam')
            ->setParameter('exam', $exam);

        // --- Filter direkt in der Datenbank ---
        // Nur Teilnehmer laden, die in einer der erlaubten Gruppen sind
        if (!empty($allowedGroupActs)) {
            // FIX: Use 'ug.account' instead of 'ug.act'
            $qb->andWhere('ug.account IN (:allowedGroups)')
               ->setParameter('allowedGroups', $allowedGroupActs);
        }

        // Sortierung
        $sort = $request->query->get('sort', 'lastname');
        $order = strtoupper($request->query->get('order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        if ($sort === 'lastname') {
            $qb->orderBy('u.lastname', $order)->addOrderBy('u.firstname', 'ASC');
        } else {
            $qb->orderBy('u.lastname', 'ASC'); 
        }

        /** @var ExamParticipant[] $examParticipants */
        // DISTINCT wichtig, da User in mehreren Gruppen sein können und durch den Join sonst vervielfacht werden
        $examParticipants = $qb->distinct()->getQuery()->getResult();

        // ---------------------------------------------------------
        // 2. DATEN AUFBEREITEN
        // ---------------------------------------------------------
        $participantsData = [];
        $resultsData = [];
        $filterOptions = []; 
        $today = new \DateTime();

        foreach ($examParticipants as $ep) {
            $user = $ep->getParticipant()->getUser();
            $userGroups = $user->getGroups();
            
            // --- LOGIK: GRUPPENNAME ERMITTELN ---
            $categoryName = 'Sonstige';
            
            if (!empty($allowedGroupActs)) {
                foreach ($userGroups as $g) {
                    // Wir prüfen, welche der Gruppen des Users diejenige ist, die im Exam erlaubt ist
                    // Wir nutzen getAct() oder getAccount(), je nach Entity-Version. Meist getAct() oder via __toString()
                    $gAct = (method_exists($g, 'getAct')) ? $g->getAct() : $g->getAccount();
                    
                    if (in_array($gAct, $allowedGroupActs)) {
                        $categoryName = $g->getName(); // Der Anzeigename (z.B. "Klasse 5a")
                        break; 
                    }
                }
            } else {
                // Fallback: Erste Gruppe nehmen, idealerweise eine Klasse (startet oft mit Ziffer)
                if (!$userGroups->isEmpty()) {
                    // Einfache Heuristik: Nimm die erste Gruppe
                    $categoryName = $userGroups->first()->getName();
                    // Optional: Man könnte hier durchloopen und bevorzugt Gruppen nehmen, die wie Klassen aussehen
                }
            }

            // Kategorie für das Dropdown-Menü merken
            $filterOptions[] = $categoryName;

            // --- FILTER PRÜFUNG (Frontend Filter via URL) ---
            if ($selectedFilter && $categoryName !== $selectedFilter) {
                continue;
            }

            // --- SCHWIMMSTATUS PRÜFEN ---
            $hasSwimming = false;
            $swimmingExpiry = null;
            $metVia = null; 
            
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getExamYear() == $exam->getYear() || ($proof->getValidUntil() && $proof->getValidUntil() >= $today)) {
                    $hasSwimming = true;
                    $metVia = $proof->getRequirementMetVia(); 
                    if ($swimmingExpiry === null || $proof->getValidUntil() > $swimmingExpiry) {
                        $swimmingExpiry = $proof->getValidUntil();
                    }
                }
            }

            // --- ERGEBNISSE INDIZIEREN ---
            foreach ($ep->getResults() as $res) {
                $resultsData[$ep->getId()][$res->getDiscipline()->getId()] = [
                    'leistung' => $res->getLeistung(),
                    'points' => $res->getPoints(),
                    'stufe' => $res->getStufe(),
                    'category' => $res->getDiscipline()->getCategory()
                ];
            }
            
            // --- DATENSATZ BAUEN ---
            $participantsData[] = [
                'entity' => $ep,
                'ep_id' => $ep->getId(),
                'vorname' => $user->getFirstname(),
                'nachname' => $user->getLastname(),
                'klasse' => $categoryName, 
                'group'  => $categoryName, 
                'geschlecht' => $ep->getParticipant()->getGender(),
                'age_year' => $ep->getAgeYear(),
                'total_points' => $ep->getTotalPoints(),
                'final_medal' => $ep->getFinalMedal(),
                'has_swimming' => $hasSwimming,
                'swimming_expiry' => $swimmingExpiry,
                'swimming_met_via' => $metVia,
            ];
        }

        // Filter-Optionen bereinigen
        $filterOptions = array_unique($filterOptions);
        sort($filterOptions, SORT_NATURAL | SORT_FLAG_CASE);

        // ---------------------------------------------------------
        // 3. ANFORDERUNGEN FÜR TABELLENKOPF LADEN
        // ---------------------------------------------------------
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
        
        foreach($disciplines as $kat => $vals) {
            $disciplines[$kat] = array_values($vals);
        }

        $swimmingDisciplines = $this->em->getRepository(Discipline::class)->findBy(
            ['category' => 'Schwimmen'], 
            ['name' => 'ASC']
        );

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam' => $exam,
            'participants' => $participantsData,
            'disciplines' => $disciplines,
            'results' => $resultsData,
            'classes' => $filterOptions, 
            'selectedClass' => $selectedFilter,
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

        // 1. Alte Ergebnisse dieser Kategorie aufräumen
        $currentCat = $discipline->getCategory();
        foreach ($ep->getResults() as $existingRes) {
            if ($existingRes->getDiscipline()->getCategory() === $currentCat) {
                if ($existingRes->getDiscipline()->isSwimmingCategory()) {
                    $this->service->updateSwimmingProof($ep, $existingRes->getDiscipline(), 0); 
                }
                $this->em->remove($existingRes);
            }
        }
        $this->em->flush(); 

        // 2. Berechnung & Logik
        $leistung = $this->formatLeistung($data['leistung'] ?? null);
        $unit = $discipline->getUnit();
        $isVerband = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));

        $points = 0;
        $stufe = '';

        if ($isVerband) {
            $points = 3; 
            $stufe = 'GOLD';
        } else {
            $pData = $this->service->calculateResult(
                $discipline, 
                (int)$ep->getExam()->getYear(), 
                $this->getGenderString($ep), 
                (int)$ep->getAgeYear(), 
                $leistung
            );
            $points = $pData['points'];
            $stufe = $pData['stufe'];
        }

        // 2a. Requirements für das Frontend laden
        $requirementsData = null;

        if (!$isVerband) {
            $reqEntity = $this->em->getRepository(Requirement::class)->createQueryBuilder('r')
                ->where('r.discipline = :disp')
                ->andWhere('r.year = :year')
                ->andWhere('r.gender = :gender')
                ->andWhere('r.minAge <= :age')
                ->andWhere('r.maxAge >= :age')
                ->setParameter('disp', $discipline)
                ->setParameter('year', $ep->getExam()->getYear())
                ->setParameter('gender', $this->getGenderString($ep))
                ->setParameter('age', $ep->getAgeYear())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($reqEntity) {
                $requirementsData = [
                    'bronze' => $reqEntity->getBronze(),
                    'silber' => $reqEntity->getSilver(),
                    'gold'   => $reqEntity->getGold(),
                    'unit'   => $unit
                ];
            }
        }

        // 3. Ergebnis speichern
        $newResult = new ExamResult();
        $newResult->setExamParticipant($ep);
        $newResult->setDiscipline($discipline);
        $newResult->setPoints($points);
        $newResult->setStufe($stufe);

        if ($isVerband) {
            $newResult->setLeistung(1.0); 
        } else {
            $newResult->setLeistung($leistung ?? 0.0);
        }
        
        $this->em->persist($newResult);

        // 4. Schwimm-Proof Update
        if ($discipline->isSwimmingCategory()) {
            $this->service->updateSwimmingProof($ep, $discipline, $points);
        }

        $this->em->flush();
        $this->em->refresh($ep); 
        
        // 5. Response bauen
        $response = $this->generateSummaryResponse($ep, $points, $stufe);
        $content = json_decode($response->getContent(), true);
        
        $content['new_requirements'] = $requirementsData;
        $content['discipline_unit'] = $unit; 

        return new JsonResponse($content);
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
                if ($discipline->isSwimmingCategory()) {
                    $this->service->updateSwimmingProof($ep, $discipline, 0);
                }
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

            if ($isUnitNone) {
                $points = 3;
                $stufe = 'GOLD';
                $reqObj = null;
            } else {
                $pData = $this->service->calculateResult(
                    $discipline,
                    (int)$ep->getExam()->getYear(),
                    $this->getGenderString($ep),
                    (int)$ep->getAgeYear(),
                    $leistung
                );
                $points = $pData['points'];
                $stufe = $pData['stufe'];
                $reqObj = $pData['req'] ?? null;
            }

            $result->setLeistung($leistung);
            $result->setPoints($points);
            $result->setStufe($stufe);

            if ($discipline->isSwimmingCategory()) {
                $this->service->updateSwimmingProof($ep, $discipline, $points, $reqObj);
            }
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
        // 1. Parameter auslesen
        $selectedClass = $request->query->get('class_filter') ?? $request->query->get('class');
        $searchQuery   = $request->query->get('search_query');
        $selectedIds   = $request->query->get('ids'); 
        
        $sort = $request->query->get('sort', 'lastname');
        $order = strtoupper($request->query->get('order', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $conn = $this->em->getConnection();

        // 2. Prüfungsdaten
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$examId]);
        if (!$exam) throw $this->createNotFoundException('Prüfung nicht gefunden.');

        $examYear = (int)$exam['exam_year'];
        $examYearEnd = $examYear . '-12-31';

        // 3. Basis-SQL vorbereiten
        // KORREKTUR: actgrp statt group, actuser statt user, ~ statt REGEXP
        $groupNameSql = "
            (SELECT g.name FROM members m 
             JOIN groups g ON m.actgrp = g.act 
             WHERE m.actuser = u.act 
             " . ($selectedClass ? "AND g.name = :selectedClass" : "") . "
             ORDER BY (CASE WHEN g.name ~ '^[0-9]' THEN 0 ELSE 1 END), g.name 
             LIMIT 1
            )
        ";

        $sql = "
            SELECT 
                ep.id as ep_id, 
                u.act as account_act,
                u.lastname, u.firstname, 
                p.geburtsdatum, p.geschlecht, 
                ep.age_year, ep.total_points, ep.final_medal, ep.participant_id,
                $groupNameSql as group_name,
                (SELECT sp.exam_year 
                 FROM sportabzeichen_swimming_proofs sp 
                 WHERE sp.participant_id = ep.participant_id 
                   AND (sp.exam_year = :year OR sp.valid_until >= :yearEnd)
                 ORDER BY sp.confirmed_at DESC LIMIT 1
                ) as swimming_proof_year
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            JOIN users u ON u.id = p.user_id  
            WHERE ep.exam_id = :examId 
              AND ep.final_medal IN ('bronze', 'silber', 'silver', 'gold')
        ";
        
        $params = ['examId' => $examId, 'year' => $examYear, 'yearEnd' => $examYearEnd];
        
        if ($selectedClass) {
            $params['selectedClass'] = $selectedClass;
        }

        // --- FILTER LOGIK ---

        // A) Explizite IDs
        if (!empty($selectedIds)) {
            $idArray = array_map('intval', explode(',', $selectedIds));
            if (count($idArray) > 0) {
                $sql .= " AND ep.id IN (" . implode(',', $idArray) . ")";
            }
        } 
        else {
            // B) Filterung via Gruppe und/oder Suche
            
            // 1. Gruppe filtern (KORREKTUR: actgrp/actuser)
            if ($selectedClass) {
                $sql .= " AND EXISTS (
                            SELECT 1 FROM members m 
                            JOIN groups g ON m.actgrp = g.act 
                            WHERE m.actuser = u.act AND g.name = :cls
                          )";
                $params['cls'] = $selectedClass;
            }

            // 2. Suchbegriff filtern
            if ($searchQuery) {
                $sql .= " AND (u.firstname LIKE :search OR u.lastname LIKE :search)";
                $params['search'] = '%' . $searchQuery . '%';
            }
        }

        // --- SORTIERUNG ---
        switch ($sort) {
            case 'firstname': $orderBy = "u.firstname $order, u.lastname ASC"; break;
            case 'points':    $orderBy = "ep.total_points $order, u.lastname ASC"; break;
            case 'age':       $orderBy = "ep.age_year $order, u.lastname ASC"; break;
            case 'lastname':
            default:          $orderBy = "u.lastname $order, u.firstname ASC"; break;
        }

        $participants = $conn->fetchAllAssociative($sql . " ORDER BY " . $orderBy, $params);

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

            if (empty($p['group_name'])) {
                $p['group_name'] = '-';
            }

            // Ergebnisse laden
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
                        $displayNr = 'A';
                        $displayRes = $res['verband'];
                    } else {
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
            'total'         => $summary['total'],        
            'medal'         => $summary['medal'],        
            'has_swimming'  => $summary['has_swimming'],
            'swimming_met_via' => $summary['swimming_met_via'] ?? ($summary['met_via'] ?? ''),
            'expiry'           => $summary['expiry'] ?? null,
        ]);
    }
}
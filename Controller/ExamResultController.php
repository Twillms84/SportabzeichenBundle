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
        $exams = $this->em->getRepository(Exam::class)->findBy([], ['examYear' => 'DESC']);
        return $this->render('@PulsRSportabzeichen/results/index.html.twig', ['exams' => $exams]);
    }

    #[Route('/exam/{id}', name: 'index', methods: ['GET'])]
    public function index(Exam $exam, Request $request): Response
    {
        $selectedClass = $request->query->get('class');

        // 1. Teilnehmer laden (DQL)
        // Wir holen nur Teilnehmer, die zu DIESEM Exam gehören
        $qb = $this->em->createQueryBuilder();
        $qb->select('ep', 'p', 'u', 'sp', 'res', 'd') // Alles vorladen ("Eager Loading")
           ->from(ExamParticipant::class, 'ep')
           ->join('ep.participant', 'p')
           ->join('p.user', 'u')
           ->leftJoin('p.swimmingProofs', 'sp')
           ->leftJoin('ep.results', 'res')
           ->leftJoin('res.discipline', 'd')
           
           // --- WICHTIGE ÄNDERUNG START ---
           // Wir filtern nicht nach einem Jahr-Feld in ep, sondern nach der Relation zum Exam-Objekt
           ->where('ep.exam = :exam')
           ->setParameter('exam', $exam)
           // --- WICHTIGE ÄNDERUNG ENDE ---

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
            // Check Swimming Validity
            $hasSwimming = false;
            $swimmingExpiry = null;
            $today = new \DateTime();
            
            foreach ($ep->getParticipant()->getSwimmingProofs() as $proof) {
                if ($proof->getValidUntil() >= $today) {
                    $hasSwimming = true;
                    // Finde das längste Datum
                    if ($swimmingExpiry === null || $proof->getValidUntil() > $swimmingExpiry) {
                        $swimmingExpiry = $proof->getValidUntil();
                    }
                }
            }

            // Results mappen für JS/Twig Matrix
            foreach ($ep->getResults() as $res) {
                $resultsData[$ep->getId()][$res->getDiscipline()->getId()] = [
                    'leistung' => $res->getLeistung(),
                    'points' => $res->getPoints(),
                    // Kleiner Tipp: Statt Strings 'gold' lieber Konstanten nutzen, aber so gehts erstmal:
                    'stufe' => $res->getPoints() === 3 ? 'gold' : ($res->getPoints() === 2 ? 'silber' : 'bronze'),
                    'category' => $res->getDiscipline()->getCategory()
                ];
            }
            
            // Wir packen das Objekt UND berechnete Werte rein
            $participantsData[] = [
                'entity' => $ep, // Zugriff im Twig: item.entity
                'ep_id' => $ep->getId(),
                'vorname' => $ep->getParticipant()->getUser()->getFirstname(),
                'nachname' => $ep->getParticipant()->getUser()->getLastname(),
                'klasse' => $ep->getParticipant()->getUser()->getAuxinfo(),
                'geschlecht' => $ep->getParticipant()->getGeschlecht(),
                // Falls getAgeYear() nicht existiert, musst du es in ExamParticipant.php erstellen oder hier berechnen:
                'age_year' => $ep->getAgeYear(), 
                'total_points' => $ep->getTotalPoints(),
                'final_medal' => $ep->getFinalMedal(),
                'has_swimming' => $hasSwimming,
                'swimming_expiry' => $swimmingExpiry
            ];
        }

        // 3. Anforderungen/Disziplinen laden
        // Hier nutzen wir das Jahr aus dem Exam-Objekt ($exam->getExamYear())
        $requirements = $this->em->createQueryBuilder()
            ->select('r', 'd')
            ->from(Requirement::class, 'r')
            ->join('r.discipline', 'd')
            ->where('r.jahr = :year') // In der Anforderungs-Tabelle heißt es meistens 'jahr'
            ->setParameter('year', $exam->getExamYear())
            ->orderBy('d.category', 'ASC')
            ->addOrderBy('r.auswahlnummer', 'ASC')
            ->getQuery()
            ->getResult();

        $disciplines = [];
        foreach ($requirements as $req) {
            /** @var Requirement $req */
            $d = $req->getDiscipline();
            
            // Initialisieren, falls noch nicht vorhanden
            if (!isset($disciplines[$d->getCategory()])) {
                 $disciplines[$d->getCategory()] = [];
            }

            // Um Duplikate zu vermeiden (da Requirements pro Alter/Geschlecht mehrfach vorkommen):
            // Wir nutzen die ID als Key für Eindeutigkeit
            if (!isset($disciplines[$d->getCategory()][$d->getId()])) {
                $disciplines[$d->getCategory()][$d->getId()] = [
                    'id' => $d->getId(),
                    'name' => $d->getName(),
                    'unit' => $d->getUnit(),
                    'category' => $d->getCategory()
                ];
            }
        }
        
        // Array Keys bereinigen (damit Twig einfach drüber loopen kann)
        foreach($disciplines as $kat => $vals) {
            $disciplines[$kat] = array_values($vals);
        }

        // Klassenliste für Filter (Direktes SQL ist in IServ okay für die Users Tabelle)
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

    #[Route('/exam/result/save', name: 'exam_result_save', methods: ['POST'])]
    public function saveExamResult(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)($data['ep_id'] ?? 0));
        $discipline = $this->em->getRepository(Discipline::class)->find((int)($data['discipline_id'] ?? 0));
        $leistung = isset($data['leistung']) && $data['leistung'] !== '' 
            ? (float)str_replace(',', '.', (string)$data['leistung']) 
            : null;

        if (!$ep || !$discipline) return new JsonResponse(['error' => 'Not found'], 404);

        // 1. Requirement suchen
        // Mapping IServ Geschlecht -> DB Geschlecht
        $gender = (str_starts_with(strtoupper($ep->getParticipant()->getGeschlecht() ?? ''), 'M')) ? 'MALE' : 'FEMALE';
        
        $req = $this->em->getRepository(Requirement::class)->createQueryBuilder('r')
            ->where('r.discipline = :disc')
            ->andWhere('r.jahr = :year')
            ->andWhere('r.geschlecht = :gender')
            ->andWhere(':age BETWEEN r.ageMin AND r.ageMax')
            ->setParameters([
                'disc' => $discipline,
                'year' => $ep->getExamYear(), // Oder $ep->getExam()->getExamYear() wenn Relation da ist
                'gender' => $gender,
                'age' => $ep->getAgeYear()
            ])
            ->getQuery()
            ->getOneOrNullResult();

        // 2. Punkte berechnen
        $points = 0;
        $stufe = 'none';

        if ($req && $leistung !== null && $leistung > 0) {
            $calc = strtoupper($discipline->getBerechnungsart() ?? 'BIGGER');
            
            // Helper convert string "1:30" or "1,5" to float logic could go here, 
            // but for now we assume simple floats in DB string fields
            $gold = (float)str_replace(',', '.', $req->getGold() ?? '0');
            $silber = (float)str_replace(',', '.', $req->getSilber() ?? '0');
            $bronze = (float)str_replace(',', '.', $req->getBronze() ?? '0');

            if ($calc === 'SMALLER') {
                if ($leistung <= $gold) { $points = 3; $stufe = 'gold'; }
                elseif ($leistung <= $silber) { $points = 2; $stufe = 'silber'; }
                elseif ($leistung <= $bronze) { $points = 1; $stufe = 'bronze'; }
            } else {
                if ($leistung >= $gold) { $points = 3; $stufe = 'gold'; }
                elseif ($leistung >= $silber) { $points = 2; $stufe = 'silber'; }
                elseif ($leistung >= $bronze) { $points = 1; $stufe = 'bronze'; }
            }
        }

        // 3. Konflikte bereinigen (gleiche Kategorie löschen)
        if ($req) {
            $cat = $discipline->getCategory();
            foreach ($ep->getResults() as $res) {
                if ($res->getDiscipline()->getId() !== $discipline->getId() 
                    && $res->getDiscipline()->getCategory() === $cat) {
                    $this->em->remove($res);
                }
            }
        }

        // 4. Speichern
        $result = $this->em->getRepository(ExamResult::class)->findOneBy(['examParticipant' => $ep, 'discipline' => $discipline]);
        
        if ($leistung === null || $leistung <= 0) {
            if ($result) {
                $this->em->remove($result);
                $ep->getResults()->removeElement($result);
            }
        } else {
            if (!$result) {
                $result = new ExamResult();
                $result->setExamParticipant($ep);
                $result->setDiscipline($discipline);
                $this->em->persist($result);
                $ep->getResults()->add($result);
            }
            $result->setLeistung($leistung);
            $result->setPoints($points);
            // Wenn Entity Feld 'stufe' nicht hat, ignorieren wir es hier im Objekt, aber im JSON geben wir es zurück
        }

        // 5. Schwimmnachweis
        $this->updateSwimmingProof($ep, $discipline, $points);

        $this->em->flush();
        
        // 6. Summary berechnen
        $summary = $this->calculateSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'points' => $points,
            'stufe' => $stufe,
            'category' => $discipline->getCategory(),
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal'],
            'has_swimming' => $summary['has_swimming']
        ]);
    }

    /**
     * Zentralisierte Schwimm-Logik
     */
    private function updateSwimmingProof(ExamParticipant $ep, Discipline $disc, int $points): void
    {
        $year = $ep->getExamYear(); // Oder aus $ep->getExam() holen
        $p = $ep->getParticipant();

        // A) Setzen
        if ($points > 0 && $disc->isSwimmingCategory()) {
            $proof = null;
            foreach ($p->getSwimmingProofs() as $sp) {
                if ($sp->getExamYear() === $year) {
                    $proof = $sp; break;
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

        // B) Löschen (Wenn Schwimmdisziplin gelöscht wurde oder Punkte 0)
        // Wir prüfen, ob es NOCH gültige Schwimm-Ergebnisse gibt
        $hasValidSwim = false;
        foreach ($ep->getResults() as $res) {
            // Achtung: Wenn wir oben remove() aufgerufen haben, müssen wir prüfen ob das Objekt noch verwaltet wird
            // Da wir aber oben removeElement gemacht haben, iteriert die Schleife nur über die "bleibenden".
            if ($res->getPoints() > 0 && $res->getDiscipline()->isSwimmingCategory()) {
                $hasValidSwim = true;
                break;
            }
        }

        if (!$hasValidSwim) {
            // Lösche proofs, die DISCIPLINE basiert sind UND aus diesem Jahr
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
            $k = $res->getDiscipline()->getCatgory();
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        
        // Check Swimming
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

        // Entity update
        // Falls du in ExamParticipant Setter für totalPoints/finalMedal hast:
        // $ep->setTotalPoints($total);
        // $ep->setFinalMedal($medal);
        // $this->em->flush(); // hier nicht flushen, macht der Caller

        // Wir führen ein direktes Update aus, falls Setter fehlen oder um sicher zu sein
        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        return ['total' => $total, 'medal' => $medal, 'has_swimming' => $hasSwimming];
    }
    
    // --- NEUE DRUCKFUNKTION ---
    // Route angepasst: Enthält jetzt {examId}, damit wir wissen, WELCHES Sportfest gedruckt wird.
    #[Route('/exam/{examId}/print_groupcard', name: 'print_groupcard', methods: ['GET'])]
    public function printGroupcard(int $examId, Request $request, Connection $conn): Response
    {
    $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');
    $selectedClass = $request->query->get('class');

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
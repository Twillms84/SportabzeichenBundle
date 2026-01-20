<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
use PulsR\SportabzeichenBundle\Entity\ExamResult;
use PulsR\SportabzeichenBundle\Entity\Requirement;
use PulsR\SportabzeichenBundle\Entity\SwimmingProof;

class SportabzeichenService
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {}

    /**
     * Zentrale Berechnung der Punkte basierend auf Disziplin und Leistung
     */
    public function calculateResult(Discipline $discipline, int $year, string $gender, int $age, ?float $leistung): array
        {
            // 1. Einheit prüfen
            // (Stelle sicher, dass der Getter in deiner Entity so heißt, z.B. getUnit() oder getEinheit())
            $unit = $discipline->getUnit(); 

            // 2. Verbands-Logik präzisieren
            // Wir geben nur pauschal Gold, wenn Verband existiert UND KEINE Maßeinheit da ist.
            // Turnen hat UNIT_PIECES, fällt hier also durch (FALSE).
            // DLRG hat UNIT_NONE, wird hier abgefangen (TRUE).
            $istPauschalVerband = !empty($discipline->getVerband()) && $unit === 'NONE';

            $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement($discipline, $year, $gender, $age);

            // 3. Automatisches Gold nur für pauschale Verbände (DLRG)
            if ($istPauschalVerband) {
                return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
            }

            // 4. Berechnung für Turnen, Laufen, Schwimmen etc.
            // Wenn Turnen ausgewählt wurde, aber noch keine Leistung eingetragen ist ($leistung ist null oder 0),
            // landen wir hier und geben 0 Punkte (none) zurück. Das ist das gewünschte Verhalten (Feld weiß).
            if ($leistung === null || $leistung <= 0 || !$req) {
                return ['points' => 0, 'stufe' => 'none', 'req' => $req];
            }

            // --- Ab hier normale Berechnung (Turnen, wenn Zahl eingegeben wurde) ---

            $calc = strtoupper($discipline->getBerechnungsart() ?? 'GREATER');
            $vG = (float)$req->getGold();
            $vS = (float)$req->getSilver();
            $vB = (float)$req->getBronze();
            
            $p = 0; $s = 'none';
            
            if ($calc === 'SMALLER') {
                // Logik für Zeiten (Laufen, Radfahren, Schwimmen auf Zeit)
                if ($leistung <= $vG && $vG > 0) { $p = 3; $s = 'gold'; }
                elseif ($leistung <= $vS && $vS > 0) { $p = 2; $s = 'silber'; }
                elseif ($leistung <= $vB && $vB > 0) { $p = 1; $s = 'bronze'; }
            } else {
                // Logik für Mengen/Weiten (Werfen, Springen, TURNEN)
                if ($leistung >= $vG) { $p = 3; $s = 'gold'; }
                elseif ($leistung >= $vS) { $p = 2; $s = 'silber'; }
                elseif ($leistung >= $vB) { $p = 1; $s = 'bronze'; }
            }
            
            return ['points' => $p, 'stufe' => $s, 'req' => $req];
        }

    /**
     * Aktualisiert den Schwimmnachweis basierend auf der erbrachten Disziplin
     */
    public function updateSwimmingProof(ExamParticipant $ep, Discipline $discipline, int $points, ?Requirement $req = null): void
    {
        $examYear = $ep->getExam()->getYear();
        $isSwimmingRelevant = ($req && $req->isSwimmingProof()) || !empty($discipline->getVerband());
        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $ep->getParticipant(),
            'examYear' => $examYear
        ]);

        if ($isSwimmingRelevant && $points > 0) {
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($ep->getParticipant());
                $proof->setExamYear($examYear);
                $this->em->persist($proof);
            }
            
            $age = $ep->getAgeYear();
            $validUntilYear = ($age <= 17) ? ($examYear + (18 - $age)) : ($examYear + 4);
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
            // Wir speichern hier explizit, dass es über eine Disziplin kam
            $proof->setRequirementMetVia('DISCIPLINE:' . $discipline->getId());
        } elseif ($proof && $proof->getRequirementMetVia() === 'DISCIPLINE:' . $discipline->getId()) {
            // Wenn der Nachweis an diese Disziplin gebunden ist, aber keine Punkte mehr da sind -> löschen
            if (!$isSwimmingRelevant || $points === 0) {
                $this->em->remove($proof);
            }
        }
    }

    /**
     * Berechnet die Gesamtpunktzahl und die finale Medaille
     * NEU: Prüft jetzt auch, ob ALLE 4 Kategorien bedient wurden!
     */
    public function syncSummary(ExamParticipant $ep): array
    {
        $cats = ['Ausdauer' => 0, 'Kraft' => 0, 'Schnelligkeit' => 0, 'Koordination' => 0];
        
        foreach ($ep->getResults() as $res) {
            // Annahme: getCategory() gibt den String zurück, der dem Array-Key entspricht
            // Falls getCategory() ein Objekt ist, müsste hier ->getName() stehen.
            $k = $res->getDiscipline()->getCategory(); 
            
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        
        // --- NEU: Zählen, wie viele Kategorien erfüllt sind ---
        // array_filter entfernt alle Einträge mit 0 Punkten
        $filledCategories = count(array_filter($cats, fn($points) => $points > 0));
        // -----------------------------------------------------

        // Initialisierung der Variablen mit Standardwerten
        $hasSwimming = false;
        $metVia = 'nicht vorhanden';
        $expiryYear = null;
        $today = new \DateTime();

        // Schwimmnachweise prüfen
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            if ($sp->getExamYear() == $ep->getExam()->getYear() || ($sp->getValidUntil() && $sp->getValidUntil() >= $today)) {
                $hasSwimming = true;
                
                // Anzeige-Logik für "Erreicht durch..."
                $rawVia = $sp->getRequirementMetVia(); // z.B. "DISCIPLINE:12"
                
                if (method_exists($sp, 'getDiscipline') && $sp->getDiscipline()) {
                    $metVia = $sp->getDiscipline()->getName();
                } elseif ($rawVia && str_starts_with($rawVia, 'DISCIPLINE:')) {
                     // Fallback: Wenn wir nur die ID im String haben, versuchen wir es schön anzuzeigen
                     // Hier könnte man theoretisch noch die ID auflösen, aber für JSON reicht auch ein Hinweis
                     $metVia = 'Disziplin (Auto)';
                } elseif ($rawVia) {
                    $metVia = $rawVia;
                } else {
                    $metVia = 'Nachweis vorhanden';
                }
                
                $expiryYear = $sp->getValidUntil() ? $sp->getValidUntil()->format('Y') : ($sp->getExamYear() + 4);
                break;
            }
        }

        // Medaille berechnen
        // REGEL: Nur Gold/Silber/Bronze, wenn Schwimmen da ist UND alle 4 Kategorien bedient sind.
        $medal = 'none';
        
        if ($hasSwimming && $filledCategories === 4) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silver'; // Einheitlich 'silver' statt 'silber' für DB values empfohlen
            elseif ($total >= 4) $medal = 'bronze';
        }

        // Direktes SQL Update für Performance
        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        return [
            'total' => $total, 
            'medal' => $medal, 
            'has_swimming' => $hasSwimming,
            'met_via'      => $metVia, 
            'expiry'       => $expiryYear,
        ];
    }

    public function createSwimmingProofFromDiscipline(ExamParticipant $ep, Discipline $discipline): void
    {
        $participant = $ep->getParticipant();
        
        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $participant
        ]);

        if (!$proof) {
            $proof = new SwimmingProof();
            $proof->setParticipant($participant);
            $this->em->persist($proof);
        }

        // Einheitliches Format nutzen "DISCIPLINE:ID" oder Name
        // Da updateSwimmingProof "DISCIPLINE:ID" nutzt, sollten wir konsistent bleiben,
        // oder sicherstellen, dass syncSummary beides kann.
        // Hier nehme ich den Namen, wie in deinem Snippet, aber Vorsicht beim Mischen.
        $proof->setRequirementMetVia($discipline->getName());
        
        $proof->setExamYear($ep->getExam()->getYear());
        
        $validUntil = (new \DateTime())->setDate((int)$ep->getExam()->getYear() + 4, 12, 31);
        $proof->setValidUntil($validUntil);
        $proof->setConfirmedAt(new \DateTime());

        if (method_exists($participant, 'setSwimmingProof')) {
            $participant->setSwimmingProof(true);
        }

        $this->em->flush();
    }
}
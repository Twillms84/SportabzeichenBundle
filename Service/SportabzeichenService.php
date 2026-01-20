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
        $unit = $discipline->getUnit(); 
        
        // FIX: Aggressivere Prüfung auf "Keine Einheit" (Verband/DLRG)
        // Prüft auf 'NONE', 'UNIT_NONE' oder leer.
        $isUnitNone = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));

        // Nur wenn Verband existiert UND Einheit NONE ist -> Pauschal Gold
        $istPauschalVerband = !empty($discipline->getVerband()) && $isUnitNone;

        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement($discipline, $year, $gender, $age);

        // 1. Automatisch Gold (Verbandsabzeichen ohne Werteingabe)
        if ($istPauschalVerband) {
            // Wir geben hier Punkte zurück, auch wenn keine Leistung da ist!
            return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
        }

        // 2. Normale Disziplinen: Wenn leer -> 0 Punkte
        if ($leistung === null || $leistung <= 0 || !$req) {
            return ['points' => 0, 'stufe' => 'none', 'req' => $req];
        }

        // --- Ab hier normale Berechnung ---
        $calc = strtoupper($discipline->getBerechnungsart() ?? 'GREATER');
        $vG = (float)$req->getGold();
        $vS = (float)$req->getSilver();
        $vB = (float)$req->getBronze();
        
        $p = 0; $s = 'none';
        
        if ($calc === 'SMALLER') {
            // Zeiten
            if ($leistung <= $vG && $vG > 0) { $p = 3; $s = 'gold'; }
            elseif ($leistung <= $vS && $vS > 0) { $p = 2; $s = 'silber'; }
            elseif ($leistung <= $vB && $vB > 0) { $p = 1; $s = 'bronze'; }
        } else {
            // Weiten / Mengen
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

        // FIX: Hier prüfen wir direkt an der Disziplin, ob es ein Schwimmnachweis ist.
        // Falls deine Entity Methode getSwimming() heißt, bitte anpassen.
        $disciplineIsSwimming = method_exists($discipline, 'isSwimming') ? $discipline->isSwimming() : ($discipline->getCategory() === 'Schwimmen');
        
        // Es gilt als Schwimmnachweis, wenn:
        // 1. Die Disziplin selbst "swimming = true" hat
        // 2. ODER es ein Verbandsabzeichen (DLRG) ist
        // 3. ODER die Requirement sagt "isSwimmingProof" (Legacy Check)
        $isSwimmingRelevant = $disciplineIsSwimming || !empty($discipline->getVerband()) || ($req && $req->isSwimmingProof());

        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $ep->getParticipant(),
            'examYear' => $examYear
        ]);

        if ($isSwimmingRelevant && $points > 0) {
            // Erstellen oder Aktualisieren
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($ep->getParticipant());
                $proof->setExamYear($examYear);
                $this->em->persist($proof);
            }
            
            $age = $ep->getAgeYear();
            // Gültigkeit: Kinder/Jugend (<=17) bis 18. LJ, Erwachsene 5 Jahre (Aktuelles + 4)
            $validUntilYear = ($age <= 17) ? ($examYear + (18 - $age)) : ($examYear + 4);
            
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
            
            // Speichern, woher der Nachweis kommt
            $proof->setRequirementMetVia('DISCIPLINE:' . $discipline->getId());

        } elseif ($proof && $proof->getRequirementMetVia() === 'DISCIPLINE:' . $discipline->getId()) {
            // Wenn der Nachweis an DIESE Disziplin gebunden war, aber jetzt 0 Punkte sind (gelöscht/schlechter)
            // -> Nachweis entfernen!
            if (!$isSwimmingRelevant || $points === 0) {
                $this->em->remove($proof);
            }
        }
    }

    /**
     * Berechnet die Gesamtpunktzahl und die finale Medaille
     */
    public function syncSummary(ExamParticipant $ep): array
    {
        $cats = ['Ausdauer' => 0, 'Kraft' => 0, 'Schnelligkeit' => 0, 'Koordination' => 0];
        
        foreach ($ep->getResults() as $res) {
            $k = $res->getDiscipline()->getCategory(); 
            // Falls Kategorie-Name in DB anders ist, hier mappen
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        // Prüfen, ob alle 4 Kategorien mindestens 1 Punkt haben
        $filledCategories = count(array_filter($cats, fn($points) => $points > 0));

        // --- Schwimmen Check ---
        $hasSwimming = false;
        $metVia = 'fehlt';
        $expiryYear = null;
        $today = new \DateTime();

        // Refresh nötig, falls gerade ein Proof hinzugefügt wurde, der im Cache noch fehlt? 
        // Normalerweise reicht der Hibernate Cache, aber sicherheitshalber iterieren wir über die Collection.
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            $isValidDate = ($sp->getValidUntil() && $sp->getValidUntil() >= $today);
            $isCurrentYear = ($sp->getExamYear() == $ep->getExam()->getYear());

            if ($isCurrentYear || $isValidDate) {
                $hasSwimming = true;
                
                // Schönen Text für JSON bauen
                $rawVia = $sp->getRequirementMetVia(); 
                if ($rawVia && str_starts_with($rawVia, 'DISCIPLINE:')) {
                    // Da wir hier keine Discipline Entity laden wollen (teuer), generischer Text
                    // ODER: Du holst den Namen im Controller. Hier reicht oft:
                    $metVia = 'Disziplin erfüllt'; 
                } elseif ($rawVia) {
                    $metVia = $rawVia;
                } else {
                    $metVia = 'Vorhanden';
                }
                
                $expiryYear = $sp->getValidUntil() ? $sp->getValidUntil()->format('Y') : '';
                break; // Ersten gültigen Nachweis nehmen
            }
        }

        // Medaille berechnen
        $medal = 'none';
        if ($hasSwimming && $filledCategories === 4) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silver';
            elseif ($total >= 4) $medal = 'bronze';
        }

        // DB Update
        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        return [
            'total' => $total, 
            'medal' => $medal, 
            'has_swimming' => $hasSwimming,
            'met_via' => $metVia, 
            'expiry' => $expiryYear,
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
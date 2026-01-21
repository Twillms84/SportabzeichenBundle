<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
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
        
        // Check auf "Keine Einheit" (z.B. Verband/DLRG)
        $isUnitNone = ($unit === 'NONE' || $unit === 'UNIT_NONE' || empty($unit));
        $verband = $discipline->getVerband();

        // Pauschal Gold: Wenn ein Verband existiert UND Einheit NONE ist
        $istPauschalVerband = !empty($verband) && $isUnitNone;

        // Anforderung aus DB laden
        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement($discipline, $year, $gender, $age);

        // 1. Automatisch Gold (Verbandsabzeichen ohne Werteingabe)
        if ($istPauschalVerband) {
            // Wir geben Punkte zurück, auch wenn keine Leistung als Zahl vorliegt
            return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
        }

        // 2. Normale Disziplinen: Wenn leer oder <= 0 (außer es gäbe Disziplinen wo 0 gültig wäre, hier unwahrscheinlich) -> 0 Punkte
        // Hinweis: Wenn kein Requirement ($req) gefunden wurde (z.B. zu alt/jung), gibt es auch 0 Punkte.
        if ($leistung === null || $leistung <= 0 || !$req) {
            return ['points' => 0, 'stufe' => 'none', 'req' => $req];
        }

        // --- Berechnung anhand der Werte ---
        $calc = strtoupper($discipline->getBerechnungsart() ?? 'GREATER');
        $vG = (float)$req->getGold();
        $vS = (float)$req->getSilver();
        $vB = (float)$req->getBronze();
        
        $p = 0; 
        $s = 'none';
        
        if ($calc === 'SMALLER') {
            // Laufdisziplinen (Zeit): Kleiner ist besser
            // Werte > 0 prüfen, um Division by Zero oder logische Fehler bei leeren Anforderungen zu vermeiden
            if ($vG > 0 && $leistung <= $vG) { $p = 3; $s = 'gold'; }
            elseif ($vS > 0 && $leistung <= $vS) { $p = 2; $s = 'silber'; }
            elseif ($vB > 0 && $leistung <= $vB) { $p = 1; $s = 'bronze'; }
        } else {
            // Wurf/Sprung (Weite/Menge): Größer ist besser
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
        $participant = $ep->getParticipant();

        // Prüfen, ob dies eine Schwimm-Disziplin ist
        $disciplineIsSwimming = method_exists($discipline, 'isSwimming') 
            ? $discipline->isSwimming() 
            : ($discipline->getCategory() === 'Schwimmen');
        
        // Bedingungen für Schwimmnachweis
        $isSwimmingRelevant = $disciplineIsSwimming 
            || !empty($discipline->getVerband()) 
            || ($req && $req->isSwimmingProof());

        $repo = $this->em->getRepository(SwimmingProof::class);
        $proof = $repo->findOneBy([
            'participant' => $participant,
            'examYear' => $examYear
        ]);

        $proofIdentifier = 'DISCIPLINE:' . $discipline->getId();

        if ($isSwimmingRelevant && $points > 0) {
            // A) Erstellen oder Aktualisieren
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($participant);
                $proof->setExamYear($examYear);
                $this->em->persist($proof);
                
                // WICHTIG: Damit syncSummary im gleichen Request den Nachweis findet:
                $participant->addSwimmingProof($proof);
            }
            
            $age = $ep->getAgeYear(); // Alter im Prüfungsjahr
            
            // Gültigkeit: 
            // Kinder/Jugend (<=17): Gültig bis zum 18. Geburtstag? DOSB sagt oft "einmalig im Jugendbereich".
            // Erwachsene: Prüfungsjahr + 4 weitere Jahre = 5 Jahre Gültigkeit.
            $validUntilYear = ($age <= 17) ? ($examYear + (18 - $age)) : ($examYear + 4);
            
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
            
            // Speichern, dass dieser Nachweis durch diese Disziplin erbracht wurde
            $proof->setRequirementMetVia($proofIdentifier);

        } elseif ($proof && $proof->getRequirementMetVia() === $proofIdentifier) {
            // B) Löschen
            // Wenn der existierende Nachweis genau von DIESER Disziplin kam, 
            // aber jetzt keine Punkte mehr da sind (oder Disziplin geändert wurde) -> löschen.
            if (!$isSwimmingRelevant || $points === 0) {
                $this->em->remove($existingProof);
            }
        }
    }

    /**
     * Berechnet die Gesamtpunktzahl und die finale Medaille
     * und schreibt sie direkt in die DB (Performance).
     */
    public function syncSummary(ExamParticipant $ep): array
    {
        // 1. Punkte pro Kategorie ermitteln (Bestwert zählt)
        $cats = ['Ausdauer' => 0, 'Kraft' => 0, 'Schnelligkeit' => 0, 'Koordination' => 0];
        
        foreach ($ep->getResults() as $res) {
            $d = $res->getDiscipline();
            if (!$d) continue;

            $k = $d->getCategory(); 
            // Falls Kategorie-Mapping nötig ist, hier einfügen. 
            // Wir gehen davon aus, dass String-Match passt.
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        // Prüfen, ob alle 4 Kategorien > 0 sind
        $filledCategories = count(array_filter($cats, fn($points) => $points > 0));

        // 2. Schwimmen Check
        $hasSwimming = false;
        $metVia = 'fehlt';
        $expiryYear = null;
        $today = new \DateTime();
        $examYear = $ep->getExam()->getYear();

        // Iteration über Collection (nutzt Doctrine Cache, falls geladen)
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            $isValidDate = ($sp->getValidUntil() && $sp->getValidUntil() >= $today);
            $isCurrentExamYear = ($sp->getExamYear() == $examYear);

            // Nachweis gilt, wenn er aus dem aktuellen Jahr stammt ODER noch gültig ist
            if ($isCurrentExamYear || $isValidDate) {
                $hasSwimming = true;
                
                // Text für Frontend aufbereiten
                $rawVia = $sp->getRequirementMetVia(); 
                if ($rawVia && str_starts_with($rawVia, 'DISCIPLINE:')) {
                    $metVia = 'Disziplin erfüllt'; 
                } elseif ($rawVia) {
                    $metVia = $rawVia;
                } else {
                    $metVia = 'Vorhanden';
                }
                
                $expiryYear = $sp->getValidUntil() ? $sp->getValidUntil()->format('Y') : '';
                break; // Ein gültiger Nachweis reicht
            }
        }

        // 3. Medaille berechnen
        $medal = 'none';
        // Voraussetzung: Schwimmnachweis + Leistungen in allen 4 Kategorien
        if ($hasSwimming && $filledCategories === 4) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silver';
            elseif ($total >= 4) $medal = 'bronze';
        }

        // 4. DB Update (Raw SQL für Performance, um Listener-Loops zu vermeiden)
        // Achtung: Wenn Entity später im Code noch genutzt wird, ist sie "stale".
        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        return [
            'total' => $total, 
            'medal' => $medal, 
            'has_swimming' => $hasSwimming,
            'swimming_met_via' => $metVia, // Konsistente Benennung für JS
            'expiry' => $expiryYear,
        ];
    }
    
    /**
     * Manuelle Erstellung eines Schwimmnachweises (z.B. über Button)
     */
    public function createSwimmingProofFromDiscipline(ExamParticipant $ep, Discipline $discipline): void
    {
        $participant = $ep->getParticipant();
        
        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $participant,
            'examYear' => $ep->getExam()->getYear()
        ]);

        if (!$proof) {
            $proof = new SwimmingProof();
            $proof->setParticipant($participant);
            $this->em->persist($proof);
        }

        $proof->setRequirementMetVia($discipline->getName());
        $proof->setExamYear($ep->getExam()->getYear());
        
        // 5 Jahre Gültigkeit (Aktuelles Jahr + 4)
        $validUntil = (new \DateTime())->setDate((int)$ep->getExam()->getYear() + 4, 12, 31);
        $proof->setValidUntil($validUntil);
        $proof->setConfirmedAt(new \DateTime());

        // Falls es ein Boolean Flag im Participant gibt (Legacy)
        if (method_exists($participant, 'setSwimmingProof')) {
            $participant->setSwimmingProof(true);
        }

        $this->em->flush();
    }
}
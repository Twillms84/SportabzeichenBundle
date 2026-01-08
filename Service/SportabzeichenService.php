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
        $istVerband = !empty($discipline->getVerband());
        $req = $this->em->getRepository(Requirement::class)->findMatchingRequirement($discipline, $year, $gender, $age);

        if ($istVerband) {
            return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
        }

        if ($leistung === null || $leistung <= 0 || !$req) {
            return ['points' => 0, 'stufe' => 'none', 'req' => $req];
        }

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
            $proof->setRequirementMetVia('DISCIPLINE:' . $discipline->getId());
        } elseif ($proof && $proof->getRequirementMetVia() === 'DISCIPLINE:' . $discipline->getId()) {
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
            if (isset($cats[$k]) && $res->getPoints() > $cats[$k]) {
                $cats[$k] = $res->getPoints();
            }
        }
        
        $total = array_sum($cats);
        
        // Initialisierung der Variablen mit Standardwerten
        $hasSwimming = false;
        $metVia = 'nicht vorhanden';
        $expiryYear = null;
        $today = new \DateTime();

        // Schwimmnachweise prüfen
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            // Prüfung: Gültig im aktuellen Prüfungsjahr ODER Ablaufdatum liegt in der Zukunft
            if ($sp->getExamYear() == $ep->getExam()->getYear() || ($sp->getValidUntil() && $sp->getValidUntil() >= $today)) {
                $hasSwimming = true;
                // Bestimmen, woher der Nachweis kommt (z.B. "Bronze Abzeichen" oder "Manuell")
                // Nutze diese sicherere Variante:
                if (method_exists($sp, 'getDiscipline') && $sp->getDiscipline()) {
                    $metVia = $sp->getDiscipline()->getName();
                } elseif (method_exists($sp, 'getType')) {
                    $metVia = $sp->getType();
                } else {
                    $metVia = 'Nachweis vorhanden';
                }
                $expiryYear = $sp->getValidUntil() ? $sp->getValidUntil()->format('Y') : $sp->getExamYear() + 4;
                break;
            }
        }

        // Medaille berechnen
        $medal = 'none';
        if ($hasSwimming) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silber';
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
        
        // 1. Prüfen, ob für diesen Teilnehmer bereits ein Nachweis existiert (Egal aus welchem Jahr)
        // Das verhindert den "Unique violation" Fehler 23505
        $proof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
            'participant' => $participant
        ]);

        if (!$proof) {
            // Falls kein Eintrag existiert, neuen anlegen
            $proof = new SwimmingProof();
            $proof->setParticipant($participant);
            $this->em->persist($proof);
        }

        // 2. Die gewählte Disziplin (z.B. Bronze-Abzeichen) hinterlegen
        $proof->setDiscipline($discipline);
        $proof->setExamYear($ep->getExam()->getYear());
        
        // 3. Gültigkeit berechnen (Standard: Jahr der Prüfung + 4 Jahre bis Jahresende)
        $validUntil = (new \DateTime())->setDate((int)$ep->getExam()->getYear() + 4, 12, 31);
        $proof->setValidUntil($validUntil);

        // 4. Den Teilnehmer als "hat Schwimmnachweis" markieren, falls das Feld existiert
        // (Optional, falls du ein extra Boolean-Feld in der Tabelle hast)
        if (method_exists($participant, 'setSwimmingProof')) {
            $participant->setSwimmingProof(true);
        }
    }
}
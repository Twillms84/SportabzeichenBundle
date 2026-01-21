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
            // Wir geben 3 Punkte zurück. Requirement kann null sein, das ist ok.
            return ['points' => 3, 'stufe' => 'gold', 'req' => $req];
        }

        // 2. Normale Disziplinen: Wenn leer oder <= 0
        // Ausnahme: Wenn kein Requirement ($req) gefunden wurde (z.B. zu alt/jung), gibt es 0 Punkte.
        if ($leistung === null || $leistung <= 0 || !$req) {
            return ['points' => 0, 'stufe' => 'none', 'req' => $req];
        }

        // --- Berechnung anhand der Werte (Tabelle) ---
        $calc = strtoupper($discipline->getBerechnungsart() ?? 'GREATER');
        $vG = (float)$req->getGold();
        $vS = (float)$req->getSilver();
        $vB = (float)$req->getBronze();
        
        $p = 0; 
        $s = 'none';
        
        if ($calc === 'SMALLER') {
            // Laufdisziplinen (Zeit): Kleiner ist besser (z.B. 10,5s ist besser als 11s)
            // Werte > 0 prüfen, um Fehler bei leeren Anforderungen zu vermeiden
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
     * Aktualisiert den Schwimmnachweis automatisch basierend auf der erbrachten Disziplin.
     * Erkennt, ob es eine Schwimmdisziplin oder ein Verbandsabzeichen ist.
     */
    public function updateSwimmingProof(ExamParticipant $ep, Discipline $discipline, int $points, ?Requirement $req = null): void
    {
        $examYear = (int)$ep->getExam()->getYear();
        $participant = $ep->getParticipant();

        // --- 1. RELEVANZ PRÜFEN ---
        $isSwimmingRelevant = false;

        // Prio A: Das Flag direkt am Requirement (Deine Requirement-Entity)
        // Das deckt die Fälle ab, wo in der DB 'schwimmnachweis = true' gesetzt ist.
        if ($req !== null && $req->isSwimmingProof()) {
            $isSwimmingRelevant = true;
        }
        // Prio B: Fallback auf die Disziplin (Namen oder Kategorie)
        // Falls mal kein Requirement da ist oder es nicht explizit gesetzt wurde.
        elseif (method_exists($discipline, 'isSwimmingCategory') && $discipline->isSwimmingCategory()) {
            $isSwimmingRelevant = true;
        }
        // Prio C: Fallback auf alte Getter-Namen (Sicherheitshalber)
        elseif (method_exists($discipline, 'isSwimming') && $discipline->isSwimming()) {
            $isSwimmingRelevant = true;
        }

        // Wenn es nichts mit Schwimmen zu tun hat -> Abbruch.
        if (!$isSwimmingRelevant) {
            return;
        }

        // --- 2. NACHWEIS VERARBEITEN ---
        
        $repo = $this->em->getRepository(SwimmingProof::class);
        
        // Prüfen, ob für dieses Jahr schon ein Nachweis existiert
        $existingProof = $repo->findOneBy([
            'participant' => $participant,
            'examYear' => $examYear
        ]);

        // Eindeutige Kennung, damit wir wissen, dass der Nachweis AUTOMATISCH durch diese Disziplin kam
        $proofIdentifier = 'DISCIPLINE:' . $discipline->getId();

        // FALL A: Leistung wurde erbracht (Gold/Silber/Bronze bzw. Verband erfolgreich)
        if ($points > 0) {
            
            if ($existingProof) {
                // Wenn schon ein manueller Upload da ist (via ist NULL oder anders), nicht überschreiben!
                $via = $existingProof->getRequirementMetVia();
                if ($via && $via !== $proofIdentifier) {
                    return; // Manuellen Nachweis behalten
                }
                $proof = $existingProof;
            } else {
                // Neuen Nachweis anlegen
                $proof = new SwimmingProof();
                $proof->setParticipant($participant);
                $proof->setExamYear($examYear);
                $this->em->persist($proof);
            }

            // Gültigkeit berechnen (Kinder/Jugend < 18: bis Volljährigkeit, Erw: 5 Jahre)
            $age = $ep->getAgeYear(); // oder wie du das Alter ermittelst
            
            // Regelwerk: 
            // Erwachsene: Gültigkeit 5 Jahre (Prüfungsjahr + 4)
            // Jugend: Gültig bis zum 18. Lebensjahr (bzw. Jahresende)
            if ($age < 18) {
                $yearsTo18 = 18 - $age;
                $validUntilYear = $examYear + $yearsTo18; 
            } else {
                $validUntilYear = $examYear + 4; 
            }
            
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31 23:59:59"));
            
            // Wichtig: Markieren, dass dieser Nachweis durch diese Disziplin entstand
            $proof->setRequirementMetVia($proofIdentifier);

            $this->em->flush();
        } 
        
        // FALL B: Leistung wurde zurückgenommen (z.B. Note gelöscht -> 0 Punkte)
        elseif ($points === 0 && $existingProof) {
            // Nur löschen, wenn er auch von DIESER Disziplin erstellt wurde
            if ($existingProof->getRequirementMetVia() === $proofIdentifier) {
                $this->em->remove($existingProof);
                $this->em->flush();
            }
        }
    }

    /**
     * Berechnet die Gesamtpunktzahl und die finale Medaille.
     * Aktualisiert die Datenbank direkt via SQL (Performance).
     */
    public function syncSummary(ExamParticipant $ep): array
    {
        // 1. Punkte pro Kategorie ermitteln (Bestwert zählt)
        // Array-Schlüssel müssen exakt den Kategorie-Namen in der DB entsprechen
        $cats = ['Ausdauer' => 0, 'Kraft' => 0, 'Schnelligkeit' => 0, 'Koordination' => 0];
        
        foreach ($ep->getResults() as $res) {
            $d = $res->getDiscipline();
            if (!$d) continue;

            $k = $d->getCategory(); 
            // Nur berücksichtigen, wenn Kategorie gültig ist
            if (isset($cats[$k])) {
                if ($res->getPoints() > $cats[$k]) {
                    $cats[$k] = $res->getPoints();
                }
            }
        }
        
        $total = array_sum($cats);
        
        // Prüfen, ob alle 4 Kategorien > 0 sind (Voraussetzung für Medaille)
        $filledCategories = count(array_filter($cats, fn($points) => $points > 0));

        // 2. Schwimmen Check
        $hasSwimming = false;
        $metVia = 'fehlt';
        $expiryYear = null;
        $today = new \DateTime();
        $examYear = (int)$ep->getExam()->getYear();

        // Wir prüfen alle vorhandenen Nachweise des Teilnehmers
        foreach ($ep->getParticipant()->getSwimmingProofs() as $sp) {
            $validUntil = $sp->getValidUntil();
            
            // Ein Nachweis gilt, wenn:
            // a) Er explizit für dieses Prüfungsjahr eingetragen ist (isCurrentExamYear)
            // b) Er noch gültig ist (isValidDate)
            $isCurrentExamYear = ((int)$sp->getExamYear() === $examYear);
            $isValidDate = ($validUntil && $validUntil >= $today);

            if ($isCurrentExamYear || $isValidDate) {
                $hasSwimming = true;
                
                // Text für Frontend aufhübschen
                $rawVia = $sp->getRequirementMetVia(); 
                if ($rawVia && str_starts_with($rawVia, 'DISCIPLINE:')) {
                    $metVia = 'Disziplin erfüllt'; 
                } elseif ($rawVia) {
                    $metVia = $rawVia;
                } else {
                    $metVia = 'Vorhanden';
                }
                
                $expiryYear = $validUntil ? $validUntil->format('Y') : '';
                break; // Ein gültiger Nachweis reicht
            }
        }

        // 3. Medaille berechnen
        $medal = 'none';
        // Voraussetzung: Schwimmnachweis JA + Leistungen in allen 4 Kategorien JA
        if ($hasSwimming && $filledCategories === 4) {
            if ($total >= 11) $medal = 'gold';
            elseif ($total >= 8) $medal = 'silver';
            elseif ($total >= 4) $medal = 'bronze';
        }

        // 4. DB Update (Raw SQL für Performance, vermeidet Event-Loops)
        $this->em->getConnection()->update('sportabzeichen_exam_participants', 
            ['total_points' => $total, 'final_medal' => $medal], 
            ['id' => $ep->getId()]
        );

        // Rückgabe an den Controller für JSON Response
        return [
            'total' => $total, 
            'medal' => $medal, 
            'has_swimming' => $hasSwimming,
            'swimming_met_via' => $metVia,
            'expiry' => $expiryYear,
        ];
    }
    
    /**
     * Legacy/Helper: Manuelle Erstellung (falls nötig)
     */
    public function createSwimmingProofFromDiscipline(ExamParticipant $ep, Discipline $discipline): void
    {
        // Kann auf die Hauptfunktion umgeleitet werden, um Logik zu zentralisieren
        $this->updateSwimmingProof($ep, $discipline, 3);
    }
}
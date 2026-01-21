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
        $examYear = $ep->getExam()->getYear();
        $participant = $ep->getParticipant();

        // 1. Prüfen, ob dies überhaupt relevant für Schwimmen ist
        // (Kategorie Schwimmen, ODER Verbandsabzeichen (DLRG), ODER explizites Requirement-Flag)
        $disciplineIsSwimming = method_exists($discipline, 'isSwimming') 
            ? $discipline->isSwimming() 
            : ($discipline->getCategory() === 'Schwimmen');
        
        $isSwimmingRelevant = $disciplineIsSwimming 
            || !empty($discipline->getVerband()) 
            || ($req && $req->isSwimmingProof());

        if (!$isSwimmingRelevant) {
            return;
        }

        $repo = $this->em->getRepository(SwimmingProof::class);
        $proof = $repo->findOneBy([
            'participant' => $participant,
            'examYear' => $examYear
        ]);

        // Wir nutzen "DISCIPLINE:ID" als Marker, damit wir wissen, dass dieser Proof automatisch generiert wurde
        $proofIdentifier = 'DISCIPLINE:' . $discipline->getId();

        if ($points > 0) {
            // --- FALL A: Gültige Leistung -> Nachweis erstellen/aktualisieren ---
            
            if (!$proof) {
                $proof = new SwimmingProof();
                $proof->setParticipant($participant);
                $proof->setExamYear((string)$examYear);
                $this->em->persist($proof);
            }

            // Gültigkeit berechnen
            // Erwachsene: 5 Jahre (Prüfungsjahr + 4)
            // Kinder/Jugend: Gültig bis zum 18. Lebensjahr (bzw. max 5 Jahre, Logik hier: bis 18)
            $age = $ep->getAgeYear();
            $validUntilYear = ($age <= 17) ? ($examYear + (18 - $age)) : ($examYear + 4);
            
            $proof->setConfirmedAt(new \DateTime());
            $proof->setValidUntil(new \DateTime("$validUntilYear-12-31"));
            
            // Text für die Anzeige (z.B. "Disziplin erfüllt")
            $proof->setRequirementMetVia($proofIdentifier);

            $this->em->flush();

        } elseif ($points === 0 && $proof && $proof->getRequirementMetVia() === $proofIdentifier) {
            // --- FALL B: Leistung gelöscht/0 Punkte -> Nachweis entfernen ---
            
            // Aber NUR löschen, wenn der Nachweis auch von DIESER Disziplin kam.
            // (Nicht dass wir einen manuell hochgeladenen DLRG-Schein löschen, nur weil jemand beim 200m Schwimmen versagt hat).
            $this->em->remove($proof);
            $this->em->flush();
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
<?php

namespace PulsR\SportabzeichenBundle\Service;

use Doctrine\DBAL\Connection;

class SportabzeichenCalculator
{
    public function __construct(private Connection $conn) {}

    public function calculate(int $disciplineId, int $examYear, string $gender, int $age, ?float $leistung): array
    {
        if ($leistung === null || $leistung <= 0) {
            return ['points' => 0, 'stufe' => 'NONE'];
        }

        // 1. Anforderungen laden
        // Mapping: Wir normalisieren das Geschlecht auf das Format in der DB (MALE/FEMALE)
        $genderSearch = str_starts_with(strtoupper($gender), 'M') ? 'MALE' : 'FEMALE';

        $req = $this->conn->fetchAssociative("
            SELECT r.*, d.berechnungsart 
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON d.id = r.discipline_id
            WHERE r.discipline_id = ? 
              AND r.jahr = ? 
              AND r.geschlecht = ? 
              AND ? BETWEEN r.age_min AND r.age_max
            LIMIT 1
        ", [$disciplineId, $examYear, $genderSearch, $age]);

        if (!$req) {
            return ['points' => 0, 'stufe' => 'NONE'];
        }

        $calcType = strtoupper($req['berechnungsart'] ?? 'BIGGER');
        $points = 0;
        $stufe = 'NONE';

        if ($calcType === 'SMALLER') {
            // Zeit-Logik: Kleiner ist besser
            if ($leistung <= $req['gold'] && $req['gold'] > 0) { $points = 3; $stufe = 'GOLD'; }
            elseif ($leistung <= $req['silber'] && $req['silber'] > 0) { $points = 2; $stufe = 'SILBER'; }
            elseif ($leistung <= $req['bronze'] && $req['bronze'] > 0) { $points = 1; $stufe = 'BRONZE'; }
        } else {
            // Weiten-Logik: Größer ist besser
            if ($leistung >= $req['gold']) { $points = 3; $stufe = 'GOLD'; }
            elseif ($leistung >= $req['silber']) { $points = 2; $stufe = 'SILBER'; }
            elseif ($leistung >= $req['bronze']) { $points = 1; $stufe = 'BRONZE'; }
        }

        return ['points' => $points, 'stufe' => $stufe];
    }
}
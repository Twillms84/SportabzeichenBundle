<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sportabzeichen/me', name: 'puls_r_sportabzeichen_my_results')]
#[IsGranted('PRIV_SPORTABZEICHEN_VIEW_OWN')]
final class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {}

    public function __invoke(): Response
    {
        $user = $this->getUser();
        $currentYear = (int)date('Y');

        // 1. Teilnehmer-Daten laden
        $participant = $this->conn->fetchAssociative("
            SELECT id, geburtsdatum, geschlecht 
            FROM sportabzeichen_participants 
            WHERE user_id = :uid
        ", ['uid' => $user->getId()]);

        if (!$participant || empty($participant['geburtsdatum'])) {
            return $this->render('@PulsRSportabzeichen/my_results/not_found.html.twig');
        }

        // 2. Alter und Altersklasse berechnen
        // Sportabzeichen-Alter = Das Jahr, das man im aktuellen Kalenderjahr erreicht
        $birthYear = (int)(new \DateTime($participant['geburtsdatum']))->format('Y');
        $age = $currentYear - $birthYear;
        
        // Geschlecht mappen (DB 'MALE'/'FEMALE' -> Requirements 'm'/'w')
        $genderReq = ($participant['geschlecht'] === 'FEMALE') ? 'w' : 'm'; // Einfaches Mapping, anpassen falls 'd' existiert

        // 3. Anforderungen für DIESES Alter und Geschlecht laden
        // Wir holen auch gleich die Disziplin-Infos dazu
        $sqlReq = "
            SELECT 
                d.id as discipline_id,
                d.name,
                d.kategorie, -- Ausdauer, Kraft, Schnelligkeit, Koordination
                d.einheit,
                d.berechnungsart,
                r.bronze,
                r.silber,
                r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex
              AND :age BETWEEN r.min_alter AND r.max_alter
            ORDER BY d.kategorie ASC, d.name ASC
        ";
        
        $rawRequirements = $this->conn->fetchAllAssociative($sqlReq, [
            'sex' => $genderReq,
            'age' => $age
        ]);

        // 4. Meine Ergebnisse für das AKTUELLE JAHR laden
        // Wir suchen Ergebnisse in Exams, die im aktuellen Jahr stattfinden
        $sqlResults = "
            SELECT 
                r.discipline_id,
                r.leistung,
                r.points
            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_exams e ON ep.exam_id = e.id
            WHERE ep.participant_id = :pid
              AND (
                  EXTRACT(YEAR FROM e.date) = :year 
                  OR e.created_at >= :startOfYear
              )
        ";

        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid' => $participant['id'],
            'year' => $currentYear,
            'startOfYear' => $currentYear . '-01-01'
        ]);

        // Ergebnisse indexieren (DisciplineID -> ResultData)
        $myResults = [];
        foreach ($rawResults as $res) {
            // Falls es mehrere gibt (z.B. zwei Versuche), nehmen wir den besseren (höhere Punkte)
            $dId = $res['discipline_id'];
            if (!isset($myResults[$dId]) || $res['points'] > $myResults[$dId]['points']) {
                $myResults[$dId] = $res;
            }
        }

        // 5. Daten zusammenfügen und nach Kategorien gruppieren
        $categories = [
            'Ausdauer' => [],
            'Kraft' => [],
            'Schnelligkeit' => [],
            'Koordination' => []
        ];

        foreach ($rawRequirements as $req) {
            $cat = $req['kategorie'];
            $dId = $req['discipline_id'];

            // Eintrag vorbereiten
            $entry = $req;
            $entry['my_result'] = $myResults[$dId] ?? null; // Ergebnis hinzufügen falls vorhanden

            if (isset($categories[$cat])) {
                $categories[$cat][] = $entry;
            } else {
                // Fallback für unbekannte Kategorien (z.B. Schwimmnachweis extra)
                $categories['Sonstige'][] = $entry;
            }
        }

        return $this->render('@PulsRSportabzeichen/my_results/index.html.twig', [
            'age' => $age,
            'gender' => $participant['geschlecht'],
            'year' => $currentYear,
            'categories' => $categories
        ]);
    }
}
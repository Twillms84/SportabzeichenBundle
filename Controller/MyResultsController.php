<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/my_results', name: 'pulsr_sportabzeichen_my_results')]
class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $currentYear = (int)date('Y');
        
        // --- 1. ID SICHER ERMITTELN ---
        $username = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername();
        $userId = (int)$this->conn->fetchOne("SELECT id FROM users WHERE act = ?", [$username]);

        if (!$userId) {
            throw $this->createNotFoundException('User ID Error');
        }

        // --- 2. TEILNEHMER PRÜFEN / AUTO-CREATE ---
        $participant = $this->conn->fetchAssociative("
            SELECT p.id, p.geburtsdatum, p.geschlecht 
            FROM sportabzeichen_participants p
            WHERE p.user_id = :uid
        ", ['uid' => $userId]);

        if (!$participant) {
            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username, geburtsdatum, geschlecht)
                VALUES (:uid, :act, :dob, :sex)
            ", [
                'uid' => $userId,
                'act' => $username,
                'dob' => '2008-01-01',
                'sex' => 'MALE'
            ]);
            
            $participant = $this->conn->fetchAssociative("
                SELECT p.id, p.geburtsdatum, p.geschlecht 
                FROM sportabzeichen_participants p
                WHERE p.user_id = :uid
            ", ['uid' => $userId]);

            $this->addFlash('info', 'Dein Profil wurde initialisiert.');
        }

        // --- 3. SPEICHERN ---
        if ($request->isMethod('POST') && $request->request->has('save_training')) {
            $discId = (int)$request->request->get('discipline_id');
            $value  = trim((string)$request->request->get('training_value'));

            if ($discId > 0) {
                $existing = $this->conn->fetchOne("
                    SELECT id FROM sportabzeichen_training 
                    WHERE user_id = :uid AND discipline_id = :did AND year = :yr
                ", [
                    'uid' => $userId,
                    'did' => $discId,
                    'yr'  => $currentYear
                ]);

                if ($existing) {
                    $this->conn->executeStatement("
                        UPDATE sportabzeichen_training SET value = :val 
                        WHERE user_id = :uid AND discipline_id = :did AND year = :yr
                    ", [
                        'val' => $value,
                        'uid' => $userId,
                        'did' => $discId,
                        'yr'  => $currentYear
                    ]);
                } else {
                    if ($value !== '') {
                        $this->conn->executeStatement("
                            INSERT INTO sportabzeichen_training (user_id, discipline_id, year, value)
                            VALUES (:uid, :did, :yr, :val)
                        ", [
                            'uid' => $userId,
                            'did' => $discId,
                            'yr'  => $currentYear,
                            'val' => $value
                        ]);
                    }
                }
                $this->addFlash('success', 'Wert gespeichert.');
                return $this->redirectToRoute('pulsr_sportabzeichen_my_results');
            }
        }

        // --- 4. DATEN LADEN ---
        $birthDate = new \DateTime($participant['geburtsdatum']);
        $age = $currentYear - (int)$birthDate->format('Y');

        // Offizielle Ergebnisse
        $sqlResults = "
            SELECT r.discipline_id, r.leistung, r.points
            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_exams e ON ep.exam_id = e.id
            WHERE ep.participant_id = :pid AND e.exam_year = :year
        ";
        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid' => (int)$participant['id'],
            'year' => $currentYear
        ]);
        
        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[$r['discipline_id']] = $r;
        }

        // Eigene Trainingsdaten
        $trainingData = $this->conn->fetchAllAssociative("
            SELECT discipline_id, value 
            FROM sportabzeichen_training 
            WHERE user_id = :uid AND year = :year
        ", [
            'uid' => $userId, 
            'year' => $currentYear
        ]);
        
        $myTraining = [];
        foreach ($trainingData as $t) {
            $myTraining[$t['discipline_id']] = $t['value'];
        }

        // --- REQUIREMENTS LADEN (Gefiltert & Sortiert) ---
        $sqlReq = "
            SELECT DISTINCT
                d.id as discipline_id, d.name, d.kategorie, d.einheit,
                r.bronze, r.silber, r.gold,
                r.auswahlnummer 
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex 
              AND :age BETWEEN r.age_min AND r.age_max
              AND d.einheit != 'NONE'
              AND r.jahr = :year  -- <--- NEU: Nur das aktuelle Jahr laden!
            ORDER BY d.kategorie ASC, r.auswahlnummer ASC, d.name ASC
        ";
        
        $rows = $this->conn->fetchAllAssociative($sqlReq, [
            'sex'  => $participant['geschlecht'],
            'age'  => $age,
            'year' => $currentYear // <--- NEU: Parameter übergeben
        ]);

        // --- 5. ZUSAMMENBAU ---
        $categories = ['Ausdauer' => [], 'Kraft' => [], 'Schnelligkeit' => [], 'Koordination' => []];
        $addedDisciplines = [];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];

            if (isset($addedDisciplines[$dId])) {
                continue;
            }
            $addedDisciplines[$dId] = true;
            
            $row['official_result'] = $officialResults[$dId] ?? null;
            $row['training_value'] = $myTraining[$dId] ?? '';

            $cat = $row['kategorie'];
            if (isset($categories[$cat])) {
                $categories[$cat][] = $row;
            }
        }

        return $this->render('@PulsRSportabzeichen/my_results/index.html.twig', [
            'year' => $currentYear,
            'age' => $age,
            'gender' => $participant['geschlecht'],
            'categories' => $categories,
        ]);
    }
}
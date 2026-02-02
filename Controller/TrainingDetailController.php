<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/training/{disciplineId}', name: 'pulsr_sportabzeichen_training_detail')]
class TrainingDetailController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {
    }

    public function __invoke(int $disciplineId, Request $request): Response
    {
        $user = $this->getUser();
        // ... (User/ID Validierung wie im anderen Controller) ...
        $username = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername();
        $userId = (int)$this->conn->fetchOne("SELECT id FROM users WHERE act = ?", [$username]);
        $currentYear = (int)date('Y');

        // 1. Disziplin & Requirements laden
        // Wir brauchen die Requirements, um den Abstand zu berechnen
        // ACHTUNG: Hier vereinfacht, wir nehmen an, Alter/Geschlecht ist im Session/User bekannt oder wir laden es neu.
        // Um es sauber zu halten, laden wir kurz Participant Info:
        $participant = $this->conn->fetchAssociative("SELECT geburtsdatum, geschlecht FROM sportabzeichen_participants WHERE user_id = ?", [$userId]);
        $birthDate = new \DateTime($participant['geburtsdatum']);
        $age = $currentYear - (int)$birthDate->format('Y');

        $req = $this->conn->fetchAssociative("
            SELECT d.name, d.einheit, r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE d.id = :did 
              AND r.geschlecht = :sex
              AND :age BETWEEN r.age_min AND r.age_max
              AND r.jahr = :year
        ", [
            'did' => $disciplineId,
            'sex' => $participant['geschlecht'],
            'age' => $age,
            'year' => $currentYear
        ]);

        if (!$req) {
            throw $this->createNotFoundException('Disziplin nicht gefunden oder nicht für dein Alter verfügbar.');
        }

        // 2. Speichern neuer Werte
        if ($request->isMethod('POST')) {
            $newValue = trim((string)$request->request->get('value'));
            if ($newValue !== '') {
                // Komma zu Punkt für DB (optional, je nach Format)
                // $newValue = str_replace(',', '.', $newValue); 
                
                $this->conn->executeStatement("
                    INSERT INTO sportabzeichen_training (user_id, discipline_id, year, value, created_at)
                    VALUES (:uid, :did, :yr, :val, NOW())
                ", [
                    'uid' => $userId,
                    'did' => $disciplineId,
                    'yr'  => $currentYear,
                    'val' => $newValue
                ]);
                $this->addFlash('success', 'Trainingseinheit gespeichert!');
                return $this->redirectToRoute('pulsr_sportabzeichen_training_detail', ['disciplineId' => $disciplineId]);
            }
        }

        // 3. Historie laden (Neueste zuerst)
        $history = $this->conn->fetchAllAssociative("
            SELECT value, created_at 
            FROM sportabzeichen_training 
            WHERE user_id = :uid AND discipline_id = :did AND year = :yr
            ORDER BY created_at DESC
        ", [
            'uid' => $userId,
            'did' => $disciplineId,
            'yr'  => $currentYear
        ]);

        // 4. Analyse & Abstand zum nächsten Ziel
        $latestValue = $history[0]['value'] ?? null;
        $analysis = $this->analyzePerformance($latestValue, $req);

        return $this->render('@PulsRSportabzeichen/my_results/detail.html.twig', [
            'discipline' => $req,
            'history' => $history,
            'analysis' => $analysis,
            'year' => $currentYear
        ]);
    }

    private function analyzePerformance(?string $currentVal, array $req): array
    {
        if ($currentVal === null) return [];

        // Konvertiere "12,5" zu 12.5 float
        $val = (float)str_replace(',', '.', $currentVal);
        $gold = (float)str_replace(',', '.', (string)$req['gold']);
        $bronze = (float)str_replace(',', '.', (string)$req['bronze']);

        // Erkennen: Ist weniger besser (Laufen) oder mehr besser (Werfen/Springen)?
        // Heuristik: Wenn Gold kleiner ist als Bronze, ist weniger besser (Zeit).
        $lessIsBetter = ($gold < $bronze);

        $targets = [
            'Bronze' => (float)str_replace(',', '.', (string)$req['bronze']),
            'Silber' => (float)str_replace(',', '.', (string)$req['silber']),
            'Gold'   => (float)str_replace(',', '.', (string)$req['gold']),
        ];

        $nextGoal = null;
        $gap = 0;
        $unit = $req['einheit'];

        foreach ($targets as $label => $targetVal) {
            $reached = $lessIsBetter ? ($val <= $targetVal) : ($val >= $targetVal);
            
            if (!$reached) {
                $nextGoal = $label;
                // Differenz berechnen
                $gap = $lessIsBetter ? ($val - $targetVal) : ($targetVal - $val);
                break; // Das erste nicht erreichte Ziel ist das nächste Ziel
            }
        }

        return [
            'current_float' => $val,
            'next_goal_label' => $nextGoal, // z.B. "Silber"
            'gap' => round($gap, 2),        // z.B. 1.5
            'unit' => $unit,
            'less_is_better' => $lessIsBetter
        ];
    }
}
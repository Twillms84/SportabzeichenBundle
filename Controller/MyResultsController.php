<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\TrainingEntry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request; // Wichtig!
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/my-results', name: 'sportabzeichen_my_results')]
class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn,
        private readonly EntityManagerInterface $em
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $currentYear = (int)date('Y');
        
        // --- TEIL A: SPEICHERN (Wenn Formular abgesendet wurde) ---
        if ($request->isMethod('POST') && $request->request->has('save_training')) {
            $discId = (int)$request->request->get('discipline_id');
            $value  = trim((string)$request->request->get('training_value'));

            if ($discId > 0) {
                // Repository nutzen um Eintrag zu finden oder neu zu erstellen
                $repo = $this->em->getRepository(TrainingEntry::class);
                $entry = $repo->findOneBy(['user' => $user, 'discipline' => $this->em->getReference(Discipline::class, $discId), 'year' => $currentYear]);

                if (!$entry) {
                    $entry = new TrainingEntry();
                    $entry->setUser($user);
                    $entry->setDiscipline($this->em->getReference(Discipline::class, $discId));
                    $entry->setYear($currentYear);
                }

                $entry->setValue($value);
                $this->em->persist($entry);
                $this->em->flush();

                $this->addFlash('success', 'Trainingswert gespeichert.');
                
                // Redirect, um "Formular erneut senden" Warnung zu verhindern
                return $this->redirectToRoute('sportabzeichen_my_results');
            }
        }

        // --- TEIL B: DATEN LADEN (Wie vorher) ---
        
        // 1. Teilnehmer ID holen (Code von vorher...)
        $accountName = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername();
        $participant = $this->conn->fetchAssociative("
            SELECT p.id, p.geburtsdatum, p.geschlecht 
            FROM sportabzeichen_participants p
            JOIN users u ON p.user_id = u.id
            WHERE u.act = :acct
        ", ['acct' => $accountName]);

        if (!$participant || empty($participant['geburtsdatum'])) {
            return $this->render('@PulsRSportabzeichen/my_results/not_found.html.twig');
        }

        // Alter berechnen (Code von vorher...)
        $birthDate = new \DateTime($participant['geburtsdatum']);
        $age = $currentYear - (int)$birthDate->format('Y');

        // 2. Offizielle Ergebnisse holen (Code von vorher...)
        $sqlResults = "
            SELECT r.discipline_id, r.leistung, r.points
            FROM sportabzeichen_exam_results r
            JOIN sportabzeichen_exam_participants ep ON r.ep_id = ep.id
            JOIN sportabzeichen_exams e ON ep.exam_id = e.id
            WHERE ep.participant_id = :pid AND e.exam_year = :year
        ";
        $rawResults = $this->conn->fetchAllAssociative($sqlResults, [
            'pid' => $participant['id'], 
            'year' => $currentYear
        ]);
        // Indizieren für schnellen Zugriff
        $officialResults = [];
        foreach ($rawResults as $r) {
            $officialResults[$r['discipline_id']] = $r;
        }

        // 3. NEU: Trainingsdaten holen
        // Wir nutzen User-ID, nicht Participant-ID, da Training direkt am User hängt
        $trainingData = $this->conn->fetchAllAssociative("
            SELECT discipline_id, value 
            FROM sportabzeichen_training 
            WHERE user_id = :uid AND year = :year
        ", ['uid' => $user->getId(), 'year' => $currentYear]);
        
        $myTraining = [];
        foreach ($trainingData as $t) {
            $myTraining[$t['discipline_id']] = $t['value'];
        }

        // 4. Anforderungen laden (Code von vorher...)
        $sqlReq = "
            SELECT 
                d.id as discipline_id, d.name, d.kategorie, d.einheit, d.berechnungsart,
                r.bronze, r.silber, r.gold
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d ON r.discipline_id = d.id
            WHERE r.geschlecht = :sex AND :age BETWEEN r.age_min AND r.age_max
            ORDER BY d.kategorie ASC, d.name ASC
        ";
        
        $rows = $this->conn->fetchAllAssociative($sqlReq, [
            'sex' => $participant['geschlecht'],
            'age' => $age
        ]);

        // 5. Alles zusammenbauen
        $categories = ['Ausdauer' => [], 'Kraft' => [], 'Schnelligkeit' => [], 'Koordination' => []];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];
            
            // Offizielles Ergebnis einfügen
            $row['official_result'] = $officialResults[$dId] ?? null;
            
            // Trainingswert einfügen
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
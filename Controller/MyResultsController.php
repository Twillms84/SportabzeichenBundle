<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\TrainingEntry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use PulsR\SportabzeichenBundle\Entity\Participant; // Falls benötigt, sonst optional

#[Route('/sportabzeichen/my_results', name: 'sportabzeichen_my_results')]
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
        if (!$user) {
            throw $this->createAccessDeniedException();
        }

        $currentYear = (int)date('Y');
        
        // --- 1. ECHTE USER-ID ERMITTELN ---
        // $user->getId() gibt bei IServ oft den Namen zurück ("timo.willms").
        // Wir brauchen aber die Zahl (z.B. 1054). Wir holen sie uns via SQL.
        
        $username = method_exists($user, 'getUserIdentifier') ? $user->getUserIdentifier() : $user->getUsername();
        
        // Wir suchen die ID in der users Tabelle anhand des Namens (Spalte 'act')
        $userId = (int)$this->conn->fetchOne("SELECT id FROM users WHERE act = ?", [$username]);

        if (!$userId) {
            throw $this->createNotFoundException('Benutzer-ID in Datenbank nicht gefunden.');
        }

        // --- 2. TEILNEHMER LADEN / ERSTELLEN ---
        
        $participant = $this->conn->fetchAssociative("
            SELECT p.id, p.geburtsdatum, p.geschlecht 
            FROM sportabzeichen_participants p
            WHERE p.user_id = :uid
        ", ['uid' => $userId]);

        // AUTO-ONBOARDING: Falls noch kein Eintrag existiert
        if (!$participant) {
            $this->conn->executeStatement("
                INSERT INTO sportabzeichen_participants (user_id, username, geburtsdatum, geschlecht)
                VALUES (:uid, :act, :dob, :sex)
            ", [
                'uid' => $userId,       // Jetzt ist das garantiert die korrekte Zahl
                'act' => $username,
                'dob' => '2008-01-01',  // Dummy-Datum
                'sex' => 'MALE'         // Dummy-Geschlecht
            ]);

            // Sofort neu laden
            $participant = $this->conn->fetchAssociative("
                SELECT p.id, p.geburtsdatum, p.geschlecht 
                FROM sportabzeichen_participants p
                WHERE p.user_id = :uid
            ", ['uid' => $userId]);
            
            $this->addFlash('info', 'Dein Profil wurde automatisch angelegt (Standard: Männlich, *2008).');
        }

        if (!$participant) {
            return $this->render('@PulsRSportabzeichen/my_results/not_found.html.twig');
        }

        // --- 3. SPEICHERN (POST) ---
        if ($request->isMethod('POST') && $request->request->has('save_training')) {
            $discId = (int)$request->request->get('discipline_id');
            $value  = trim((string)$request->request->get('training_value'));

            if ($discId > 0) {
                $repo = $this->em->getRepository(TrainingEntry::class);
                
                // Wir suchen hier wieder über die Doctrine User Entity
                // Da $user->getId() strings liefert, könnte Doctrine verwirrt sein.
                // Sicherer: Wir suchen via findOneBy und übergeben das User-Objekt.
                // Doctrine mappt das intern meist korrekt auf den Primary Key.
                $entry = $repo->findOneBy([
                    'user' => $user, 
                    'discipline' => $this->em->getReference(Discipline::class, $discId), 
                    'year' => $currentYear
                ]);

                if (!$entry) {
                    $entry = new TrainingEntry();
                    $entry->setUser($user);
                    $entry->setDiscipline($this->em->getReference(Discipline::class, $discId));
                    $entry->setYear($currentYear);
                }

                $entry->setValue($value);
                $this->em->persist($entry);
                $this->em->flush();

                $this->addFlash('success', 'Gespeichert.');
                return $this->redirectToRoute('sportabzeichen_my_results');
            }
        }

        // --- 4. DATEN LADEN FÜR ANZEIGE ---
        
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

        // Trainingsdaten (Eigene Einträge)
        // Hier nutzen wir jetzt die sicher ermittelte $userId (Integer)
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

        // Anforderungen
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

        $categories = ['Ausdauer' => [], 'Kraft' => [], 'Schnelligkeit' => [], 'Koordination' => []];

        foreach ($rows as $row) {
            $dId = $row['discipline_id'];
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
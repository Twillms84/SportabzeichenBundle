<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group; // <--- NEU
use IServ\CoreBundle\Entity\User;  // <--- NEU
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Entity\Participant; // <--- NEU: Brauchen wir für den Import
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use PulsR\SportabzeichenBundle\Repository\SwimmingProofRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    /**
     * DASHBOARD: Liste der EIGENEN Prüfungen
     */
    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // NEU: Nur Prüfungen finden, die ICH erstellt habe
        $user = $this->getUser();
        
        // Admin-Option (optional): Falls jemand ALLES sehen soll, hier Logic einbauen.
        // Standard: Nur eigene.
        $exams = $examRepository->findBy(
            ['creator' => $user], 
            ['year' => 'DESC', 'date' => 'DESC']
        );

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams' => $exams,
        ]);
    }

    /**
     * CREATE: Neue Prüfung erstellen
     */
    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // 1. Klassen laden (Legacy SQL Methode, ist performant für Auxinfo)
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // 2. Gruppen laden (NEU: Über Doctrine Entity)
        // Wir laden alle Gruppen, sortieren sie aber idealerweise im Template oder hier
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        // Array für das Dropdown bauen: [account => name]
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            // Optional: Klassen hier rausfiltern, da wir sie oben schon haben?
            // if ($g->getType() !== 'class') { ... }
            $groupsForDropdown[$g->getAccount()] = $g->getName();
        }


        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                // Formular Daten holen
                $postData = $request->request->all(); // Symfony < 6 use request->all(), >6 needs adjustment if typed
                // Fallback falls request->all() Array-Probleme macht:
                $selectedClasses = $postData['classes'] ?? [];
                $selectedGroups  = $postData['groups'] ?? []; // <--- NEU

                // A. Prüfung anlegen
                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                
                // NEU: Creator setzen
                $exam->setCreator($this->getUser());

                $em->persist($exam);
                $em->flush(); // ID generieren lassen

                $count = 0;

                // B. Klassen importieren (Existierende SQL Logik)
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $exam->getId(), $year, $singleClass);
                        $count++;
                    }
                }

                // C. Gruppen importieren (NEU: Doctrine Logik)
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        // Importiere diese Gruppe
                        $this->importParticipantsFromGroup($em, $conn, $exam, $groupAccount);
                        $count++;
                    }
                }

                $this->addFlash('success', 'Prüfung erfolgreich angelegt.');
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes,
            'groups'  => $groupsForDropdown // <--- ans Template übergeben
        ]);
    }

    // ... (EDIT Methode bleibt wie sie ist) ...
    // ... (DELETE Methode bleibt wie sie ist) ...


    // --- HILFSMETHODEN ---

    // 1. Die bestehende SQL Methode für Klassen (Auxinfo)
    Alles klar, wir bauen eine "Notbremse" ein.

Da der Fehler Undefined array key "id" immer noch auftritt, bedeutet das: Die Datenbank liefert zwar Daten zurück (es ist also kein false), aber das Array hat nicht den Key id (vielleicht heißt er ID, sportabzeichen_participants.id oder ganz anders).

Wir fügen jetzt Code ein, der sofort das Script stoppt und dir den Inhalt des Arrays auf den Bildschirm knallt, sobald der Key fehlt.

Ersetze die beiden Methoden unten im ExamController hiermit:

PHP

    // --- HILFSMETHODE MIT DEBUGGING ---

    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL AND importid <> ''
        ", [$class]);

        foreach ($users as $u) {
            // Schritt 1: User-Keys normalisieren
            $u = array_change_key_case($u, CASE_LOWER);
            
            if (empty($u['importid'])) continue;

            // Schritt 2: Teilnehmer laden
            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$u['importid']]);

            // Wenn kein Teilnehmer gefunden wurde -> weiter
            if (!$participant) continue;

            // Schritt 3: Keys normalisieren
            $participant = array_change_key_case($participant, CASE_LOWER);

            // --- DEBUG START ---
            // Wenn 'id' fehlt, werfen wir sofort einen Fehler mit dem Array-Inhalt
            if (!array_key_exists('id', $participant)) {
                throw new \Exception(
                    "DEBUG ERROR (Class Import): Key 'id' fehlt! \n" .
                    "ImportID: " . $u['importid'] . "\n" .
                    "Gefundene DB-Daten: " . print_r($participant, true)
                );
            }
            // --- DEBUG ENDE ---

            if (empty($participant['geburtsdatum'])) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }

    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        foreach ($group->getUsers() as $user) {
            $importId = $user->getImportId();

            if ($importId) {
                $row = $conn->fetchAssociative("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
                ", [$importId]);
                
                if ($row) {
                    $row = array_change_key_case($row, CASE_LOWER);

                    // --- DEBUG START ---
                    if (!array_key_exists('id', $row)) {
                        throw new \Exception(
                            "DEBUG ERROR (Group Import): Key 'id' fehlt! \n" .
                            "User: " . $user->getUsername() . "\n" .
                            "Gefundene DB-Daten: " . print_r($row, true)
                        );
                    }
                    // --- DEBUG ENDE ---
                    
                    if (!empty($row['id']) && !empty($row['geburtsdatum'])) {
                        $age = $exam->getYear() - (int)substr($row['geburtsdatum'], 0, 4);
                        
                        $conn->executeStatement("
                            INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                            VALUES (?, ?, ?) ON CONFLICT DO NOTHING
                        ", [$exam->getId(), $row['id'], $age]);
                    }
                }
            }
        }
    }
}
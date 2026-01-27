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
    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL
        ", [$class]);

        foreach ($users as $u) {
            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$u['importid']]);

            if (!$participant || !$participant['geburtsdatum']) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }

    // 2. Die NEUE Methode für Gruppen (Doctrine + SQL Mix)
    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        // Gruppe suchen
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        // Alle User der Gruppe iterieren
        foreach ($group->getUsers() as $user) {
            // Wir müssen prüfen, ob es für diesen IServ-User schon einen "Participant" gibt.
            // Suche via User-Relation (falls vorhanden) oder import_id
            
            // Versuch 1: Suche in Participants Tabelle via User-Verknüpfung (falls Entity so gebaut ist)
            // Da ich deine Participant-Entity nicht kenne, mache ich es hier über SQL/DBAL, 
            // um Lehrer zu finden, die evtl. keine import_id haben.
            
            // Wir suchen in sportabzeichen_participants nach einem Eintrag, der zu diesem User gehört.
            // Annahme: Es gibt eine Spalte 'user_id' oder wir nutzen 'import_id' = user->getImportId()
            
            // Sicherer Weg: Wir schauen, ob wir den User anhand der import_id finden (Schüler)
            // ODER wir erstellen einen Teilnehmer, falls es ein Lehrer ist der noch nicht existiert.
            
            $importId = $user->getImportId();
            $participantId = null;
            $birthDate = null;

            if ($importId) {
                // Versuche existierenden Schüler zu finden
                $row = $conn->fetchAssociative("SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?", [$importId]);
                if ($row) {
                    $participantId = $row['id'];
                    $birthDate = $row['geburtsdatum'];
                }
            }

            // Wenn kein Schüler gefunden wurde (z.B. Lehrer ohne Import-ID im Sportabzeichen-System),
            // müssten wir hier eigentlich einen Teilnehmer anlegen. 
            // HINWEIS: Das ist komplex, da wir Vorname/Nachname/Geschlecht/Geburtsdatum brauchen.
            // IServ User Objekt hat: $user->getName(), $user->getFirstname(), $user->getLastname().
            // Aber Geburtsdatum steht oft nicht im IServ User Objekt (Datenschutz).
            
            // Workaround für jetzt: Wir importieren nur User, die schon als Participant existieren.
            // Wenn Lehrer mitmachen wollen, müssen sie vorher im "Teilnehmer"-Tab manuell oder per Import angelegt werden.
            
            if ($participantId && $birthDate) {
                $age = $exam->getYear() - (int)substr($birthDate, 0, 4);
                
                $conn->executeStatement("
                    INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                    VALUES (?, ?, ?) ON CONFLICT DO NOTHING
                ", [$exam->getId(), $participantId, $age]);
            }
        }
    }
}
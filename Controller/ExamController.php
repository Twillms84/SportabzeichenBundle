<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group; // <--- Wichtig für Gruppen
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use PulsR\SportabzeichenBundle\Repository\SwimmingProofRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exams = $examRepository->findBy(
            ['creator' => $this->getUser()], // Optional: Nur eigene anzeigen
            ['year' => 'DESC', 'date' => 'DESC']
        );

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams' => $exams,
        ]);
    }

    #[Route('/new', name: 'new')]
    public function new(Request $request, EntityManagerInterface $em, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        // 1. Klassen laden (wie gehabt)
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // 2. Gruppen laden (NEU)
        $groupRepo = $em->getRepository(Group::class);
        $allGroups = $groupRepo->findBy([], ['name' => 'ASC']);
        
        $groupsForDropdown = [];
        foreach ($allGroups as $g) {
            $groupsForDropdown[$g->getAccount()] = $g->getName();
        }

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $postData = $request->request->all();
                $selectedClasses = $postData['classes'] ?? [];
                $selectedGroups  = $postData['groups'] ?? []; // <--- NEU

                // Entity anlegen
                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser());

                $em->persist($exam);
                $em->flush();

                $count = 0;

                // A. Klassen importieren
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $exam->getId(), $year, $singleClass);
                        $count++;
                    }
                }

                // B. Gruppen importieren (NEU)
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
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

    // --- HILFSMETHODE 1: KLASSEN (Dein funktionierender Code + 1 Sicherheitszeile) ---
    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL AND importid <> ''
        ", [$class]);

        foreach ($users as $u) {
            // Normierung, falls DB mal 'ImportID' statt 'importid' liefert
            $u = array_change_key_case($u, CASE_LOWER);
            
            if (empty($u['importid'])) continue;

            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$u['importid']]);

            // WICHTIG: Wenn DB nix liefert -> continue.
            if (!$participant) continue;

            // SICHERHEIT: Alles klein schreiben. Verhindert Fehler bei "ID" vs "id".
            $participant = array_change_key_case($participant, CASE_LOWER);

            // LOGIK: Wenn ID fehlt ODER Geburtsdatum leer ist -> Überspringen
            if (!isset($participant['id']) || empty($participant['geburtsdatum'])) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }

    // --- HILFSMETHODE 2: GRUPPEN (Die gleiche Logik wie oben) ---
    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        // 1. IServ Gruppe holen
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        // 2. Über User iterieren
        foreach ($group->getUsers() as $user) {
            $importId = $user->getImportId();
            $username = $user->getUsername();
            
            $row = false;

            // Suche A: Import ID
            if (!empty($importId)) {
                $row = $conn->fetchAssociative("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
                ", [$importId]);
            }

            // Suche B: Username (Fallback)
            if (!$row && !empty($username)) {
                $row = $conn->fetchAssociative("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE username = ?
                ", [$username]);
            }

            // Wenn immer noch nichts gefunden -> Nächster User
            if (!$row) continue;

            // SICHERHEIT: Exakt wie oben. Array Keys klein machen.
            $row = array_change_key_case($row, CASE_LOWER);

            // LOGIK: ID fehlt oder Datum fehlt -> Nächster User. 
            // Das killt den Fehler "Undefined array key id".
            if (!isset($row['id']) || empty($row['geburtsdatum'])) continue;

            $age = $exam->getYear() - (int)substr($row['geburtsdatum'], 0, 4);
            
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$exam->getId(), $row['id'], $age]);
        }
    }
}
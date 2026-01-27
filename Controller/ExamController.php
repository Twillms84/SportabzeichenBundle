<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Entity\Participant;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
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
        $user = $this->getUser();
        
        $exams = $examRepository->findBy(
            ['creator' => $user], 
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

        // 1. Klassen laden
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        // 2. Gruppen laden
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
                $selectedGroups  = $postData['groups'] ?? [];

                // A. Prüfung anlegen
                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                $exam->setCreator($this->getUser());

                $em->persist($exam);
                $em->flush();

                // B. Klassen importieren
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $exam->getId(), $year, $singleClass);
                    }
                }

                // C. Gruppen importieren
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $this->importParticipantsFromGroup($em, $conn, $exam, $groupAccount);
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
            'groups'  => $groupsForDropdown
        ]);
    }

    // --- HILFSMETHODE 1: KLASSEN (Unverändert, da funktionierend) ---
    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL AND importid <> ''
        ", [$class]);

        foreach ($users as $u) {
            $importId = $u['importid'] ?? $u['ImportID'] ?? null;
            if (!$importId) continue;

            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$importId]);

            if (!$participant || empty($participant['geburtsdatum'])) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }

    // --- HILFSMETHODE 2: GRUPPEN (Bulletproof Version) ---
    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        foreach ($group->getUsers() as $user) {
            $importId = $user->getImportId();
            $username = $user->getUsername();
            
            $row = false;

            // 1. Versuch: Über ImportID (bevorzugt)
            if (!empty($importId)) {
                $row = $conn->fetchAssociative("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
                ", [$importId]);
            }

            // 2. Versuch: Über Username (Fallback für Lehrer ohne ImportID)
            if (!$row && !empty($username)) {
                $row = $conn->fetchAssociative("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE username = ?
                ", [$username]);
            }

            // Wenn SQL nichts geliefert hat -> Abbruch für diesen User
            if (!$row) continue;

            // SICHERHEITSSCHRITT: Alle Keys auf Kleinschreibung normieren
            // Das verhindert Probleme, falls DB 'ID' statt 'id' liefert.
            $row = array_change_key_case($row, CASE_LOWER);

            // FINALER CHECK (Der "Bock"-Killer):
            // Wir prüfen explizit mit ISSET auf 'id'. 
            // Wenn 'id' fehlt ODER 'geburtsdatum' leer ist -> SKIP.
            // Damit kann der Fehler "Undefined array key" unmöglich auftreten.
            if (!isset($row['id']) || empty($row['geburtsdatum'])) {
                continue;
            }

            $age = $exam->getYear() - (int)substr($row['geburtsdatum'], 0, 4);
            
            // Jetzt ist $row['id'] garantiert vorhanden.
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$exam->getId(), $row['id'], $age]);
        }
    }
}
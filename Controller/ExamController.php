<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use IServ\CoreBundle\Entity\Group;
use IServ\CoreBundle\Entity\User;
use PulsR\SportabzeichenBundle\Entity\Exam;
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
        
        // ÄNDERUNG 1: Wir filtern nicht mehr nach Creator
        // ['creator' => $this->getUser()] wurde zu []
        $exams = $examRepository->findBy(
            [], // Zeige ALLE Prüfungen, egal von wem
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

                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);
                
                // ÄNDERUNG 2: Creator auskommentiert
                // $exam->setCreator($this->getUser());

                $em->persist($exam);
                $em->flush();

                $count = 0;

                // A. Klassen
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $exam->getId(), $year, $singleClass);
                        $count++;
                    }
                }

                // B. Gruppen
                if (!empty($selectedGroups) && is_array($selectedGroups)) {
                    foreach ($selectedGroups as $groupAccount) {
                        $this->importParticipantsFromGroup($em, $conn, $exam, $groupAccount);
                        $count++;
                    }
                }

                $this->addFlash('success', 'Prüfung erfolgreich angelegt (OHNE Creator).');
                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                // Wir geben den kompletten Trace aus, falls es knallt
                $this->addFlash('error', 'Fehler: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes,
            'groups'  => $groupsForDropdown
        ]);
    }

    // --- HILFSMETHODE 1: KLASSEN ---
    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $importIds = $conn->fetchFirstColumn("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL AND importid <> ''
        ", [$class]);

        foreach ($importIds as $importId) {
            if (empty($importId)) continue;

            $row = $conn->fetchNumeric("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$importId]);

            if (!$row || empty($row[1])) continue;

            $pId = $row[0];
            $pDob = $row[1];
            $age = $examYear - (int)substr($pDob, 0, 4);

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $pId, $age]);
        }
    }

    // --- HILFSMETHODE 2: GRUPPEN ---
    private function importParticipantsFromGroup(EntityManagerInterface $em, Connection $conn, Exam $exam, string $groupAccount): void
    {
        $group = $em->getRepository(Group::class)->findOneBy(['account' => $groupAccount]);
        if (!$group) return;

        foreach ($group->getUsers() as $user) {
            $importId = $user->getImportId();
            $username = $user->getUsername();
            
            $row = false;

            if (!empty($importId)) {
                $row = $conn->fetchNumeric("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
                ", [$importId]);
            }

            if (!$row && !empty($username)) {
                $row = $conn->fetchNumeric("
                    SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE username = ?
                ", [$username]);
            }

            if (!$row || empty($row[1])) continue;

            $pId = $row[0];
            $pDob = $row[1];
            $age = $exam->getYear() - (int)substr($pDob, 0, 4);
            
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$exam->getId(), $pId, $age]);
        }
    }
}
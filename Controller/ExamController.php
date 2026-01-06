<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Exam;
use PulsR\SportabzeichenBundle\Repository\ExamRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Zentrale Verwaltung der Prüfungen
 */
#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamController extends AbstractPageController
{
    /**
     * DASHBOARD: Liste aller Prüfungen
     */
    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // ANPASSUNG: Property heißt jetzt 'year' (statt examYear/jahr)
        // Prüfe auch, ob 'examDate' in der Entity 'date' heißt.
        $exams = $examRepository->findBy([], ['year' => 'DESC', 'date' => 'DESC']);

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

        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $postData = $request->request->all();
                $selectedClasses = $postData['classes'] ?? []; 

                // 1. Prüfung als Entity anlegen
                $exam = new Exam();
                $exam->setName($name);
                // ANPASSUNG: Setter heißt setYear()
                $exam->setYear($year);
                $exam->setDate($date);

                $em->persist($exam);
                $em->flush();

                // 2. Teilnehmer importieren
                $count = 0;
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        $this->importParticipantsFromClass($conn, $exam->getId(), $year, $singleClass);
                        $count++;
                    }
                    $this->addFlash('success', sprintf('Prüfung angelegt und Teilnehmer aus %d Klassen/Gruppen importiert.', $count));
                } else {
                    $this->addFlash('success', 'Prüfung erfolgreich angelegt (ohne Teilnehmer).');
                }

                return $this->redirectToRoute('sportabzeichen_exams_dashboard');

            } catch (\Throwable $e) {
                $this->addFlash('error', 'Fehler beim Anlegen: ' . $e->getMessage());
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Exam $exam, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('exam_name'));
            $year = (int)$request->request->get('exam_year');
            if ($year < 100) $year += 2000;
            
            $dateStr = $request->request->get('exam_date');
            $date = $dateStr ? new \DateTime($dateStr) : null;

            $exam->setName($name);
            // ANPASSUNG: setYear
            $exam->setYear($year);
            $exam->setDate($date);

            $em->flush();

            $this->addFlash('success', 'Änderungen gespeichert.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        return $this->render('@PulsRSportabzeichen/exams/edit.html.twig', [
            'exam' => $exam
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Exam $exam, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $exam->getId(), $token)) {
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        try {
            $em->remove($exam);
            $em->flush();
            $this->addFlash('success', 'Prüfung wurde gelöscht.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    // --- HILFSMETHODE (SQL) ---
    // Hier bleibt SQL bestehen, da wir direkt Datenbank-Operationen machen.
    // Falls die DB-Spalten noch nicht umbenannt wurden, bleiben die Spaltennamen hier deutsch/snake_case!
    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL
        ", [$class]);

        foreach ($users as $u) {
            // Hinweis: Falls die Spalte 'geburtsdatum' in der DB noch so heißt, lassen wir sie so.
            // SQL interagiert mit der DB-Struktur, nicht mit PHP Entities.
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
}
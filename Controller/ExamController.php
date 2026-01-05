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
     * Hier nutzen wir jetzt das Repository, damit wir Objekte zurückbekommen!
     */
    #[Route('/', name: 'dashboard')]
    public function index(ExamRepository $examRepository): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // Holt echte Exam-Objekte, sortiert nach Jahr absteigend
        // Voraussetzungen: In der Entity Exam existieren die Felder 'year' und 'date'
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

        // Klassen laden (weiterhin per SQL, da Zugriff auf IServ 'users' Tabelle)
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo FROM users 
            WHERE auxinfo IS NOT NULL AND auxinfo <> '' 
            ORDER BY auxinfo
        ");

        if ($request->isMethod('POST')) {
            try {
                $name = trim($request->request->get('exam_name'));
                $year = (int)$request->request->get('exam_year');
                
                // Jahr normalisieren
                if ($year < 100) $year += 2000;
                
                $dateStr = $request->request->get('exam_date');
                $date = $dateStr ? new \DateTime($dateStr) : null;
                
                $postData = $request->request->all();
                $selectedClasses = $postData['classes'] ?? []; 

                // 1. Prüfung als Entity anlegen
                $exam = new Exam();
                $exam->setName($name);
                $exam->setYear($year);
                $exam->setDate($date);

                $em->persist($exam);
                $em->flush(); // Jetzt hat $exam eine ID

                // 2. Teilnehmer importieren (Hybrid-Lösung: SQL Helper nutzen für Performance)
                $count = 0;
                if (!empty($selectedClasses) && is_array($selectedClasses)) {
                    foreach ($selectedClasses as $singleClass) {
                        // Wir übergeben die ID des gerade erstellten Objekts
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

    /**
     * EDIT: Prüfung bearbeiten
     * Symfony lädt das Exam-Objekt automatisch anhand der {id} in der URL
     */
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

            // Setter nutzen
            $exam->setName($name);
            $exam->setYear($year);
            $exam->setDate($date);

            $em->flush(); // Speichert die Änderungen

            $this->addFlash('success', 'Änderungen gespeichert.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        return $this->render('@PulsRSportabzeichen/exams/edit.html.twig', [
            'exam' => $exam
        ]);
    }

    /**
     * DELETE: Prüfung löschen
     */
    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Exam $exam, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // CSRF Token Check
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete' . $exam->getId(), $token)) {
            $this->addFlash('error', 'Ungültiger Sicherheits-Token.');
            return $this->redirectToRoute('sportabzeichen_exams_dashboard');
        }

        try {
            // Löschen über den EntityManager.
            // Hinweis: Falls Datenbank-Foreign-Keys auf CASCADE stehen oder
            // die Entity cascade={"remove"} hat, werden Ergebnisse automatisch gelöscht.
            $em->remove($exam);
            $em->flush();

            $this->addFlash('success', 'Prüfung wurde gelöscht.');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Fehler beim Löschen: ' . $e->getMessage());
        }

        return $this->redirectToRoute('sportabzeichen_exams_dashboard');
    }

    // --- HILFSMETHODE (Bleibt vorerst SQL für Performance beim User-Import) ---

    private function importParticipantsFromClass(Connection $conn, int $examId, int $examYear, string $class): void
    {
        // 1. IServ User holen
        $users = $conn->fetchAllAssociative("
            SELECT importid FROM users 
            WHERE auxinfo = ? AND importid IS NOT NULL
        ", [$class]);

        foreach ($users as $u) {
            // 2. Entsprechenden Sportabzeichen-Participant suchen
            $participant = $conn->fetchAssociative("
                SELECT id, geburtsdatum FROM sportabzeichen_participants WHERE import_id = ?
            ", [$u['importid']]);

            if (!$participant || !$participant['geburtsdatum']) continue;

            $age = $examYear - (int)substr($participant['geburtsdatum'], 0, 4);

            // 3. Verknüpfung anlegen
            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_participants (exam_id, participant_id, age_year)
                VALUES (?, ?, ?) ON CONFLICT DO NOTHING
            ", [$examId, $participant['id'], $age]);
        }
    }
}
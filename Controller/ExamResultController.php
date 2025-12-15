<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams/results', name: 'sportabzeichen_results_')]
final class ExamResultController extends AbstractPageController
{
    /* --------------------------------------------------------
     * Altersklasse bestimmen
     * -------------------------------------------------------- */
    private function mapAgeToAltersklasse(int $age): string
    {
        $mapping = [
            [6, 7,   "AC0607"],
            [8, 9,   "AC0809"],
            [10, 11, "AC1011"],
            [12, 13, "AC1213"],
            [14, 15, "AC1415"],
            [16, 17, "AC1617"],
            [18, 19, "AC1819"],
            [20, 24, "AC2024"],
            [25, 29, "AC2529"],
            [30, 34, "AC3034"],
            [35, 39, "AC3539"],
            [40, 44, "AC4044"],
            [45, 49, "AC4549"],
            [50, 54, "AC5054"],
            [55, 59, "AC5559"],
            [60, 64, "AC6064"],
            [65, 69, "AC6569"],
            [70, 74, "AC7074"],
            [75, 79, "AC7579"],
            [80, 84, "AC8084"],
            [85, 89, "AC8589"],
            [90, 200, "AC9000"],
        ];

        foreach ($mapping as [$min, $max, $label]) {
            if ($age >= $min && $age <= $max) {
                return $label;
            }
        }
        return "AC2024";
    }


    /* --------------------------------------------------------
     * Klassen für Filter laden
     * -------------------------------------------------------- */
    private function loadClasses(Connection $conn): array
    {
        return $conn->fetchAllAssociative("
            SELECT DISTINCT auxinfo AS klasse
            FROM users
            WHERE auxinfo IS NOT NULL AND auxinfo <> ''
            ORDER BY auxinfo
        ");
    }


    /* --------------------------------------------------------
     * 1️⃣ Übersicht Prüfungen
     * -------------------------------------------------------- */
    #[Route('/', name: 'exams', methods: ['GET'])]
    public function examSelection(Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $exams = $conn->fetchAllAssociative("
            SELECT id, exam_name, exam_year, exam_date
            FROM sportabzeichen_exams
            ORDER BY exam_year DESC, exam_date DESC
        ");

        return $this->render('@PulsRSportabzeichen/results/index.html.twig', [
            'exams' => $exams,
        ]);
    }


    /* --------------------------------------------------------
     * 2️⃣ Ergebnisse eingeben
     * -------------------------------------------------------- */
    #[Route('/exam/{examId}', name: 'index', methods: ['GET'])]
    public function index(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        // Prüfung laden
        $exam = $conn->fetchAssociative("
            SELECT *
            FROM sportabzeichen_exams
            WHERE id = ?
        ", [$examId]);

        if (!$exam) {
            throw $this->createNotFoundException("Prüfung nicht gefunden.");
        }

        /* ------------------------------
         * Klassenfilter anwenden
         * ------------------------------ */
        $selectedClass = $request->query->get('class');
        $classes = $this->loadClasses($conn);

        if ($selectedClass) {
            $participants = $conn->fetchAllAssociative("
                SELECT ep.id AS ep_id,
                       p.vorname, p.nachname, p.geschlecht,
                       ep.age_year,
                       u.auxinfo AS klasse
                FROM sportabzeichen_exam_participants ep
                JOIN sportabzeichen_participants p ON p.id = ep.participant_id
                JOIN users u ON u.importid = p.import_id
                WHERE ep.exam_id = ?
                  AND u.auxinfo = ?
                ORDER BY p.nachname, p.vorname
            ", [$examId, $selectedClass]);
        } else {
            $participants = $conn->fetchAllAssociative("
                SELECT ep.id AS ep_id,
                       p.vorname, p.nachname, p.geschlecht,
                       ep.age_year,
                       u.auxinfo AS klasse
                FROM sportabzeichen_exam_participants ep
                JOIN sportabzeichen_participants p ON p.id = ep.participant_id
                JOIN users u ON u.importid = p.import_id
                WHERE ep.exam_id = ?
                ORDER BY p.nachname, p.vorname
            ", [$examId]);
        }

        /* ------------------------------
         * Altersklasse & Geschlecht vorbereiten
         * ------------------------------ */
        foreach ($participants as &$pp) {
            $pp['altersklasse'] = $this->mapAgeToAltersklasse((int)$pp['age_year']);
            $g = strtolower(trim($pp['geschlecht']));
            $pp['gender'] = ($g === 'm' || $g === 'male') ? 'MALE' : 'FEMALE';
        }
        unset($pp);

        /* ------------------------------
         * Disziplinen + Anforderungen
         * ------------------------------ */
        $rows = $conn->fetchAllAssociative("
            SELECT d.id, d.name, d.kategorie, d.einheit,
                   r.altersklasse, r.geschlecht, r.auswahlnummer
            FROM sportabzeichen_disciplines d
            JOIN sportabzeichen_requirements r ON d.id = r.discipline_id
            WHERE r.jahr = ?
            ORDER BY d.kategorie, r.auswahlnummer, d.name
        ", [$exam['exam_year']]);

        $disciplines = [];
        foreach ($rows as $row) {
            $disciplines[$row['kategorie']][] = $row;
        }
        foreach ($disciplines as &$items) {
            usort($items, fn($a, $b) => ($a['auswahlnummer'] <=> $b['auswahlnummer']));
        }
        unset($items);

        /* ------------------------------
         * Ergebnisse laden
         * ------------------------------ */
        $resultsRaw = $conn->fetchAllAssociative("
            SELECT *
            FROM sportabzeichen_exam_results
            WHERE ep_id IN (
                SELECT id FROM sportabzeichen_exam_participants WHERE exam_id = ?
            )
        ", [$examId]);

        $results = [];
        foreach ($resultsRaw as $r) {
            $results[$r['ep_id']][$r['discipline_id']] = $r;
        }

        return $this->render('@PulsRSportabzeichen/results/exam_results.html.twig', [
            'exam'         => $exam,
            'participants' => $participants,
            'disciplines'  => $disciplines,
            'results'      => $results,
            'classes'      => $classes,
            'selectedClass'=> $selectedClass,
        ]);
    }


    /* --------------------------------------------------------
     * 3️⃣ Einzel speichern
     * -------------------------------------------------------- */
    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request, Connection $conn): Response
    {
        $epId         = (int)$request->request->get('ep_id');
        $disciplineId = (int)$request->request->get('discipline_id');
        $leistung     = $request->request->get('leistung');

        if ($leistung === '' || $leistung === null) {
            $leistung = null;
        } else {
            $leistung = (float)$leistung;
        }

        $exists = $conn->fetchOne("
            SELECT id FROM sportabzeichen_exam_results
            WHERE ep_id = ? AND discipline_id = ?
        ", [$epId, $disciplineId]);

        if ($exists) {
            $conn->update('sportabzeichen_exam_results', [
                'leistung' => $leistung
            ], ['id' => $exists]);
        } else {
            $conn->insert('sportabzeichen_exam_results', [
                'ep_id'         => $epId,
                'discipline_id' => $disciplineId,
                'leistung'      => $leistung
            ]);
        }

        return new Response('OK');
    }


    #[Route('/exam/{examId}/save-all', name: 'save_all', methods: ['POST'])]
    public function saveAll(int $examId, Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        $formData = $request->request->all('results');

        if (!$formData) {
            $this->addFlash('warning', 'Keine Daten empfangen.');
            return $this->redirectToRoute('sportabzeichen_results_index', ['examId' => $examId]);
        }

        foreach ($formData as $epId => $entry) {

            $disciplineId = $entry['discipline'] ?? null;
            $leistung     = $entry['leistung'] ?? null;

            if (!$disciplineId) {
                continue;
            }

            $conn->executeStatement("
                INSERT INTO sportabzeichen_exam_results (ep_id, discipline_id, leistung)
                VALUES (:ep, :disc, :l)
                ON CONFLICT (ep_id, discipline_id)
                DO UPDATE SET leistung = EXCLUDED.leistung
            ", [
                'ep'   => (int)$epId,
                'disc' => (int)$disciplineId,
                'l'    => ($leistung === '' ? null : (float)$leistung),
            ]);
        }

        $this->addFlash('success', 'Alle Ergebnisse gespeichert!');

        return $this->redirectToRoute('sportabzeichen_results_index', ['examId' => $examId]);
    }
}

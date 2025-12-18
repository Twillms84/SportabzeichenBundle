<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Verwaltung der Prüfungen + Teilnehmer
 */
#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exam_')]
final class ExamController extends AbstractPageController
{
    /**
     * Liste aller Prüfungen
     */
    #[Route('/', name: 'index')]
    public function index(Connection $conn): Response
    {
        $exams = $conn->fetchAllAssociative("
            SELECT e.id,
                   e.exam_name,
                   e.exam_date,
                   e.exam_year,
                   (SELECT COUNT(*) 
                    FROM sportabzeichen_exam_participants ep 
                    WHERE ep.exam_id = e.id) AS participant_count
            FROM sportabzeichen_exams e
            ORDER BY e.exam_date DESC NULLS LAST, e.exam_name
        ");

        return $this->render('@PulsRSportabzeichen/exams/index.html.twig', [
            'exams' => $exams,
        ]);
    }

    /**
     * Prüfung erstellen (simple version)
     */
    #[Route('/new', name: 'new')]
    public function new(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE_PARTICIPANTS');

        if ($request->isMethod('POST')) {
            $name = trim($request->request->get('exam_name'));
            $year = (int)$request->request->get('exam_year');
            $date = $request->request->get('exam_date') ?: null;

            $conn->insert('sportabzeichen_exam', [
                'exam_name' => $name,
                'exam_year' => $year,
                'exam_date' => $date,
            ]);

            return $this->redirectToRoute('sportabzeichen_exam_index');
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig');
    }

    /**
     * Teilnehmerliste für eine Prüfung
     */
    #[Route('/{id}/participants', name: 'participants')]
    public function participants(int $id, Connection $conn): Response
    {
        // Prüfung holen
        $exam = $conn->fetchAssociative("
            SELECT * FROM sportabzeichen_exams WHERE id = ?
        ", [$id]);

        if (!$exam) {
            throw $this->createNotFoundException("Prüfung nicht gefunden");
        }

        // Teilnehmer holen
        $participants = $conn->fetchAllAssociative("
            SELECT ep.id AS ep_id,
                   ep.age_year,
                   p.vorname,
                   p.nachname,
                   p.geschlecht
            FROM sportabzeichen_exam_participants ep
            JOIN sportabzeichen_participants p ON p.id = ep.participant_id
            WHERE ep.exam_id = ?
            ORDER BY p.nachname, p.vorname
        ", [$id]);

        return $this->render('@PulsRSportabzeichen/exams/participants.html.twig', [
            'exam'        => $exam,
            'participants'=> $participants,
        ]);
    }

    /**
     * Teilnehmer zur Prüfung hinzufügen
     */
    #[Route('/{id}/participants/add', name: 'add_participant', methods: ['GET', 'POST'])]
    public function addParticipant(int $id, Request $request, Connection $conn): Response
    {
        $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$id]);
        if (!$exam) {
            throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            $participantId = (int)$request->request->get('participant_id');

            // Alter berechnen
            $ageYear = $exam['exam_year'] -
                       (int) $conn->fetchOne("SELECT EXTRACT(YEAR FROM geburtsdatum) FROM sportabzeichen_participants WHERE id = ?", [$participantId]);

            $conn->insert('sportabzeichen_exam_participants', [
                'exam_id'        => $id,
                'participant_id' => $participantId,
                'age_year'       => $ageYear,
            ]);

            return $this->redirectToRoute('sportabzeichen_exam_participants', ['id' => $id]);
        }

        // Teilnehmerliste holen
        $students = $conn->fetchAllAssociative("
            SELECT id, vorname, nachname
            FROM sportabzeichen_participants 
            ORDER BY nachname, vorname
        ");

        return $this->render('@PulsRSportabzeichen/exams/add_participant.html.twig', [
            'exam' => $exam,
            'students' => $students,
        ]);
    }
    #[Route('/{id}/participants/auto-add', name: 'auto_add_participants', methods: ['POST'])]
public function autoAddParticipants(int $id, Connection $conn): Response
{
    $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE');

    // Prüfung laden
    $exam = $conn->fetchAssociative("SELECT * FROM sportabzeichen_exams WHERE id = ?", [$id]);
    if (!$exam) {
        throw $this->createNotFoundException("Prüfung nicht gefunden");
    }

    // Alle Teilnehmer (global)
    $allParticipants = $conn->fetchAllAssociative("
        SELECT id, geburtsdatum
        FROM sportabzeichen_participants
        WHERE geschlecht IS NOT NULL 
          AND geburtsdatum IS NOT NULL
    ");

    // Bereits vorhandene Teilnehmer der Prüfung
    $existing = $conn->fetchFirstColumn("
        SELECT participant_id
        FROM sportabzeichen_exam_participants
        WHERE exam_id = ?
    ", [$id]);

    foreach ($allParticipants as $p) {

        if (in_array($p['id'], $existing)) {
            continue; // schon drin
        }

        // Altersberechnung
        $ageYear = $exam['exam_year'] - (int)substr($p['geburtsdatum'], 0, 4);

        // Einfügen
        $conn->insert('sportabzeichen_exam_participants', [
            'exam_id'        => $id,
            'participant_id' => $p['id'],
            'age_year'       => $ageYear
        ]);
    }

    return $this->redirectToRoute('sportabzeichen_exam_participants', ['id' => $id]);
    }
}

<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exam', name: 'sportabzeichen_exam_')]
final class ExamDisciplineSelectController extends AbstractPageController
{
    #[Route(
        '/{examId}/participant/{participantId}/disciplines',
        name: 'disciplines',
        requirements: ['examId' => '\d+', 'participantId' => '\d+'],
        methods: ['GET']
    )]
    public function disciplines(
        int $examId,
        int $participantId,
        Connection $conn
    ): Response {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        /* ------------------------------------------------------------
         * Exam laden
         * ------------------------------------------------------------ */
        $exam = $conn->fetchAssociative(
            'SELECT id, exam_year FROM sportabzeichen_exams WHERE id = ?',
            [$examId]
        );

        if (!$exam) {
            throw $this->createNotFoundException('Prüfung nicht gefunden');
        }

        /* ------------------------------------------------------------
         * Teilnehmer laden
         * ------------------------------------------------------------ */
        $participant = $conn->fetchAssociative(
            'SELECT id, geburtsdatum, geschlecht FROM sportabzeichen_participants WHERE id = ?',
            [$participantId]
        );

        if (!$participant || !$participant['geburtsdatum']) {
            throw $this->createNotFoundException('Teilnehmer nicht gefunden');
        }

        /* ------------------------------------------------------------
         * Alter berechnen (DOSB-konform)
         * ------------------------------------------------------------ */
        $birthYear = (int) (new \DateTime($participant['geburtsdatum']))->format('Y');
        $age = (int) $exam['exam_year'] - $birthYear;

        /* ------------------------------------------------------------
         * Passende Disziplinen laden
         * ------------------------------------------------------------ */
        $rows = $conn->fetchAllAssociative(
            <<<SQL
            SELECT
                d.kategorie,
                d.name,
                r.auswahlnummer,
                r.bronze,
                r.silber,
                r.gold,
                r.schwimmnachweis
            FROM sportabzeichen_requirements r
            JOIN sportabzeichen_disciplines d
                 ON d.id = r.discipline_id
            WHERE
                r.jahr = :year
            AND r.geschlecht = :gender
            AND :age BETWEEN r.age_min AND r.age_max
            AND d.kategorie IN ('Ausdauer','Schnelligkeit','Koordination','Kraft')
            ORDER BY
                d.kategorie,
                r.auswahlnummer DESC
            SQL,
            [
                'year'   => $exam['exam_year'],
                'gender'=> $participant['geschlecht'],
                'age'   => $age,
            ]
        );

        /* ------------------------------------------------------------
         * Nach Kategorien gruppieren
         * ------------------------------------------------------------ */
        $categories = [
            'Ausdauer'      => [],
            'Schnelligkeit' => [],
            'Koordination'  => [],
            'Kraft'         => [],
        ];

        foreach ($rows as $row) {
            $categories[$row['kategorie']][] = $row;
        }

        return $this->render('@PulsRSportabzeichen/exams/disciplines_select.html.twig', [
            'examId'      => $examId,
            'participantId'=> $participantId,
            'age'         => $age,
            'categories'  => $categories,
        ]);
    }
}

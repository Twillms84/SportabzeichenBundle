<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamDashboardController extends AbstractPageController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        Request $request,
        Connection $conn
    ): Response {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        /* ------------------------------------------------------------
         * Prüfungen laden
         * ------------------------------------------------------------ */
        $exams = $conn->fetchAllAssociative(
            'SELECT id, exam_name, exam_year, exam_date
             FROM sportabzeichen_exams
             ORDER BY exam_year DESC, exam_date DESC'
        );

        $examId = $request->query->getInt('exam');

        $participants = [];

        if ($examId > 0) {
            $participants = $conn->fetchAllAssociative(
                <<<SQL
                SELECT
                    p.id,
                    p.import_id,
                    p.geschlecht,
                    p.geburtsdatum
                FROM sportabzeichen_exam_participants ep
                JOIN sportabzeichen_participants p
                    ON p.id = ep.participant_id
                WHERE ep.exam_id = ?
                ORDER BY p.import_id
                SQL,
                [$examId]
            );
        }

        return $this->render('@PulsRSportabzeichen/exams/dashboard.html.twig', [
            'exams'        => $exams,
            'selectedExam' => $examId,
            'participants' => $participants,
        ]);
    }
}

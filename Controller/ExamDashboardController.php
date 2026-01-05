<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Repository\ExamRepository; // <--- Neu
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamDashboardController extends AbstractPageController
{
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(
        Request $request,
        ExamRepository $examRepository, // <--- Repository injizieren
        Connection $conn
    ): Response {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_RESULTS');

        /* ------------------------------------------------------------
         * Prüfungen als ENTITIES laden (statt SQL)
         * ------------------------------------------------------------ */
        // Holt Objekte zurück. Wir sortieren nach Jahr und Datum absteigend.
        // Achtung: In der Entity müssen die Felder 'year' und 'date' heißen.
        $exams = $examRepository->findBy([], ['year' => 'DESC', 'date' => 'DESC']);

        $examId = $request->query->getInt('exam');
        $participants = [];

        // Teilnehmer laden wir erstmal weiter per SQL, das ist okay für die Performance
        if ($examId > 0) {
            $participants = $conn->fetchAllAssociative(
                'SELECT p.id, p.import_id, p.geschlecht, p.geburtsdatum
                 FROM sportabzeichen_exam_participants ep
                 JOIN sportabzeichen_participants p ON p.id = ep.participant_id
                 WHERE ep.exam_id = ?
                 ORDER BY p.import_id',
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
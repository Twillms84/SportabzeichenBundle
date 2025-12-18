<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exam', name: 'sportabzeichen_exam_')]
final class ExamCreateController extends AbstractPageController
{
    #[Route('/new', name: 'new')]
    public function new(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $message = null;
        $error   = null;

        // --------------------------------------------------
        // IServ-Klassen laden
        // --------------------------------------------------
        $classes = $conn->fetchAllAssociative(
            'SELECT DISTINCT class
             FROM public.users
             WHERE class IS NOT NULL
             ORDER BY class'
        );

        // --------------------------------------------------
        // Formular verarbeiten
        // --------------------------------------------------
        if ($request->isMethod('POST')) {
            try {
                $examYear = (int) $request->request->get('exam_year');
                $examDate = $request->request->get('exam_date') ?: null;
                $class    = trim((string) $request->request->get('class'));

                if (!$examYear || !$class) {
                    throw new \RuntimeException('Prüfungsjahr und Klasse sind Pflichtfelder.');
                }

                // Prüfung anlegen
                $conn->insert('public.sportabzeichen_exams', [
                    'exam_year' => $examYear,
                    'exam_date' => $examDate,
                ]);

                $examId = (int) $conn->lastInsertId();

                // Teilnehmer aus IServ-Klasse übernehmen
                $users = $conn->fetchAllAssociative(
                    'SELECT importid, birthday
                     FROM public.users
                     WHERE class = :class
                       AND importid IS NOT NULL',
                    ['class' => $class]
                );

                foreach ($users as $user) {
                    // Participant sicherstellen
                    $participantId = $conn->fetchOne(
                        'SELECT id FROM public.sportabzeichen_participants WHERE import_id = ?',
                        [$user['importid']]
                    );

                    if (!$participantId) {
                        continue; // Teilnehmer wurde nicht hochgeladen
                    }

                    $age = $examYear - (int) substr($user['birthday'], 0, 4);

                    $conn->executeStatement(
                        'INSERT INTO public.sportabzeichen_exam_participants
                         (exam_id, participant_id, age_year)
                         VALUES (:exam, :participant, :age)
                         ON CONFLICT DO NOTHING',
                        [
                            'exam'       => $examId,
                            'participant'=> $participantId,
                            'age'        => $age,
                        ]
                    );
                }

                $message = 'Prüfung wurde erfolgreich angelegt.';

            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('@PulsRSportabzeichen/exams/new.html.twig', [
            'classes' => $classes,
            'message' => $message,
            'error'   => $error,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/exams', name: 'sportabzeichen_exams_')]
final class ExamCreateController extends AbstractPageController
{
    #[Route('/new', name: 'new')]
    public function new(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $message = null;
        $error   = null;

        /* --------------------------------------------------
         * IServ-Klassen laden (aus auxinfo->class)
         * -------------------------------------------------- */
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo->>'class'
            FROM users
            WHERE auxinfo ? 'class'
              AND auxinfo->>'class' <> ''
            ORDER BY auxinfo->>'class'
        ");

        /* --------------------------------------------------
         * Formular verarbeiten
         * -------------------------------------------------- */
        if ($request->isMethod('POST')) {
            try {
                $examYear = (int) $request->request->get('exam_year');
                $examDate = $request->request->get('exam_date') ?: null;
                $class    = trim((string) $request->request->get('class'));

                if (!$examYear || !$class) {
                    throw new \RuntimeException(
                        'Prüfungsjahr und Klasse sind Pflichtfelder.'
                    );
                }

                /* --------------------------------------------
                 * Prüfung anlegen
                 * -------------------------------------------- */
                $conn->insert('sportabzeichen_exams', [
                    'exam_year' => $examYear,
                    'exam_date' => $examDate,
                ]);

                $examId = (int) $conn->lastInsertId();

                /* --------------------------------------------
                 * IServ-User der Klasse laden
                 * -------------------------------------------- */
                $users = $conn->fetchAllAssociative("
                    SELECT importid, birthdate
                    FROM users
                    WHERE auxinfo->>'class' = :class
                      AND importid IS NOT NULL
                      AND birthdate IS NOT NULL
                ", [
                    'class' => $class
                ]);

                /* --------------------------------------------
                 * Teilnehmer zuweisen
                 * -------------------------------------------- */
                foreach ($users as $user) {

                    // Participant muss vorher importiert worden sein
                    $participantId = $conn->fetchOne(
                        'SELECT id FROM sportabzeichen_participants WHERE import_id = ?',
                        [$user['importid']]
                    );

                    if (!$participantId) {
                        continue;
                    }

                    $age = $examYear - (int) substr($user['birthdate'], 0, 4);

                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_exam_participants
                            (exam_id, participant_id, age_year)
                        VALUES (:exam, :participant, :age)
                        ON CONFLICT DO NOTHING
                    ", [
                        'exam'        => $examId,
                        'participant' => (int)$participantId,
                        'age'         => $age,
                    ]);
                }

                $message = 'Prüfung wurde erfolgreich angelegt und Teilnehmer zugewiesen.';

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

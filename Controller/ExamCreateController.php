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
         * Klassen aus IServ laden (auxinfo = Klasse)
         * -------------------------------------------------- */
        $classes = $conn->fetchFirstColumn("
            SELECT DISTINCT auxinfo
            FROM users
            WHERE auxinfo IS NOT NULL
              AND auxinfo <> ''
            ORDER BY auxinfo
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
                    throw new \RuntimeException('Prüfungsjahr und Klasse sind Pflichtfelder.');
                }

                // Prüfung anlegen
                $conn->insert('sportabzeichen_exams', [
                    'exam_year' => $examYear,
                    'exam_date' => $examDate,
                ]);

                $examId = (int) $conn->lastInsertId();

                /* ------------------------------------------
                 * Teilnehmer dieser Klasse ermitteln
                 * ------------------------------------------ */
                $users = $conn->fetchAllAssociative("
                    SELECT importid
                    FROM users
                    WHERE auxinfo = :class
                      AND importid IS NOT NULL
                ", ['class' => $class]);

                foreach ($users as $u) {

                    // Participant suchen
                    $participant = $conn->fetchAssociative("
                        SELECT id, geburtsdatum
                        FROM sportabzeichen_participants
                        WHERE import_id = ?
                    ", [$u['importid']]);

                    if (!$participant || !$participant['geburtsdatum']) {
                        continue;
                    }

                    // Alter berechnen
                    $age = $examYear - (int) substr($participant['geburtsdatum'], 0, 4);

                    // Zuordnung speichern
                    $conn->executeStatement("
                        INSERT INTO sportabzeichen_exam_participants
                            (exam_id, participant_id, age_year)
                        VALUES (:exam, :participant, :age)
                        ON CONFLICT DO NOTHING
                    ", [
                        'exam'        => $examId,
                        'participant' => $participant['id'],
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

<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class ParticipantUploadController extends AbstractPageController
{
    #[Route('/upload_participant', name: 'upload_participant')]
    public function upload(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $imported = 0;
        $skipped  = 0;
        $error    = null;

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                fgetcsv($handle); // Header

                while (($row = fgetcsv($handle)) !== false) {
                    try {
                        if (count($row) < 3) {
                            $skipped++;
                            continue;
                        }

                        [$importId, $geschlechtRaw, $geburtsdatumRaw] =
                            array_map('trim', $row);

                        if ($importId === '') {
                            $skipped++;
                            continue;
                        }

                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm' => 'MALE',
                            'w' => 'FEMALE',
                            default => null,
                        };

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        $conn->executeStatement(
                            <<<SQL
INSERT INTO sportabzeichen_participants
(import_id, geschlecht, geburtsdatum)
VALUES
(:import_id, :geschlecht, :geburtsdatum)
ON CONFLICT (import_id)
DO UPDATE SET
 geschlecht = EXCLUDED.geschlecht,
 geburtsdatum = EXCLUDED.geburtsdatum,
 updated_at = NOW()
SQL,
                            [
                                'import_id'    => $importId,
                                'geschlecht'   => $geschlecht,
                                'geburtsdatum' => $geburtsdatum,
                            ]
                        );

                        $imported++;

                    } catch (\Throwable) {
                        $skipped++;
                    }
                }

                fclose($handle);
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participant.html.twig', [
            'activeTab' => 'participants_upload',
            'imported'  => $imported,
            'skipped'   => $skipped,
            'error'     => $error,
            'message' => $message,
        ]);
    }

    private static function parseDate(?string $input): ?string
    {
        if (!$input) {
            return null;
        }

        foreach (['d.m.Y', 'd-m-Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }
}

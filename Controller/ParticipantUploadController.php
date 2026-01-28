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
    #[Route('/upload_participants', name: 'upload_participants')]
    public function upload(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $imported = 0;
        $skipped  = 0;
        $error    = null;
        $message  = null;
        
        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'import_id'); // Standard: import_id

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                
                // Wir lesen die erste Zeile, prüfen aber, ob es wirklich ein Header ist. 
                // Falls User Dateien ohne Header hochladen, könnte man das hier anpassen.
                // Hier gehen wir davon aus: Zeile 1 = Header -> wegwerfen.
                fgetcsv($handle); 

                // SQL Vorbereiten: User-ID finden
                // Wir suchen User, die NICHT gelöscht sind (deleted IS NULL)
                if ($strategy === 'act') {
                    $stmtLookup = $conn->prepare('SELECT id FROM users WHERE act = :val AND deleted IS NULL LIMIT 1');
                } else {
                    $stmtLookup = $conn->prepare('SELECT id FROM users WHERE import_id = :val AND deleted IS NULL LIMIT 1');
                }

                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    // Fallback für Komma-getrennte CSVs
                    if (count($row) < 2) {
                        $row = str_getcsv($row[0], ',');
                    }

                    try {
                        if (count($row) < 3) {
                            $skipped++;
                            continue;
                        }

                        [$identifier, $geschlechtRaw, $geburtsdatumRaw] = array_map('trim', $row);

                        if ($identifier === '') {
                            $skipped++;
                            continue;
                        }

                        // 1. User ID ermitteln
                        $userId = $stmtLookup->executeQuery(['val' => $identifier])->fetchOne();

                        if (!$userId) {
                            // User nicht in IServ gefunden -> Überspringen
                            $skipped++;
                            continue;
                        }

                        // 2. Daten aufbereiten
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich' => 'MALE',
                            'w', 'female', 'weiblich' => 'FEMALE',
                            'd', 'diverse', 'divers' => 'DIVERSE',
                            default => null,
                        };

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geburtsdatum || !$geschlecht) {
                            $skipped++;
                            continue;
                        }

                        // Import-ID nur schreiben, wenn Strategie Import-ID war, sonst lassen wir sie NULL (oder alt)
                        $importIdToSave = ($strategy === 'import_id') ? $identifier : null;

                        // 3. Speichern (Upsert auf Basis der User-ID)
                        // WICHTIG: Deine Tabelle sollte einen Unique-Index auf 'user_id' haben!
                        $conn->executeStatement(
                            <<<SQL
INSERT INTO sportabzeichen_participants
(user_id, import_id, geschlecht, geburtsdatum)
VALUES
(:uid, :import_id, :geschlecht, :geburtsdatum)
ON CONFLICT (user_id)
DO UPDATE SET
 geschlecht = EXCLUDED.geschlecht,
 geburtsdatum = EXCLUDED.geburtsdatum,
 -- Import ID nur überschreiben, wenn neue vorhanden, sonst alte lassen
 import_id = COALESCE(EXCLUDED.import_id, sportabzeichen_participants.import_id)
SQL,
                            [
                                'uid'          => $userId,
                                'import_id'    => $importIdToSave,
                                'geschlecht'   => $geschlecht,
                                'geburtsdatum' => $geburtsdatum,
                            ]
                        );

                        $imported++;

                    } catch (\Throwable $e) {
                        // Optional: $error = $e->getMessage(); um zu debuggen
                        $skipped++;
                    }
                }

                fclose($handle);
                $message = "Import abgeschlossen.";
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
            'imported'  => $imported,
            'skipped'   => $skipped,
            'error'     => $error,
            'message'   => $message,
        ]);
    }

    private static function parseDate(?string $input): ?string
    {
        if (!$input) {
            return null;
        }

        // Erweiterte Formate (z.B. Y-m-d) hinzugefügt
        foreach (['d.m.Y', 'd-m-Y', 'd/m/Y', 'Y-m-d'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }
}
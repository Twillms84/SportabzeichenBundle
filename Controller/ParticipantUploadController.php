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
        $detailedErrors = []; // Hier sammeln wir die Gründe
        
        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'import_id');

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                
                // Header lesen
                fgetcsv($handle); 
                $lineNumber = 1; // Wir starten bei 1 (Header war Zeile 1)

                // SQL Vorbereiten
                if ($strategy === 'act') {
                    // WICHTIG: LOWER() verwenden, um Groß-/Kleinschreibungsprobleme bei Usernamen zu vermeiden
                    $stmtLookup = $conn->prepare('SELECT id FROM users WHERE LOWER(act) = LOWER(:val) AND deleted IS NULL LIMIT 1');
                } else {
                    $stmtLookup = $conn->prepare('SELECT id FROM users WHERE import_id = :val AND deleted IS NULL LIMIT 1');
                }

                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    $lineNumber++;

                    // Fallback für Komma
                    if (count($row) < 2) {
                        $row = str_getcsv($row[0], ',');
                    }

                    try {
                        // Check: Spaltenanzahl
                        if (count($row) < 3) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenige Spalten (" . count($row) . " gefunden, 3 erwartet).";
                            continue;
                        }

                        [$identifier, $geschlechtRaw, $geburtsdatumRaw] = array_map('trim', $row);

                        // Check: Identifier leer?
                        if ($identifier === '') {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Identifikator (User/ID) ist leer.";
                            continue;
                        }

                        // 1. User ID suchen
                        $userId = $stmtLookup->executeQuery(['val' => $identifier])->fetchOne();

                        if (!$userId) {
                            $skipped++;
                            // Klare Fehlermeldung je nach Strategie
                            $msg = ($strategy === 'act') 
                                ? "Nutzer '$identifier' nicht gefunden (oder gelöscht)." 
                                : "Import-ID '$identifier' keinem Nutzer zugeordnet.";
                            $detailedErrors[] = "Zeile $lineNumber: $msg";
                            continue;
                        }

                        // 2. Daten prüfen
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich', 'maennlich' => 'MALE',
                            'w', 'female', 'weiblich' => 'FEMALE',
                            'd', 'diverse', 'divers' => 'DIVERSE',
                            default => null,
                        };

                        if (!$geschlecht) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Unbekanntes Geschlecht '$geschlechtRaw' bei User '$identifier'.";
                            continue;
                        }

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geburtsdatum) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Ungültiges Datum '$geburtsdatumRaw' bei User '$identifier'.";
                            continue;
                        }

                        // Import-ID Logik
                        $importIdToSave = ($strategy === 'import_id') ? $identifier : null;

                        // 3. Speichern
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
                        $skipped++;
                        $detailedErrors[] = "Zeile $lineNumber: SQL Fehler - " . $e->getMessage();
                    }
                }

                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import abgeschlossen.";
                } elseif ($skipped > 0) {
                    $error = "Es wurden keine Datensätze importiert. Bitte prüfe das Protokoll unten.";
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload', // Achtung: Name muss mit deinem Tab-System übereinstimmen
            'imported'  => $imported,
            'skipped'   => $skipped,
            'error'     => $error,
            'message'   => $message,
            'detailedErrors' => $detailedErrors, // Neu: Liste an die View übergeben
        ]);
    }

    private static function parseDate(?string $input): ?string
    {
        if (!$input) return null;
        
        // Verschiedene Formate probieren
        $formats = ['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y', 'j.n.Y'];
        
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            // Prüfung: Das Datum muss logisch korrekt sein und das Format exakt matchen (um 01.01.2023 vs 2023-01-01 zu unterscheiden)
            if ($dt !== false) {
                 return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
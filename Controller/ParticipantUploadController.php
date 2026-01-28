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
        $detailedErrors = [];
        
        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy', 'import_id');

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                
                // Header lesen und ignorieren
                fgetcsv($handle); 
                $lineNumber = 1;

                // ---------------------------------------------------------
                // 1. Spaltenname in der 'users' Tabelle ermitteln
                // ---------------------------------------------------------
                // Wir prüfen, ob 'import_id' existiert, sonst nehmen wir 'importid'
                $userImportCol = 'import_id'; 
                try {
                    $conn->executeQuery("SELECT import_id FROM users LIMIT 1");
                } catch (\Throwable $e) {
                    $userImportCol = 'importid';
                }

                // ---------------------------------------------------------
                // 2. Prepared Statements vorbereiten
                // ---------------------------------------------------------
                
                // Suche nach User ID und dessen Import-ID (falls wir sie brauchen)
                if ($strategy === 'act') {
                    // Suche via Accountname
                    $sqlLookup = "SELECT id, $userImportCol AS imp_id FROM users WHERE LOWER(act) = LOWER(:val) AND deleted IS NULL LIMIT 1";
                } else {
                    // Suche via Import-ID
                    $sqlLookup = "SELECT id, $userImportCol AS imp_id FROM users WHERE $userImportCol = :val AND deleted IS NULL LIMIT 1";
                }
                $stmtLookup = $conn->prepare($sqlLookup);

                // Prüfen, ob Teilnehmer existiert (via user_id)
                $stmtCheckExist = $conn->prepare('SELECT id FROM sportabzeichen_participants WHERE user_id = :uid');

                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    $lineNumber++;

                    // Fallback für Komma
                    if (count($row) < 2) {
                        $row = str_getcsv($row[0], ',');
                    }

                    try {
                        if (count($row) < 3) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenige Spalten.";
                            continue;
                        }

                        [$identifier, $geschlechtRaw, $geburtsdatumRaw] = array_map('trim', $row);

                        if ($identifier === '') {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: ID/Name ist leer.";
                            continue;
                        }

                        // A. User in IServ DB suchen
                        $userData = $stmtLookup->executeQuery(['val' => $identifier])->fetchAssociative();

                        if (!$userData) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: User '$identifier' nicht im System gefunden.";
                            continue;
                        }

                        $userId = $userData['id'];
                        // Fallback für Import-ID: Entweder die aus der DB, oder der Suchbegriff (wenn Strategie ImportID war), oder manuell generiert
                        $userImportId = $userData['imp_id']; 
                        if (!$userImportId && $strategy === 'import_id') {
                            $userImportId = $identifier;
                        }
                        if (!$userImportId) {
                            $userImportId = 'MANUAL_' . $userId; // Notlösung falls gar nichts da ist
                        }

                        // B. Daten validieren
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich', 'maennlich' => 'MALE',
                            'w', 'female', 'weiblich' => 'FEMALE',
                            'd', 'diverse', 'divers' => 'DIVERSE',
                            default => null,
                        };

                        if (!$geschlecht) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Unbekanntes Geschlecht '$geschlechtRaw'.";
                            continue;
                        }

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geburtsdatum) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Ungültiges Datum '$geburtsdatumRaw'.";
                            continue;
                        }

                        // C. UPDATE oder INSERT (Manuell statt ON CONFLICT)
                        
                        // Gibt es den Teilnehmer schon?
                        $existingPartId = $stmtCheckExist->executeQuery(['uid' => $userId])->fetchOne();

                        if ($existingPartId) {
                            // UPDATE
                            $conn->update('sportabzeichen_participants', [
                                'geschlecht' => $geschlecht,
                                'geburtsdatum' => $geburtsdatum,
                                // Import-ID aktualisieren wir nur, wenn sie vorher leer war oder sich geändert hat? 
                                // Besser: Wir lassen sie beim Update in Ruhe oder nutzen COALESCE Logik. 
                                // Hier einfach Update der Kerndaten:
                                'updated_at' => (new \DateTime())->format('Y-m-d H:i:s')
                            ], ['id' => $existingPartId]);
                        } else {
                            // INSERT
                            $conn->insert('sportabzeichen_participants', [
                                'user_id' => $userId,
                                'import_id' => $userImportId,
                                'geschlecht' => $geschlecht,
                                'geburtsdatum' => $geburtsdatum
                            ]);
                        }

                        $imported++;

                    } catch (\Throwable $e) {
                        $skipped++;
                        // Detaillierte Fehlermeldung hilft beim Debuggen
                        $detailedErrors[] = "Zeile $lineNumber: DB-Fehler - " . $e->getMessage();
                    }
                }

                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import erfolgreich abgeschlossen.";
                } elseif ($skipped > 0) {
                    $error = "Es konnten keine Daten importiert werden.";
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
            'imported'  => $imported,
            'skipped'   => $skipped,
            'error'     => $error,
            'message'   => $message,
            'detailedErrors' => $detailedErrors,
        ]);
    }

    private static function parseDate(?string $input): ?string
    {
        if (!$input) return null;
        
        $formats = ['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y', 'j.n.Y'];
        
        foreach ($formats as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) {
                 return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
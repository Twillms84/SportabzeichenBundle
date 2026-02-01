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
                
                // Header lesen und ignorieren (optional: prüfen ob Header korrekt)
                fgetcsv($handle); 
                $lineNumber = 1; // Wir starten bei 1 (Header war Zeile 1)

                // ---------------------------------------------------------
                // 1. Spaltenname in der 'users' Tabelle ermitteln
                // ---------------------------------------------------------
                // IServ speichert die Import-ID in neueren Versionen als 'import_id',
                // in älteren manchmal anders. Der Check ist gut.
                $userImportCol = 'import_id'; 
                try {
                    // Testabfrage
                    $conn->executeQuery("SELECT import_id FROM users LIMIT 1");
                } catch (\Throwable $e) {
                    $userImportCol = 'importid';
                }

                // ---------------------------------------------------------
                // 2. Query vorbereiten
                // ---------------------------------------------------------
                // Wir holen 'id' und 'act' (Username) aus der Core-Tabelle
                
                if ($strategy === 'act') {
                    // Suche via Accountname (case-insensitive für Robustheit)
                    $sqlLookup = "SELECT id, act FROM users WHERE LOWER(act) = LOWER(:val) AND deleted IS NULL LIMIT 1";
                } else {
                    // Suche via Import-ID (Exakte Übereinstimmung)
                    $sqlLookup = "SELECT id, act FROM users WHERE $userImportCol = :val AND deleted IS NULL LIMIT 1";
                }
                
                // Prepared Statement erstellen
                $stmtLookup = $conn->prepare($sqlLookup);

                // Check-Statement (Existiert der Teilnehmer schon?)
                $stmtCheckExist = $conn->prepare('SELECT id FROM sportabzeichen_participants WHERE user_id = :uid');

                // ---------------------------------------------------------
                // 3. CSV Durchlauf
                // ---------------------------------------------------------
                while (($row = fgetcsv($handle, 1000, ';')) !== false) {
                    $lineNumber++;

                    // Fallback für falsches Trennzeichen (Komma statt Semikolon)
                    if (count($row) < 2) {
                        $lineParsed = str_getcsv($row[0], ',');
                        if (count($lineParsed) >= 2) {
                            $row = $lineParsed;
                        }
                    }

                    try {
                        if (count($row) < 3) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Zu wenige Spalten (Erwartet: ID;Geschlecht;Datum).";
                            continue;
                        }

                        [$identifier, $geschlechtRaw, $geburtsdatumRaw] = array_map('trim', $row);

                        if ($identifier === '') {
                            $skipped++;
                            continue; // Leere Zeilen ignorieren ohne Fehler
                        }

                        // A. User in IServ DB suchen
                        // --------------------------
                        $userData = $stmtLookup->executeQuery(['val' => $identifier])->fetchAssociative();

                        if (!$userData) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber: Person '$identifier' nicht im IServ gefunden.";
                            continue;
                        }

                        $userId = $userData['id'];
                        $username = $userData['act']; // Accountname aus der DB holen

                        // B. Daten validieren
                        // -------------------
                        // Nur noch MALE / FEMALE erlauben (kein Divers mehr)
                        $geschlecht = match (strtolower($geschlechtRaw)) {
                            'm', 'male', 'männlich', 'maennlich' => 'MALE',
                            'w', 'f', 'female', 'weiblich'       => 'FEMALE',
                            default                              => null, 
                        };

                        if (!$geschlecht) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber ($identifier): Ungültiges Geschlecht '$geschlechtRaw'.";
                            continue;
                        }

                        $geburtsdatum = self::parseDate($geburtsdatumRaw);

                        if (!$geburtsdatum) {
                            $skipped++;
                            $detailedErrors[] = "Zeile $lineNumber ($identifier): Ungültiges Datum '$geburtsdatumRaw'.";
                            continue;
                        }

                        // C. Speichern (Ohne Import-ID in der Ziel-Tabelle)
                        // -------------------------------------------------
                        
                        // Prüfen ob Teilnehmer-Eintrag schon existiert
                        $existingPartId = $stmtCheckExist->executeQuery(['uid' => $userId])->fetchOne();

                        if ($existingPartId) {
                            // UPDATE
                            $conn->update('sportabzeichen_participants', [
                                'geschlecht'   => $geschlecht,
                                'geburtsdatum' => $geburtsdatum,
                                'username'     => $username, // Aktualisieren, falls sich der IServ-Accountname geändert hat
                                'updated_at'   => (new \DateTime())->format('Y-m-d H:i:s')
                            ], ['id' => $existingPartId]);
                        } else {
                            // INSERT
                            // Hier speichern wir KEINE Import-ID, sondern nur die Verknüpfung via user_id
                            $conn->insert('sportabzeichen_participants', [
                                'user_id'      => $userId,
                                'username'     => $username,
                                'geschlecht'   => $geschlecht,
                                'geburtsdatum' => $geburtsdatum,
                                // created_at wird meist von der DB per Default gesetzt, sonst hier hinzufügen
                            ]);
                        }

                        $imported++;

                    } catch (\Throwable $e) {
                        $skipped++;
                        $detailedErrors[] = "Zeile $lineNumber: Systemfehler - " . $e->getMessage();
                    }
                }

                fclose($handle);
                
                if ($imported > 0) {
                    $message = "Import erfolgreich: $imported Einträge verarbeitet.";
                } elseif ($skipped > 0 && empty($detailedErrors)) {
                     // Fallback falls alles übersprungen wurde aber keine Details gesammelt wurden
                    $error = "Es konnten keine Daten importiert werden.";
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import', // Achte darauf, dass der Tab-Name stimmt (import oder participants_upload)
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
                 // Reset auf 00:00:00 um keine Zeitverschiebungsprobleme zu haben
                 $dt->setTime(0, 0, 0);
                 // Wichtig: Checken ob das Parsing logisch war (z.B. verhindert 32.01.2023)
                 $errors = \DateTime::getLastErrors();
                 if ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                     continue;
                 }
                 return $dt->format('Y-m-d');
            }
        }
        return null;
    }
}
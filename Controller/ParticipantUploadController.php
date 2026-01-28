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
    public function upload(Request $request, AbstractPageController $em): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $message = null;
        $error = null;
        $stats = ['imported' => 0, 'skipped' => 0, 'updated' => 0];

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');
            $strategy = $request->request->get('strategy'); // 'import_id' oder 'act'

            if ($file) {
                try {
                    $conn = $em->getConnection();
                    $handle = fopen($file->getPathname(), 'r');
                    
                    // Prepared Statements vorbereiten für maximale Performance
                    // 1. User finden
                    if ($strategy === 'act') {
                        $stmtFindUser = $conn->prepare('SELECT id FROM users WHERE act = :val AND deleted IS NULL LIMIT 1');
                    } else {
                        $stmtFindUser = $conn->prepare('SELECT id FROM users WHERE import_id = :val AND deleted IS NULL LIMIT 1');
                    }

                    // 2. Teilnehmer eintragen oder aktualisieren (Upsert)
                    // Wir nutzen ON CONFLICT, um bestehende Daten zu überschreiben (z.B. Fehlerkorrektur im Datum)
                    $sqlUpsert = "
                        INSERT INTO sportabzeichen_participants (user_id, geburtsdatum, geschlecht, import_id)
                        VALUES (:uid, :dob, :gender, :impid)
                        ON CONFLICT (user_id) 
                        DO UPDATE SET 
                            geburtsdatum = EXCLUDED.geburtsdatum, 
                            geschlecht = EXCLUDED.geschlecht,
                            import_id = COALESCE(EXCLUDED.import_id, sportabzeichen_participants.import_id)
                    ";
                    
                    while (($row = fgetcsv($handle, 1000, ";")) !== false) {
                        // Support für Komma-getrennte Dateien, falls Semikolon fehlschlägt
                        if (count($row) < 2) {
                             $row = str_getcsv($row[0], ","); 
                        }

                        // Erwartetes Format: [0] => ID/Name, [1] => Geschlecht, [2] => Datum
                        if (count($row) < 3) {
                            $stats['skipped']++;
                            continue;
                        }

                        $identifier = trim($row[0]);
                        $genderRaw  = trim($row[1]);
                        $dateRaw    = trim($row[2]);

                        if (empty($identifier)) {
                            $stats['skipped']++;
                            continue;
                        }

                        // A. User ID ermitteln
                        $userId = $stmtFindUser->executeQuery(['val' => $identifier])->fetchOne();

                        if (!$userId) {
                            // User nicht im System gefunden
                            $stats['skipped']++;
                            continue;
                        }

                        // B. Daten normalisieren
                        $gender = $this->normalizeGender($genderRaw);
                        $dob    = $this->normalizeDate($dateRaw);

                        if (!$dob || !$gender) {
                            // Ungültige Daten
                            $stats['skipped']++;
                            continue;
                        }

                        // C. Importieren
                        try {
                            // Wenn wir nach Act suchen, haben wir keine Import-ID aus der CSV -> NULL oder wir lassen die alte
                            $importIdToSave = ($strategy === 'import_id') ? $identifier : null;

                            $conn->executeStatement($sqlUpsert, [
                                'uid'    => $userId,
                                'dob'    => $dob,
                                'gender' => $gender,
                                'impid'  => $importIdToSave
                            ]);
                            $stats['imported']++;
                        } catch (\Exception $e) {
                            $stats['skipped']++;
                        }
                    }
                    fclose($handle);

                    $message = sprintf(
                        "Import abgeschlossen. %d Nutzer aktualisiert/importiert. %d Zeilen übersprungen (nicht gefunden oder fehlerhaft).", 
                        $stats['imported'], 
                        $stats['skipped']
                    );

                } catch (\Exception $e) {
                    $error = 'Fehler beim Lesen der Datei: ' . $e->getMessage();
                }
            } else {
                $error = 'Bitte eine Datei auswählen.';
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'import',
            'message'   => $message,
            'error'     => $error,
            'stats'     => $stats
        ]);
    }

    // Hilfsfunktion: Geschlecht normalisieren
    private function normalizeGender(string $input): ?string
    {
        $input = strtolower(trim($input));
        if (in_array($input, ['m', 'male', 'männlich', 'maennlich'])) return 'MALE';
        if (in_array($input, ['w', 'f', 'female', 'weiblich'])) return 'FEMALE';
        if (in_array($input, ['d', 'diverse', 'divers'])) return 'DIVERSE';
        return null; // Ungültig
    }

    // Hilfsfunktion: Datum normalisieren (DD.MM.YYYY oder YYYY-MM-DD)
    private function normalizeDate(string $input): ?string
    {
        try {
            $dt = new \DateTime($input);
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    private static function parseDate(?string $input): ?string
    {
        if (!$input) return null;

        foreach (['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) return $dt->format('Y-m-d');
        }

        return null;
    }
}
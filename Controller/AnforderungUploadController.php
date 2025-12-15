<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AnforderungUploadController extends AbstractPageController
{
    private const CATEGORY_MAP = [
        'ENDURANCE'    => 'Ausdauer',
        'FORCE'        => 'Kraft',
        'RAPIDNESS'    => 'Schnelligkeit',
        'COORDINATION' => 'Koordination',
        'SWIMMING'     => 'Schwimmen',
    ];

    #[Route(path: '/upload', name: 'upload')]
    public function upload(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_REQUIREMENTS');

        $message = null;
        $error = null;
        $imported = 0;
        $skipped = 0;

        // Logging vorbereiten
        $logDir = '/var/lib/iserv/sportabzeichen/logs';
        @mkdir($logDir, 0775, true);
        $logFile = $logDir . '/requirements_import.log';

        file_put_contents($logFile, "=== Import " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');

            if (!$file) {
                $error = 'Keine Datei ausgewählt.';
            } elseif (strtolower($file->getClientOriginalExtension() ?? '') !== 'csv') {
                $error = 'Nur CSV-Dateien sind erlaubt.';
            } else {
                $tmpPath = $file->getRealPath();

                if (($handle = fopen($tmpPath, 'r')) === false) {
                    $error = 'CSV konnte nicht geöffnet werden.';
                } else {

                    // Encoding erkennen
                    $sample = fread($handle, 4096);
                    rewind($handle);
                    $encoding = mb_detect_encoding($sample, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);

                    file_put_contents($logFile, "Encoding erkannt: " . ($encoding ?: 'UNKNOWN') . "\n", FILE_APPEND);

                    // Konverter
                    $convert = function (array $row) use ($encoding) {
                        return array_map(function ($v) use ($encoding) {
                            $v = trim($v, " \t\n\r\0\x0B\"");
                            if ($encoding && $encoding !== 'UTF-8') {
                                $v = mb_convert_encoding($v, 'UTF-8', $encoding);
                            }
                            return $v;
                        }, $row);
                    };

                    // Kopfzeile überspringen
                    fgetcsv($handle, 0, ',');

                    while (($data = fgetcsv($handle, 0, ',')) !== false) {

                        $data = $convert($data);

                        if (count($data) < 14) {
                            $skipped++;
                            file_put_contents($logFile, "SKIP (zu wenige Spalten): " . json_encode($data) . "\n", FILE_APPEND);
                            continue;
                        }

                        try {
                            // CSV-Felder zuweisen
                            $nummer        = (int)$data[0];
                            $jahr          = (int)$data[1];
                            $altersklasse  = $data[2];
                            $geschlecht    = strtoupper($data[3]);
                            $auswahlnummer = (int)$data[4];
                            $disziplinName = $data[5];
                            $catCode       = strtoupper($data[6]);

                            $bronze = $data[7] !== '' ? (float)$data[7] : null;
                            $silber = $data[8] !== '' ? (float)$data[8] : null;
                            $gold   = $data[9] !== '' ? (float)$data[9] : null;
                            $einheit = $data[11] !== '' ? $data[11] : null;

                            // Boolean sauber parsen
                            $snVal = isset($data[12]) ? strtolower(trim($data[12])) : '';
                            $schwimmnachweis = match ($snVal) {
                                '1', 'true', 'yes', 'y', 't', 'wahr', 'ja' => true,
                                default => false,
                            };

                            $berechnung = strtoupper($data[13] ?: 'GREATER');

                            $kategorie = self::CATEGORY_MAP[$catCode] ?? $catCode;

                            // ============================================
                            // 1. Disziplin lookup (DBAL 3: fetchOne)
                            // ============================================
                            $disciplineId = $conn->fetchOne(
                                "SELECT id FROM sportabzeichen_disciplines WHERE name = ?",
                                [$disziplinName]
                            );

                            // 1b. Neue Disziplin anlegen
                            if (!$disciplineId) {
                                $conn->insert('sportabzeichen_disciplines', [
                                    'name'           => $disziplinName,
                                    'kategorie'      => $kategorie,
                                    'einheit'        => $einheit ?: '',
                                    'berechnungsart' => $berechnung,
                                ]);

                                $disciplineId = $conn->lastInsertId();
                                file_put_contents($logFile, "Neue Disziplin angelegt: '{$disziplinName}' (ID {$disciplineId})\n", FILE_APPEND);
                            }

                            // ============================================
                            // 2. Requirements upserten (mit Type-Binding!)
                            // ============================================
                            $sql = "
                                INSERT INTO sportabzeichen_requirements
                                    (discipline_id, jahr, altersklasse, geschlecht,
                                     bronze, silber, gold, schwimmnachweis, auswahlnummer)
                                VALUES
                                    (:discipline_id, :jahr, :ak, :g,
                                     :bronze, :silber, :gold, :sn, :auswahl)
                                ON CONFLICT (discipline_id, jahr, altersklasse, geschlecht)
                                DO UPDATE SET
                                    bronze = EXCLUDED.bronze,
                                    silber = EXCLUDED.silber,
                                    gold = EXCLUDED.gold,
                                    schwimmnachweis = EXCLUDED.schwimmnachweis,
                                    auswahlnummer = EXCLUDED.auswahlnummer
                            ";

                            $values = [
                                'discipline_id' => (int)$disciplineId,
                                'jahr'          => (int)$jahr,
                                'ak'            => $altersklasse,
                                'g'             => $geschlecht,
                                'bronze'        => $bronze,
                                'silber'        => $silber,
                                'gold'          => $gold,
                                'sn'            => (bool)$schwimmnachweis,
                                'auswahl'       => (int)$auswahlnummer,

                            ];

                            $types = [
                                'discipline_id' => \PDO::PARAM_INT,
                                'jahr'          => \PDO::PARAM_INT,
                                'ak'            => \PDO::PARAM_STR,
                                'g'             => \PDO::PARAM_STR,
                                'bronze'        => \PDO::PARAM_STR,
                                'silber'        => \PDO::PARAM_STR,
                                'gold'          => \PDO::PARAM_STR,
                                'sn'            => \PDO::PARAM_BOOL,
                                'auswahl'       => \PDO::PARAM_INT,
                            ];

                            $conn->executeStatement($sql, $values, $types);

                            $imported++;

                        } catch (\Throwable $e) {
                            $skipped++;
                            file_put_contents(
                                $logFile,
                                "ERROR in Zeile (Nummer {$nummer}): " . $e->getMessage() . "\n",
                                FILE_APPEND
                            );
                        }
                    }

                    fclose($handle);

                    $message =
                        "Import abgeschlossen: {$imported} Datensätze importiert, {$skipped} Zeilen übersprungen.";

                    file_put_contents($logFile, $message . "\n=== Import Ende ===\n", FILE_APPEND);
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload.html.twig', [
            'title'   => _('Disziplinanforderungen hochladen'),
            'message' => $message,
            'error'   => $error,
        ]);
    }
}

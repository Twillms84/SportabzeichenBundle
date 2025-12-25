<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AnforderungUploadController extends AbstractPageController
{
    private const CATEGORY_MAP = [
        'ENDURANCE'    => 'Ausdauer',
        'FORCE'        => 'Kraft',
        'RAPIDNESS'    => 'Schnelligkeit',
        'COORDINATION' => 'Koordination',
        'SWIMMING'     => 'Schwimmen',
    ];

    #[Route('/upload', name: 'upload')]
    public function upload(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        $message  = null;
        $error    = null;
        $imported = 0;
        $skipped  = 0;

        // --------------------------------------------------------
        // Logging
        // --------------------------------------------------------
        $logDir = '/var/lib/iserv/sportabzeichen/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $logFile = $logDir . '/requirements_import.log';

        file_put_contents($logFile, "=== Import " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');

            if (!$file) {
                $error = 'Keine Datei ausgewählt.';
            } elseif (strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Nur CSV-Dateien sind erlaubt.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                if (!$handle) {
                    $error = 'CSV konnte nicht geöffnet werden.';
                } else {
                    // Header überspringen
                    fgetcsv($handle, 0, ',');

                    while (($row = fgetcsv($handle, 0, ',')) !== false) {
                        try {
                            if (count($row) < 15) {
                                $skipped++;
                                continue;
                            }

                            // ------------------------------------------------
                            // CSV-Zuordnung
                            // ------------------------------------------------
                            $jahr   = (int) $row[1];
                            $ageMin = (int) $row[2];
                            $ageMax = (int) $row[3];

                            // Regel: age_max = 0 → 100
                            if ($ageMax === 0) {
                                $ageMax = 100;
                            }

                            if ($ageMax < $ageMin) {
                                throw new \RuntimeException(
                                    "Ungültiger Altersbereich {$ageMin}-{$ageMax}"
                                );
                            }

                            // Geschlecht normalisieren
                            $geschlechtRaw = strtolower(trim((string) $row[4]));
                            $geschlecht = match ($geschlechtRaw) {
                                'w' => 'FEMALE',
                                'm' => 'MALE',
                                default => throw new \RuntimeException(
                                    "Ungültiges Geschlecht '{$geschlechtRaw}'"
                                ),
                            };

                            $auswahlNr = (int) $row[5];
                            $disziplin = trim($row[6]);

                            $catCode   = strtoupper(trim($row[7]));
                            $kategorie = self::CATEGORY_MAP[$catCode] ?? $catCode;

                            $bronze = $row[8]  !== '' ? (float) $row[8]  : null;
                            $silber = $row[9]  !== '' ? (float) $row[9]  : null;
                            $gold   = $row[10] !== '' ? (float) $row[10] : null;

                            $verband = trim((string) ($row[11] ?? ''));
                                if ($verband === '') {
                                    $verband = null;
                                }
                            $einheit = $row[12] !== '' ? trim($row[12]) : '';
                            $berechnung = strtoupper(trim((string) ($row[14] ?? 'BIGGER'))); // Standard auf BIGGER

                            // 1. Disziplin suchen
                            $disciplineId = $conn->fetchOne(
                                'SELECT id FROM sportabzeichen_disciplines WHERE name = ?',
                                [$disziplin]
                            );

                            if (!$disciplineId) {
                                // Neu anlegen
                                $conn->insert('sportabzeichen_disciplines', [
                                    'name'           => $disziplin,
                                    'kategorie'      => $kategorie,
                                    'einheit'        => $einheit,
                                    'verband'        => $verband,  
                                    'berechnungsart' => $berechnung, // Hier wird es gesetzt
                                ]);
                                $disciplineId = (int) $conn->lastInsertId();
                            } else {
                                // 🔥 WICHTIG: Bestehende Disziplin aktualisieren, falls Berechnungsart falsch/leer
                                $conn->update('sportabzeichen_disciplines', 
                                    ['berechnungsart' => $berechnung], 
                                    ['id' => $disciplineId]
                                );
                            }

                            // Boolean sauber parsen
                            $snVal = isset($row[13]) ? strtolower(trim($row[13])) : '';
                            $schwimmnachweis = match ($snVal) {
                                '1', 'true', 'yes', 'y', 't', 'wahr', 'ja' => true,
                                default => false,
                            };

                            $berechnung = strtoupper(trim((string) ($row[14] ?? 'GREATER')));

                            // ------------------------------------------------
                            // Disziplin holen oder anlegen
                            // ------------------------------------------------
                            $disciplineId = $conn->fetchOne(
                                'SELECT id FROM sportabzeichen_disciplines WHERE name = ?',
                                [$disziplin]
                            );

                            if (!$disciplineId) {
                                $conn->insert('sportabzeichen_disciplines', [
                                    'name'           => $disziplin,
                                    'kategorie'      => $kategorie,
                                    'einheit'        => $einheit,
                                    'verband'        => $verband,  
                                    'berechnungsart' => $berechnung,
                                ]);

                                $disciplineId = (int) $conn->lastInsertId();
                            }

                            // ------------------------------------------------
                            // Requirement upserten
                            // ------------------------------------------------
                            $sql = <<<SQL
INSERT INTO sportabzeichen_requirements
(discipline_id, jahr, age_min, age_max, geschlecht,
 auswahlnummer, bronze, silber, gold, schwimmnachweis)
VALUES
(:discipline_id, :jahr, :age_min, :age_max, :geschlecht,
 :auswahl, :bronze, :silber, :gold, :sn)
ON CONFLICT (discipline_id, jahr, age_min, age_max, geschlecht)
DO UPDATE SET
 auswahlnummer   = EXCLUDED.auswahlnummer,
 bronze          = EXCLUDED.bronze,
 silber          = EXCLUDED.silber,
 gold            = EXCLUDED.gold,
 schwimmnachweis = EXCLUDED.schwimmnachweis
SQL;

                            $conn->executeStatement(
                            $sql,
                            [
                                'discipline_id' => $disciplineId,
                                'jahr'          => $jahr,
                                'age_min'       => $ageMin,
                                'age_max'       => $ageMax,
                                'geschlecht'    => $geschlecht,
                                'auswahl'       => $auswahlNr,
                                'bronze'        => $bronze,
                                'silber'        => $silber,
                                'gold'          => $gold,
                                'sn'            => $schwimmnachweis,
                            ],
                            [
                                'discipline_id' => \PDO::PARAM_INT,
                                'jahr'          => \PDO::PARAM_INT,
                                'age_min'       => \PDO::PARAM_INT,
                                'age_max'       => \PDO::PARAM_INT,
                                'geschlecht'    => \PDO::PARAM_STR,
                                'auswahl'       => \PDO::PARAM_INT,
                                'bronze'        => \PDO::PARAM_STR,
                                'silber'        => \PDO::PARAM_STR,
                                'gold'          => \PDO::PARAM_STR,
                                'sn'            => \PDO::PARAM_BOOL,   // 🔥 DAS IST DER ENTSCHEIDENDE TEIL
                            ]
                        );

                            $imported++;

                        } catch (\Throwable $e) {
                            $skipped++;
                            file_put_contents(
                                $logFile,
                                "ERROR: {$e->getMessage()} | " . json_encode($row) . "\n",
                                FILE_APPEND
                            );
                        }
                    }

                    fclose($handle);

                    $message = "Import abgeschlossen: {$imported} importiert, {$skipped} übersprungen.";
                    file_put_contents($logFile, $message . "\n", FILE_APPEND);
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload.html.twig', [
            'activeTab' => 'requirements_upload',
            'message'   => $message,
            'error'     => $error,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Teilnehmer-Upload mit IServ-Abgleich (über importid)
 */
#[Route(path: '/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class TeilnehmerUploadController extends AbstractPageController
{
    #[Route(path: '/upload_teilnehmer', name: 'upload_teilnehmer')]
    public function upload(Request $request, Connection $conn): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE');

        $message = null;
        $error = null;
        $importCount = 0;

        if ($request->isMethod('POST')) {
            $file = $request->files->get('csvFile');

            if (!$file) {
                $error = 'Keine Datei ausgewählt.';
            } elseif ($file->getClientOriginalExtension() !== 'csv') {
                $error = 'Nur CSV-Dateien sind erlaubt.';
            } else {
                try {
                    if (($handle = fopen($file->getPathname(), 'r')) !== false) {
                        fgetcsv($handle, 0, ','); // Kopfzeile überspringen

                        $stmt = $conn->prepare('
                            INSERT INTO sportabzeichen_participants
                                (import_id, vorname, nachname, geschlecht, geburtsdatum, updated_at)
                            VALUES (:import_id, :vorname, :nachname, :geschlecht, :geburtsdatum, NOW())
                            ON CONFLICT (import_id)
                            DO UPDATE SET
                                vorname = EXCLUDED.vorname,
                                nachname = EXCLUDED.nachname,
                                geschlecht = EXCLUDED.geschlecht,
                                geburtsdatum = EXCLUDED.geburtsdatum,
                                updated_at = NOW();
                        ');

                        while (($row = fgetcsv($handle, 0, ',')) !== false) {
                            if (count($row) < 3) {
                                continue;
                            }

                            [$importId, $geschlecht, $geburtsdatumRaw] = array_map('trim', $row);

                            // 🧭 Datumskonvertierung (z. B. 12:03:2008 → 2008-03-12)
                            $geburtsdatum = self::parseDate($geburtsdatumRaw);

                            // 🔍 IServ-Benutzerdaten holen (über importid)
                            $user = $conn->fetchAssociative(
                                'SELECT firstname, lastname FROM users WHERE importid = :importid',
                                ['importid' => $importId]
                            );

                            $vorname = $user['firstname'] ?? null;
                            $nachname = $user['lastname'] ?? null;

                            $stmt->execute([
                                'import_id'    => $importId,
                                'vorname'      => $vorname,
                                'nachname'     => $nachname,
                                'geschlecht'   => $geschlecht ?: null,
                                'geburtsdatum' => $geburtsdatum ?: null,
                            ]);

                            $importCount++;
                        }

                        fclose($handle);
                        $message = sprintf('✅ %d Teilnehmer importiert oder aktualisiert.', $importCount);
                    }
                } catch (\Throwable $e) {
                    $error = 'Fehler beim Import: ' . $e->getMessage();
                }
            }
        }

        return $this->render('@PulsRSportabzeichen/admin/upload_teilnehmer.html.twig', [
            'title'   => _('Teilnehmer-Upload'),
            'message' => $message,
            'error'   => $error,
        ]);
    }

    /**
     * Erkennt und konvertiert verschiedene Datumsformate in YYYY-MM-DD
     */
    private static function parseDate(?string $input): ?string
    {
        if (empty($input)) {
            return null;
        }

        $input = trim($input);

        $formats = ['d.m.Y', 'd-m-Y', 'd/m/Y', 'd:m:Y'];

        foreach ($formats as $format) {
            $dt = \DateTime::createFromFormat($format, $input);
            if ($dt instanceof \DateTime) {
                return $dt->format('Y-m-d');
            }
        }

        // Fallback
        $dt = \DateTime::createFromFormat('Y-m-d', $input);
        return $dt ? $dt->format('Y-m-d') : null;
    }
}

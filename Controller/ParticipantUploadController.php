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

            if (!$file || strtolower($file->getClientOriginalExtension()) !== 'csv') {
                $error = 'Bitte eine gültige CSV-Datei auswählen.';
            } else {
                $handle = fopen($file->getRealPath(), 'r');
                if ($handle !== false) {
                    fgetcsv($handle); // Header überspringen

                    while (($row = fgetcsv($handle)) !== false) {
                        try {
                            if (count($row) < 3) {
                                $skipped++;
                                continue;
                            }

                            [$importId, $geschlechtRaw, $geburtsdatumRaw] = array_map('trim', $row);

                            if ($importId === '') {
                                $skipped++;
                                continue;
                            }

                            $geschlecht = match (strtolower($geschlechtRaw)) {
                                'm', 'male', 'männlich' => 'MALE',
                                'w', 'female', 'weiblich' => 'FEMALE',
                                default => null,
                            };

                            $geburtsdatum = self::parseDate($geburtsdatumRaw);

                            // --- NEU: Username und User-ID aus IServ-Tabelle auflösen ---
                            // Wir suchen in der IServ 'users' Tabelle nach der importid
                            $iservUser = $conn->fetchAssociative(
                                'SELECT act FROM users WHERE importid = :iid LIMIT 1',
                                ['iid' => $importId]
                            );

                            $username = $iservUser ? $iservUser['act'] : null;

                            // Upsert: Jetzt inklusive der Spalten 'username' und 'user_id'
                            $conn->executeStatement(
                                <<<SQL
                                    INSERT INTO sportabzeichen_participants (
                                        import_id, username, user_id, geschlecht, geburtsdatum, updated_at
                                    )
                                    VALUES (
                                        :import_id, :username, :username, :geschlecht, :geburtsdatum, NOW()
                                    )
                                    ON CONFLICT (import_id)
                                    DO UPDATE SET
                                        username = EXCLUDED.username,
                                        user_id = EXCLUDED.user_id,
                                        geschlecht = EXCLUDED.geschlecht,
                                        geburtsdatum = EXCLUDED.geburtsdatum,
                                        updated_at = NOW()
                                SQL,
                                [
                                    'import_id'    => $importId,
                                    'username'     => $username, // 'act' wird in beide Spalten geschrieben
                                    'geschlecht'   => $geschlecht,
                                    'geburtsdatum' => $geburtsdatum,
                                ]
                            );

                            $imported++;

                        } catch (\Throwable $e) {
                            $skipped++;
                        }
                    }
                    fclose($handle);
                    
                    if ($imported > 0) {
                        $message = sprintf('%d Teilnehmer erfolgreich importiert/aktualisiert.', $imported);
                    }
                }
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
        if (!$input) return null;

        foreach (['d.m.Y', 'Y-m-d', 'd-m-Y', 'd/m/Y'] as $fmt) {
            $dt = \DateTime::createFromFormat($fmt, $input);
            if ($dt !== false) return $dt->format('Y-m-d');
        }

        return null;
    }
}
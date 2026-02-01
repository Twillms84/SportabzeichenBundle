<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller für die Ansicht des eingeloggten Schülers/Benutzers
 */
#[Route('/sportabzeichen/me', name: 'puls_r_sportabzeichen_my_results')]
#[IsGranted('PRIV_SPORTABZEICHEN_VIEW_OWN')]
final class MyResultsController extends AbstractController
{
    public function __construct(
        private readonly Connection $conn
    ) {}

    public function __invoke(): Response
    {
        $user = $this->getUser();
        
        // 1. Prüfen: Ist der User überhaupt in der participants Tabelle?
        $participant = $this->conn->fetchAssociative("
            SELECT * FROM sportabzeichen_participants WHERE user_id = :uid
        ", ['uid' => $user->getId()]);

        if (!$participant) {
            // Fallback, wenn User noch nie angelegt wurde
            return $this->render('@PulsRSportabzeichen/my_results/not_found.html.twig');
        }

        // TODO: Logik implementieren
        // 2. Aktuelles Alter berechnen
        // 3. Anforderungen für dieses Alter/Geschlecht laden
        // 4. Bereits erbrachte Leistungen (Results) laden und dazu matchen

        return $this->render('@PulsRSportabzeichen/my_results/index.html.twig', [
            'participant' => $participant,
            // 'requirements' => ...
            // 'results' => ...
        ]);
    }
}
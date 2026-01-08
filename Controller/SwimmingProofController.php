<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
use PulsR\SportabzeichenBundle\Service\SportabzeichenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_swimming_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class SwimmingProofController extends AbstractPageController
{
    // Der EntityManager muss per Constructor rein, damit $this->em funktioniert
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
    }

    #[Route('/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, SportabzeichenService $service): JsonResponse
    {
        // 1. JSON-Payload validieren
        $data = json_decode($request->getContent(), true);
        $epId = $data['ep_id'] ?? null;

        if (!$epId) {
            return new JsonResponse(['error' => 'Keine Teilnehmer-ID übermittelt'], 400);
        }

        // 2. Teilnehmer laden
        $ep = $this->em->getRepository(ExamParticipant::class)->find($epId);
        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        try {
            // 3. Logik an den Service delegieren
            // Wir übergeben den aktuell eingeloggten IServ-User für die Protokollierung
            $username = $this->getUser()->getUsername();
            $service->toggleManualSwimming($ep, (bool)($data['swimming'] ?? false), $username);

            // 4. Alles in die DB schreiben
            $this->em->flush();

            // 5. Medaille und Gesamtpunkte neu berechnen, damit das UI synchron bleibt
            $summary = $service->syncSummary($ep);

            return new JsonResponse([
                'status' => 'ok',
                'has_swimming' => $summary['has_swimming'],
                'total_points' => $summary['total'],
                'final_medal' => $summary['medal']
            ]);

        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Serverfehler: ' . $e->getMessage()], 500);
        }
    }
}
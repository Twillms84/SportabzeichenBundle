<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline; // Import nicht vergessen!
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
use PulsR\SportabzeichenBundle\Entity\SwimmingProof; // Vermutlich heißt deine Entity so?
use PulsR\SportabzeichenBundle\Service\SportabzeichenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_swimming_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class SwimmingProofController extends AbstractPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SportabzeichenService $service // Service muss hier rein!
    ) {
    }

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // ID holen, aber Standardwert null, falls nicht gesetzt
        $epId = $data['ep_id'] ?? null;
        $disciplineId = $data['discipline_id'] ?? null; 

        // 1. Teilnehmer suchen (Muss immer existieren)
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        // --- ENTSCHEIDUNG: LÖSCHEN ODER SPEICHERN? ---
        
        // Wenn discipline_id leer, 0 oder "-" ist -> LÖSCHEN
        if (empty($disciplineId) || $disciplineId === '-') {
            
            // WICHTIG: Hier musst du die Entity Klasse für den Schwimmnachweis eintragen.
            // Ich nenne sie hier "SwimmingProof", prüfe bitte, wie sie bei dir heißt!
            $existingProof = $this->em->getRepository(\PulsR\SportabzeichenBundle\Entity\SwimmingProof::class)
                                      ->findOneBy(['examParticipant' => $ep]);
            
            if ($existingProof) {
                $this->em->remove($existingProof);
                $this->em->flush();
            }
            
            // Flags für die Antwort
            $hasSwimming = false;
            $metVia = null;

        } else {
            // --- SPEICHERN ---
            
            // Jetzt suchen wir die Disziplin. Hier darf sie NICHT null sein.
            $discipline = $this->em->getRepository(\PulsR\SportabzeichenBundle\Entity\Discipline::class)
                                     ->find((int)$disciplineId);

            if (!$discipline) {
                // Das war dein ursprünglicher Fehler: Er landete hier, obwohl er löschen wollte
                return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
            }

            // Dein Service erstellt den Eintrag (und löscht vermutlich alte vorher?)
            $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
            $this->em->flush();

            $hasSwimming = true;
            $metVia = $discipline->getName();
        }

        // --- UPDATE DER PUNKTE ---
        $summary = $this->service->syncSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => $hasSwimming,
            'swimming_met_via' => $metVia,
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }
}
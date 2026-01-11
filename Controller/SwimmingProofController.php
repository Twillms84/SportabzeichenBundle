<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
use PulsR\SportabzeichenBundle\Entity\SwimmingProof; // <--- Das ist die Entity aus deinem Service!
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
        private readonly SportabzeichenService $service
    ) {
    }

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        // Werte sicher abrufen
        $epId = $data['ep_id'] ?? null;
        $disciplineId = $data['discipline_id'] ?? null;

        // 1. Teilnehmer (ExamParticipant) laden
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        // --- ENTSCHEIDUNG: LÖSCHEN ODER SPEICHERN? ---

        // FALL A: LÖSCHEN (ID ist leer oder "-")
        if (empty($disciplineId) || $disciplineId === '-') {
            
            // Logik aus deinem Service adaptiert:
            // Wir müssen den Proof anhand von Participant + Jahr finden
            $participant = $ep->getParticipant();
            $examYear = $ep->getExam()->getYear();

            $existingProof = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $examYear
            ]);
            
            if ($existingProof) {
                $this->em->remove($existingProof);
                $this->em->flush();
            }

        } 
        // FALL B: SPEICHERN (ID ist vorhanden)
        else {
            $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);

            if (!$discipline) {
                return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
            }

            // Dein Service übernimmt das Erstellen/Updaten
            $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
            // Service macht in createSwimmingProofFromDiscipline() schon ein flush(), 
            // aber sicherheitshalber hier nochmal, falls du das dort mal änderst.
            $this->em->flush(); 
        }

        // --- ZUSAMMENFASSUNG UPDATEN ---
        // Das berechnet Punkte und Medaille neu und gibt uns den aktuellen Status zurück
        $summary = $this->service->syncSummary($ep);

        // Antwort an das Frontend
        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => $summary['has_swimming'],     // Kommt jetzt dynamisch aus syncSummary
            'swimming_met_via' => $summary['met_via'],      // Name der Disziplin oder 'nicht vorhanden'
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }
}
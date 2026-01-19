<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant; // Achte auf den korrekten Namen!
use PulsR\SportabzeichenBundle\Entity\SwimmingProof; 
use PulsR\SportabzeichenBundle\Service\SportabzeichenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// 1. ROUTE PREFIX ANGEPASST: Damit es zum Twig passt ("sportabzeichen_results_...")
#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_results_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class SwimmingProofController extends AbstractPageController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SportabzeichenService $service
    ) {
    }

    // Route wird: sportabzeichen_results_exam_swimming_add_proof
    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $epId = $data['ep_id'] ?? null;
        $disciplineId = $data['discipline_id'] ?? null; 

        // ExamParticipant laden
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        $participant = $ep->getParticipant(); 
        $currentExamYear = $ep->getExam()->getYear(); 

        // Logik: Speichern (Löschen wird hier nicht mehr benötigt, da eigene Route)
        if (!empty($disciplineId) && $disciplineId !== '-') {
            $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);

            if (!$discipline) {
                return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
            }

            // Service erstellt neuen Eintrag
            $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
            $this->em->flush(); // Speichern erzwingen
        }

        // Summary neu berechnen
        $summary = $this->service->syncSummary($ep);
        
        // Neu laden für aktuelle Daten
        $this->em->refresh($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => $ep->hasSwimmingRequirementMet(), // Nutze Helper Methode aus Entity wenn vorhanden
            'swimming_met_via' => $ep->getSwimmingRequirementMetVia(),
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }

    // Route wird: sportabzeichen_results_exam_swimming_remove_proof
    #[Route('/exam/swimming/remove-proof', name: 'exam_swimming_remove_proof', methods: ['POST'])]
    public function removeSwimmingProof(Request $request): JsonResponse
    {
        try {
            // 1. Daten sicher auslesen (Body oder Post-Params)
            $content = $request->getContent();
            $data = !empty($content) ? json_decode($content, true) : [];
            
            // Fallback, falls jQuery $.post genutzt wird statt fetch body
            $epId = $jsonData['epId'] 
                ?? $jsonData['ep_id'] 
                ?? $postData['epId'] 
                ?? $postData['ep_id'] 
                ?? null;
            if (!$epId) {
                return new JsonResponse(['success' => false, 'message' => 'ID fehlt'], 400);
            }

            // 2. Entity laden (HIER WAR DER FEHLER: ExamParticipant statt ExamParticipation)
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

            if (!$ep) {
                return new JsonResponse(['success' => false, 'message' => 'Teilnehmer nicht gefunden'], 404);
            }

            // 3. Logik zum Löschen
            $participant = $ep->getParticipant();
            $currentExamYear = $ep->getExam()->getYear();

            // Den Nachweis von DIESEM Jahr suchen
            $proofToDelete = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $currentExamYear
            ]);
            
            if ($proofToDelete) {
                $this->em->remove($proofToDelete);
                $this->em->flush();
            }

            // 4. Prüfen, ob noch ein alter Nachweis da ist (Fallback für Anzeige)
            $bestValidProof = $this->service->getValidSwimmingProof($participant, $currentExamYear);
            
            // Summary neu berechnen (Punkte update)
            $summary = $this->service->syncSummary($ep);
            $this->em->refresh($ep); // Entity neu laden

            // Daten für die GUI vorbereiten
            $metVia = null;
            $hasSwimming = false;
            
            if ($bestValidProof) {
                $hasSwimming = true;
                $metVia = $bestValidProof->getRequirementMetVia() . ' (Altbestand)';
            }

            return new JsonResponse([
                'success' => true, 
                'epId' => $epId,
                'swimming_met_via' => $metVia,
                'has_swimming' => $hasSwimming,
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Exception $e) {
            // Fängt den Absturz ab und sendet sauberes JSON mit dem Fehlertext
            return new JsonResponse([
                'success' => false,
                'message' => 'Server Fehler: ' . $e->getMessage()
            ], 500);
        }
    }
}
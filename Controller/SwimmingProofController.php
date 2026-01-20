<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant; // WICHTIG!
use PulsR\SportabzeichenBundle\Entity\SwimmingProof; 
use PulsR\SportabzeichenBundle\Service\SportabzeichenService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_results_')]
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
        try {
            // 1. Daten auslesen
            $data = json_decode($request->getContent(), true);
            
            // Robustes Auslesen (ep_id oder epId)
            $epId = $data['ep_id'] ?? $data['epId'] ?? null;
            $disciplineId = $data['discipline_id'] ?? $data['disciplineId'] ?? null; 

            if (!$epId) {
                throw new \Exception('EP ID fehlt.');
            }

            // 2. Teilnehmer laden
            /** @var ExamParticipant|null $ep */
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // 3. Logik: Speichern
            // Wir prüfen, ob eine gültige Disziplin-ID übergeben wurde (und nicht nur der Platzhalter "-")
            if (!empty($disciplineId) && $disciplineId !== '-') {
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);

                if (!$discipline) {
                    throw new \Exception('Disziplin nicht gefunden.');
                }

                // Service erstellt neuen Eintrag
                // Der Service muss existieren und diese Methode haben!
                $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                $this->em->flush();
            }

            // 4. Update & Rückgabe
            // Summary neu berechnen
            $summary = $this->service->syncSummary($ep);
            $this->em->refresh($ep);

            // Daten holen
            $hasSwimming = false;
            $metVia = null;
            
            // Prüfen, ob durch diese Aktion oder Altbestand Schwimmen erfüllt ist
            // (Hier nutzen wir Helper-Methoden der Entity, falls vorhanden, sonst manuell)
            if (method_exists($ep, 'hasSwimmingRequirementMet')) {
                $hasSwimming = $ep->hasSwimmingRequirementMet();
                $metVia = $ep->getSwimmingRequirementMetVia();
            } else {
                // Fallback, falls Helper fehlt: Einfacher Check
                $hasSwimming = ($ep->getSwimmingDiscipline() !== null);
                $metVia = $hasSwimming ? $ep->getSwimmingDiscipline()->getName() : null;
            }

            return new JsonResponse([
                'status' => 'ok',
                'success' => true,
                'has_swimming' => $hasSwimming,
                'swimming_met_via' => $metVia,
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Exception $e) {
            // Fängt Fehler 500 ab und gibt sauberes JSON zurück
            return new JsonResponse([
                'status' => 'error', 
                'success' => false,
                'message' => 'Fehler beim Speichern: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/exam/swimming/remove-proof', name: 'exam_swimming_remove_proof', methods: ['POST'])]
    public function removeSwimmingProof(Request $request): JsonResponse
    {
        try {
            // 1. Daten sicher auslesen
            $content = $request->getContent();
            $jsonData = !empty($content) ? json_decode($content, true) : [];
            $postData = $request->request->all();

            $epId = $jsonData['epId'] ?? $jsonData['ep_id'] ?? $postData['epId'] ?? $postData['ep_id'] ?? null;

            if (!$epId) {
                throw new \Exception('ID fehlt.');
            }

            // 2. Entity laden
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // 3. Logik zum Löschen
            $participant = $ep->getParticipant();
            $currentExamYear = $ep->getExam()->getYear();

            $proofToDelete = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $currentExamYear
            ]);
            
            if ($proofToDelete) {
                $this->em->remove($proofToDelete);
                $this->em->flush();
            }

            // 4. Fallback prüfen (Altbestand)
            $bestValidProof = $this->service->getValidSwimmingProof($participant, $currentExamYear);
            
            // Summary neu berechnen
            $summary = $this->service->syncSummary($ep);
            $this->em->refresh($ep);

            // GUI Daten vorbereiten
            $metVia = null;
            $hasSwimming = false;
            
            if ($bestValidProof) {
                $hasSwimming = true;
                $metVia = $bestValidProof->getRequirementMetVia() . ' (Altbestand)';
            }

            return new JsonResponse([
                'status' => 'ok',
                'success' => true, 
                'epId' => $epId,
                'swimming_met_via' => $metVia,
                'has_swimming' => $hasSwimming,
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'success' => false,
                'message' => 'Fehler beim Löschen: ' . $e->getMessage()
            ], 500);
        }
    }
}
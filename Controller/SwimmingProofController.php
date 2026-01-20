<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use IServ\CoreBundle\Controller\AbstractPageController;
use PulsR\SportabzeichenBundle\Entity\Discipline;
use PulsR\SportabzeichenBundle\Entity\ExamParticipant;
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
            // 1. Daten holen
            $content = $request->getContent();
            $data = !empty($content) ? json_decode($content, true) : [];
            
            // IDs sicher auslesen
            $epId = $data['ep_id'] ?? $data['epId'] ?? null;
            $disciplineId = $data['discipline_id'] ?? $data['disciplineId'] ?? null;

            if (!$epId) {
                throw new \Exception('Teilnehmer-ID (ep_id) fehlt.');
            }

            // 2. Entity laden
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);
            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden ID: ' . $epId);
            }

            // 3. Speichern
            if (!empty($disciplineId) && $disciplineId !== '-') {
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);
                if (!$discipline) {
                    throw new \Exception('Disziplin nicht gefunden ID: ' . $disciplineId);
                }

                // Dieser Aufruf muss im Service existieren!
                $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                $this->em->flush();
            }

            // 4. Punkte berechnen
            $summary = $this->service->syncSummary($ep);
            $this->em->refresh($ep);

            // 5. Status ermitteln (Manuell, ohne Helper-Methoden, um Abstürze zu vermeiden)
            $hasSwimming = false;
            $metVia = null;

            // Prüfen: Wurde eine Schwimm-Disziplin direkt am EP gesetzt?
            if ($ep->getSwimmingDiscipline()) {
                $hasSwimming = true;
                $metVia = $ep->getSwimmingDiscipline()->getName();
            } 
            // Alternativ: Prüfen ob via Service/Altbestand etwas gefunden wird (optional)
            // $validProof = $this->service->getValidSwimmingProof($ep->getParticipant(), $ep->getExam()->getYear());
            // if ($validProof) { ... }

            return new JsonResponse([
                'status' => 'ok',
                'success' => true,
                'has_swimming' => $hasSwimming,
                'swimming_met_via' => $metVia,
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Throwable $e) {
            // \Throwable fängt ALLES, auch Syntax-Errors oder Type-Errors
            return new JsonResponse([
                'status' => 'error',
                'success' => false,
                'message' => 'Server Error: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString() // Hilft beim Debuggen
            ], 500);
        }
    }

    #[Route('/exam/swimming/remove-proof', name: 'exam_swimming_remove_proof', methods: ['POST'])]
    public function removeSwimmingProof(Request $request): JsonResponse
    {
        try {
            $content = $request->getContent();
            $data = !empty($content) ? json_decode($content, true) : [];
            $epId = $data['epId'] ?? $data['ep_id'] ?? null;

            if (!$epId) {
                throw new \Exception('ID fehlt.');
            }

            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);
            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // Löschen
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
            
            // Disziplin Verknüpfung am EP lösen
            $ep->setSwimmingDiscipline(null);
            $this->em->persist($ep);
            $this->em->flush();

            // Status neu berechnen
            $summary = $this->service->syncSummary($ep);
            
            // Prüfen ob Altbestand da ist
            $bestValidProof = $this->service->getValidSwimmingProof($participant, $currentExamYear);
            
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

        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Lösch-Fehler: ' . $e->getMessage()
            ], 500);
        }
    }
}
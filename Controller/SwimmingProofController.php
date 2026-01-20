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
            // 1. Payload parsen
            $content = $request->getContent();
            $data = !empty($content) ? json_decode($content, true) : [];
            
            $epId = $data['ep_id'] ?? $data['epId'] ?? null;
            $disciplineId = $data['discipline_id'] ?? $data['disciplineId'] ?? null;

            if (!$epId) {
                throw new \Exception('Teilnehmer-ID fehlt.');
            }

            /** @var ExamParticipant|null $ep */
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);
            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // 2. Disziplin laden
            if (!empty($disciplineId) && $disciplineId !== '-') {
                /** @var Discipline|null $discipline */
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);
                
                if (!$discipline) {
                    throw new \Exception('Disziplin nicht gefunden.');
                }

                // 3. SERVICE AUFRUF (Hier nutzen wir deine Logik)
                // Diese Methode erstellt/updated den Proof, setzt ValidUntil etc.
                $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                
                // Falls du zusätzlich am EP die Schwimmdisziplin speichern willst (fürs Dropdown):
                if (method_exists($ep, 'setSwimmingDiscipline')) {
                    $ep->setSwimmingDiscipline($discipline);
                    $this->em->persist($ep);
                    $this->em->flush();
                }
            }

            // 4. Punkte & Medaillen synchronisieren
            $summary = $this->service->syncSummary($ep);
            
            // WICHTIG: Entity neu laden, um sicherzustellen, dass Beziehungen aktuell sind
            $this->em->refresh($ep);

            // 5. Antwort vorbereiten
            return new JsonResponse([
                'status' => 'ok',
                'success' => true,
                'has_swimming' => $summary['has_swimming'] ?? false,
                'swimming_met_via' => $summary['met_via'] ?? ($discipline ? $discipline->getName() : 'Manuell'),
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Throwable $e) {
            // Fängt ALLES ab und verhindert die HTML-Fehlerseite
            return new JsonResponse([
                'status' => 'error',
                'success' => false,
                'message' => 'Fehler: ' . $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
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

            /** @var ExamParticipant|null $ep */
            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);
            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // 1. Nachweis löschen
            $participant = $ep->getParticipant();
            $examYear = $ep->getExam()->getYear();

            $proofToDelete = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $examYear
            ]);
            
            if ($proofToDelete) {
                $this->em->remove($proofToDelete);
            }

            // 2. Auch die Verknüpfung am ExamParticipant lösen (falls vorhanden)
            if (method_exists($ep, 'setSwimmingDiscipline')) {
                $ep->setSwimmingDiscipline(null);
            }
            // Falls es Legacy Support Methode gibt
            if (method_exists($participant, 'setSwimmingProof')) {
                 // Manche Implementierungen nutzen setSwimmingProof(false) oder null
                 // Wir lassen es hier sicherheitshalber weg oder setzen es auf false, wenn du sicher bist.
            }

            $this->em->flush();

            // 3. Neu berechnen
            $summary = $this->service->syncSummary($ep);

            return new JsonResponse([
                'success' => true, 
                'epId' => $epId,
                'swimming_met_via' => $summary['met_via'] ?? null,
                'has_swimming' => $summary['has_swimming'] ?? false,
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
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
        try {
            $data = json_decode($request->getContent(), true);
            
            $epId = $data['ep_id'] ?? null;
            $disciplineId = $data['discipline_id'] ?? null;

            if (!$epId) {
                return new JsonResponse(['error' => 'Keine EP-ID'], 400);
            }

            $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);
            if (!$ep) {
                return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
            }

            // --- LÖSCHEN ---
            if (empty($disciplineId) || $disciplineId === '-') {
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
            // --- SPEICHERN ---
            else {
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);
                if ($discipline) {
                    $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                    $this->em->flush();
                }
            }

            // Summary update
            $summary = $this->service->syncSummary($ep);

            return new JsonResponse([
                'status' => 'ok',
                'has_swimming' => $summary['has_swimming'] ?? false,
                'swimming_met_via' => $summary['met_via'] ?? null,
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Throwable $e) {
            // Fängt Fehler ab, damit der Server nicht abstürzt, und loggt sie
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
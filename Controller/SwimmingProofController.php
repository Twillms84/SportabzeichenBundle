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

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$data['ep_id']);
        $discipline = $this->em->getRepository(Discipline::class)->find((int)$data['discipline_id']);

        if (!$ep || !$discipline) {
            return new JsonResponse(['error' => 'Daten unvollständig'], 400);
        }

        // Service-Methode: Erstellt den SwimmingProof Eintrag
        $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
        $this->em->flush();

        // WICHTIG: syncSummary berechnet nun die Medaille neu, da has_swimming jetzt true ist
        $summary = $this->service->syncSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => true,
            'swimming_met_via' => $discipline->getName(),
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }
}
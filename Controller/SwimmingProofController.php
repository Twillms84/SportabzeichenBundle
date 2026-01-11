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
        
        $epId = (int)($data['ep_id'] ?? 0);
        $disciplineId = $data['discipline_id'] ?? null; // Kann leer sein!

        $ep = $this->em->getRepository(ExamParticipant::class)->find($epId);

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        // --- FALL A: LÖSCHEN (Discipline ID ist leer oder 0 oder "-") ---
        if (empty($disciplineId) || $disciplineId === '-') {
            
            // 1. Vorhandenen Nachweis suchen und löschen
            // Annahme: Es gibt eine Relation oder wir suchen die Entity direkt.
            // Falls du eine Methode im Service hast wie $this->service->removeSwimmingProof($ep), nutze diese.
            // Andernfalls direkt via EntityManager:
            
            // Beispiel: Wir suchen den Nachweis zu diesem Teilnehmer
            // ACHTUNG: Passe 'SwimmingProof' an deine echte Entity-Klasse an!
            $existingProof = $this->em->getRepository(SwimmingProof::class)->findOneBy(['examParticipant' => $ep]);
            
            if ($existingProof) {
                $this->em->remove($existingProof);
                $this->em->flush();
            }

            $hasSwimming = false;
            $metVia = null;

        } 
        // --- FALL B: NEU SETZEN (Discipline ID ist vorhanden) ---
        else {
            $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);

            if (!$discipline) {
                return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
            }

            // Alten Nachweis ggf. bereinigen, falls Create das nicht automatisch macht
            // Aber dein Service createSwimmingProofFromDiscipline regelt das vermutlich.
            $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
            $this->em->flush();

            $hasSwimming = true;
            $metVia = $discipline->getName();
        }

        // --- GEMEINSAMER ABSCHLUSS ---
        
        // Berechnung neu anstoßen (Punkte & Medaille aktualisieren)
        // Wenn Nachweis weg ist, wird Medaille ggf. wieder 'none'
        $summary = $this->service->syncSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => $hasSwimming, // true oder false
            'swimming_met_via' => $metVia,  // Name oder null
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }
}
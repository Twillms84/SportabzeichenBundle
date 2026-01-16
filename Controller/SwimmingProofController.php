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
        $data = json_decode($request->getContent(), true);
        
        $epId = $data['ep_id'] ?? null;
        $disciplineId = $data['discipline_id'] ?? null; 

        // 1. ExamParticipant laden
        $ep = $this->em->getRepository(ExamParticipant::class)->find((int)$epId);

        if (!$ep) {
            return new JsonResponse(['error' => 'Teilnehmer nicht gefunden'], 404);
        }

        // Stammdaten-Teilnehmer laden (an dem hängen die Schwimmnachweise!)
        $participant = $ep->getParticipant(); 
        $currentExamYear = $ep->getExam()->getYear(); // Wir brauchen das Jahr

        // --- ENTSCHEIDUNG: LÖSCHEN ODER SPEICHERN? ---
        
        if (empty($disciplineId) || $disciplineId === '-') {
            // === LÖSCHEN ===
            
            // Strategie: Wir löschen nur den Nachweis, der im aktuellen Prüfungsjahr erstellt wurde.
            // Ein Nachweis von vor 2 Jahren darf hier nicht gelöscht werden, nur weil man im Dropdown "-" wählt.
            
            $proofToDelete = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $currentExamYear // Nur den von diesem Jahr löschen!
            ]);
            
            if ($proofToDelete) {
                $this->em->remove($proofToDelete);
                $this->em->flush();
            }
            
            // Jetzt kommt Issue 3: Checken, ob noch ein ALTER Nachweis da ist (Fallback)
            // Wir nutzen den Service, um den "besten noch gültigen" Nachweis zu finden.
            // (Ich gehe davon aus, dass dein Service so eine Methode hat oder wir sie simulieren müssen)
            $bestValidProof = $this->service->getValidSwimmingProof($participant, $currentExamYear);
            
            if ($bestValidProof) {
                // Es gibt noch einen alten gültigen!
                $hasSwimming = true;
                $metVia = $bestValidProof->getRequirementMetVia() . ' (Altbestand)';
                // Optional: ValidUntil formatieren
            } else {
                // Wirklich nichts mehr da
                $hasSwimming = false;
                $metVia = null;
            }

        } else {
            // === SPEICHERN ===
            
            $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);

            if (!$discipline) {
                return new JsonResponse(['error' => 'Disziplin nicht gefunden'], 404);
            }

            // Service erstellt neuen Eintrag für dieses Jahr
            // (Der Service sollte intern prüfen, ob für dieses Jahr schon einer existiert und ihn ggf. updaten)
            $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
            $this->em->flush();

            $hasSwimming = true;
            $metVia = $discipline->getName();
        }

        // --- UPDATE DER PUNKTE ---
        // Das SyncSummary berechnet neu, ob "Schwimmen" erfüllt ist für das Abzeichen
        $summary = $this->service->syncSummary($ep);

        return new JsonResponse([
            'status' => 'ok',
            'has_swimming' => $hasSwimming,
            'swimming_met_via' => $metVia,
            'total_points' => $summary['total'],
            'final_medal' => $summary['medal']
        ]);
    }

    #[Route('/exam/swimming/remove-proof', name: 'sportabzeichen_results_exam_swimming_remove_proof', methods: ['POST'])]
    public function removeSwimmingProof(Request $request): JsonResponse
    {
        // 1. Daten holen
        $data = json_decode($request->getContent(), true);
        $epId = $data['epId'] ?? null;

        if (!$epId) {
            return new JsonResponse(['success' => false, 'message' => 'ID fehlt'], 400);
        }

        // 2. Entity laden
        $em = $this->getDoctrine()->getManager();
        $participation = $em->getRepository(ExamParticipation::class)->find($epId);

        if (!$participation) {
            return new JsonResponse(['success' => false, 'message' => 'Teilnehmer nicht gefunden'], 404);
        }

        // 3. Schwimm-Daten löschen (auf null setzen)
        $participation->setSwimmingDiscipline(null);
        // Falls du weitere Felder hast, die zurückgesetzt werden müssen (z.B. manuelles Datum):
        // $participation->setSwimmingDate(null); 

        $em->persist($participation);
        $em->flush();

        // 4. WICHTIG: JSON zurückgeben, kein HTML!
        return new JsonResponse([
            'success' => true, 
            'epId' => $epId
        ]);
    }
}
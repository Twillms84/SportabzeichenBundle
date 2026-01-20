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
            $content = $request->getContent();
            $data = !empty($content) ? json_decode($content, true) : [];
            
            $epId = $data['ep_id'] ?? $data['epId'] ?? null;
            $disciplineId = $data['discipline_id'] ?? $data['disciplineId'] ?? null;

            if (!$epId) {
                throw new \Exception('Teilnehmer-ID fehlt.');
            }

            // --- FIX: Eager Loading statt find() ---
            // Wir laden ep, participant (p) UND user (u) gleichzeitig.
            // Das verhindert den "Missing value for primary key username" Fehler.
            /** @var ExamParticipant|null $ep */
            $ep = $this->em->createQueryBuilder()
                ->select('ep', 'p', 'u')
                ->from(ExamParticipant::class, 'ep')
                ->join('ep.participant', 'p')
                ->join('p.user', 'u')
                ->where('ep.id = :id')
                ->setParameter('id', (int)$epId)
                ->getQuery()
                ->getOneOrNullResult();
            // ---------------------------------------

            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            // Disziplin laden
            if (!empty($disciplineId) && $disciplineId !== '-') {
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);
                if (!$discipline) {
                    throw new \Exception('Disziplin nicht gefunden.');
                }

                // Service-Aufruf (jetzt sicher, da User geladen ist)
                $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                
                // Optional: Speichern, welche Disziplin gewählt wurde (falls Feld existiert)
                if (method_exists($ep, 'setSwimmingDiscipline')) {
                    $ep->setSwimmingDiscipline($discipline);
                    $this->em->persist($ep);
                    $this->em->flush();
                }
            }

            // Zusammenfassung aktualisieren
            $summary = $this->service->syncSummary($ep);
            
            // Entity refreshen, um sicherzugehen
            $this->em->refresh($ep);

            return new JsonResponse([
                'status' => 'ok',
                'success' => true,
                'has_swimming' => $summary['has_swimming'] ?? false,
                'swimming_met_via' => $summary['met_via'] ?? ($discipline ? $discipline->getName() : 'Manuell'),
                'total_points' => $summary['total'] ?? 0,
                'final_medal' => $summary['medal'] ?? 'none'
            ]);

        } catch (\Throwable $e) {
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

            // Eager Loading (wie gehabt)
            $ep = $this->em->createQueryBuilder()
                ->select('ep', 'p', 'u')
                ->from(ExamParticipant::class, 'ep')
                ->join('ep.participant', 'p')
                ->join('p.user', 'u')
                ->where('ep.id = :id')
                ->setParameter('id', (int)$epId)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$ep) {
                throw new \Exception('Teilnehmer nicht gefunden.');
            }

            $participant = $ep->getParticipant();
            $examYear = $ep->getExam()->getYear();

            /** @var SwimmingProof|null $proofToDelete */
            $proofToDelete = $this->em->getRepository(SwimmingProof::class)->findOneBy([
                'participant' => $participant,
                'examYear' => $examYear
            ]);
            
            // --- NEU: Prüfung auf Herkunft des Nachweises ---
            if ($proofToDelete) {
                $via = $proofToDelete->getRequirementMetVia();
                
                // Wenn der Nachweis automatisch durch eine Disziplin (z.B. Ausdauer) kam,
                // beginnt der String mit "DISCIPLINE:". Dann darf hier nicht gelöscht werden.
                if ($via && str_starts_with($via, 'DISCIPLINE:')) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Dieser Nachweis ist an eine Disziplin gebunden und kann nur über die Leistungstabelle entfernt werden.'
                    ], 400); // 400 Bad Request verhindert, dass das JS das UI updatet
                }

                $this->em->remove($proofToDelete);
            }
            // ------------------------------------------------

            // Verknüpfung am ExamParticipant lösen
            if (method_exists($ep, 'setSwimmingDiscipline')) {
                $ep->setSwimmingDiscipline(null);
            }

            $this->em->flush();
            
            // Neu berechnen
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
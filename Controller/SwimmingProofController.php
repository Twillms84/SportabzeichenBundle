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

            // Eager Loading: Participant und User mitladen
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
            $examYear = $ep->getExam()->getYear(); // z.B. 2026

            $repo = $this->em->getRepository(SwimmingProof::class);

            // 1. Wir suchen explizit einen Nachweis für das AKTUELLE Prüfungsjahr
            /** @var SwimmingProof|null $proofToDelete */
            $proofToDelete = $repo->findOneBy([
                'participant' => $participant,
                'examYear' => $examYear
            ]);
            
            // =================================================================
            // LOGIK ANPASSUNG: Feedback bei historischem Nachweis
            // =================================================================
            if (!$proofToDelete) {
                // Wir haben für 2026 nichts gefunden. 
                // Prüfen wir, ob vielleicht ein alter, noch gültiger Nachweis existiert?
                // Wir suchen den aktuellsten Nachweis für diesen Teilnehmer
                $historicalProof = $repo->findOneBy(
                    ['participant' => $participant], 
                    ['validUntil' => 'DESC']
                );

                if ($historicalProof && $historicalProof->isValidForYear($examYear)) {
                    // Es gibt einen gültigen Nachweis, aber er ist nicht aus diesem Jahr
                    return new JsonResponse([
                        'success' => false,
                        'message' => sprintf(
                            'Der Schwimmnachweis stammt aus dem Jahr %s und ist bis %s gültig. Er kann im Prüfungsjahr %s nicht gelöscht werden.',
                            $historicalProof->getExamYear(),
                            $historicalProof->getValidUntil()->format('d.m.Y'),
                            $examYear
                        )
                    ], 400); // 400 Bad Request sorgt für Fehler-Popup im Frontend
                }

                // Wenn gar kein Nachweis da ist, geben wir einfach OK zurück (Idempotenz)
                // oder eine Info "Nichts zu löschen".
                return new JsonResponse([
                    'success' => false, 
                    'message' => 'Kein aktueller Schwimmnachweis für dieses Jahr gefunden.'
                ], 400);
            }

            // =================================================================
            // AB HIER: Normales Löschen (wenn Nachweis aus aktuellem Jahr ist)
            // =================================================================
            
            $via = $proofToDelete->getRequirementMetVia();
            
            // Prüfen: Wurde der Nachweis durch eine Disziplin erzeugt?
            if ($via && str_starts_with($via, 'DISCIPLINE:')) {
                
                // ID parsen "DISCIPLINE:{id}"
                $parts = explode(':', $via);
                $disciplineId = $parts[1] ?? null;
                
                $canDelete = true; // Standardannahme: Löschen erlaubt

                if ($disciplineId) {
                    $discipline = $this->em->getRepository(Discipline::class)->find($disciplineId);
                    
                    if ($discipline) {
                        // Kategorie Name sicher ermitteln
                        $categoryRaw = $discipline->getCategory();
                        $categoryName = '';

                        if (is_object($categoryRaw) && method_exists($categoryRaw, 'getName')) {
                            $categoryName = strtoupper($categoryRaw->getName());
                        } elseif (is_string($categoryRaw)) {
                            $categoryName = strtoupper($categoryRaw);
                        }

                        // Kategorien, bei denen NICHT gelöscht werden darf
                        $blockingCategories = ['AUSDAUER', 'ENDURANCE', 'SCHNELLIGKEIT', 'RAPIDNESS'];

                        if (in_array($categoryName, $blockingCategories)) {
                            $canDelete = false;
                        } else {
                            // Wenn es erlaubt ist (z.B. Kategorie "SCHWIMMEN"), Result nullen
                            foreach ($ep->getResults() as $result) {
                                if ($result->getDiscipline() && $result->getDiscipline()->getId() === $discipline->getId()) {
                                    $result->setValue(0);
                                    $result->setPoints(0);
                                    $result->setData(null);
                                    // $this->em->persist($result); // Flush reicht
                                }
                            }
                        }
                    }
                }

                if (!$canDelete) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Dieser Nachweis resultiert aus einer Leistung in Ausdauer/Schnelligkeit. Bitte löschen Sie die Zeit in der Leistungstabelle.'
                    ], 400);
                }
            }
            
            // Löschen durchführen
            $this->em->remove($proofToDelete);

            // Verknüpfung am ExamParticipant lösen (UI Cleanup)
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
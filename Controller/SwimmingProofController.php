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
    // Konstanten vermeiden Tippfehler
    private const BLOCKING_CATEGORIES = ['AUSDAUER', 'ENDURANCE', 'SCHNELLIGKEIT', 'RAPIDNESS'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SportabzeichenService $service
    ) {
    }

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        try {
            $data = $this->decodeRequest($request);
            $epId = $data['epId'] ?? $data['ep_id'] ?? null;
            $disciplineId = $data['disciplineId'] ?? $data['discipline_id'] ?? null;

            if (!$epId) {
                throw new \InvalidArgumentException('Teilnehmer-ID fehlt.');
            }

            $ep = $this->loadExamParticipant((int)$epId);

            // Disziplin verarbeiten
            if (!empty($disciplineId) && $disciplineId !== '-') {
                $discipline = $this->em->getRepository(Discipline::class)->find((int)$disciplineId);
                if (!$discipline) {
                    throw new \InvalidArgumentException('Disziplin nicht gefunden.');
                }

                $this->service->createSwimmingProofFromDiscipline($ep, $discipline);
                
                // Falls Property existiert (Code-Review Check)
                if (method_exists($ep, 'setSwimmingDiscipline')) {
                    $ep->setSwimmingDiscipline($discipline);
                    // Persist ist nicht nötig, wenn EP schon managed ist, aber schadet hier nicht
                    $this->em->flush();
                }
            }

            return $this->buildSuccessResponse($ep);

        } catch (\Throwable $e) {
            return $this->buildErrorResponse($e);
        }
    }

    #[Route('/exam/swimming/remove-proof', name: 'exam_swimming_remove_proof', methods: ['POST'])]
    public function removeSwimmingProof(Request $request): JsonResponse
    {
        try {
            $data = $this->decodeRequest($request);
            $epId = $data['epId'] ?? $data['ep_id'] ?? null;

            if (!$epId) {
                throw new \InvalidArgumentException('ID fehlt.');
            }

            $ep = $this->loadExamParticipant((int)$epId);
            $proofRepo = $this->em->getRepository(SwimmingProof::class);
            $examYear = $ep->getExam()->getYear();

            // 1. Suche Nachweis für das aktuelle Jahr
            /** @var SwimmingProof|null $proofToDelete */
            $proofToDelete = $proofRepo->findOneBy([
                'participant' => $ep->getParticipant(),
                'examYear' => $examYear
            ]);

            // 2. Fall: Kein aktueller Nachweis -> Prüfe auf blockierende historische Nachweise
            if (!$proofToDelete) {
                $this->checkHistoricalProofBlocking($ep->getParticipant(), $examYear);
                // Wenn checkHistoricalProofBlocking keine Exception wirft, aber auch nichts gefunden wurde:
                throw new \RuntimeException('Es wurde kein aktueller Schwimmnachweis zum Löschen gefunden.');
            }

            // 3. Fall: Aktueller Nachweis gefunden -> Prüfe ob Löschen erlaubt (Kategorie-Check)
            $this->ensureDeletionIsAllowed($proofToDelete, $ep);

            // 4. Löschen durchführen
            $this->em->remove($proofToDelete);

            if (method_exists($ep, 'setSwimmingDiscipline')) {
                $ep->setSwimmingDiscipline(null);
            }

            $this->em->flush();

            return $this->buildSuccessResponse($ep);

        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // Logik-Fehler (400 Bad Request)
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            // System-Fehler (500)
            return $this->buildErrorResponse($e);
        }
    }

    /**
     * Zentralisiertes Laden mit Eager Loading um "Missing Value" Fehler zu vermeiden.
     */
    private function loadExamParticipant(int $id): ExamParticipant
    {
        $ep = $this->em->createQueryBuilder()
            ->select('ep', 'p', 'u')
            ->from(ExamParticipant::class, 'ep')
            ->join('ep.participant', 'p')
            ->join('p.user', 'u')
            ->where('ep.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$ep) {
            throw new \InvalidArgumentException('Teilnehmer nicht gefunden.');
        }

        return $ep;
    }

    /**
     * Prüft, ob ein alter Nachweis das Löschen/Bearbeiten im aktuellen Jahr "blockiert",
     * weil er noch gültig ist.
     */
    private function checkHistoricalProofBlocking($participant, int $currentExamYear): void
    {
        $repo = $this->em->getRepository(SwimmingProof::class);
        
        // Den am längsten gültigen historischen Nachweis holen
        $historicalProof = $repo->findOneBy(
            ['participant' => $participant], 
            ['validUntil' => 'DESC']
        );

        if ($historicalProof) {
            $validUntil = $historicalProof->getValidUntil();
            if ($validUntil && (int)$validUntil->format('Y') >= $currentExamYear) {
                throw new \RuntimeException(sprintf(
                    'Der Schwimmnachweis stammt aus dem Jahr %s und ist noch bis %s gültig. Er wird automatisch übernommen.',
                    $historicalProof->getExamYear(),
                    $validUntil->format('d.m.Y')
                ));
            }
        }
    }

    /**
     * Prüft, ob der Nachweis gelöscht werden darf oder ob er aus einer
     * Disziplin (Ausdauer/Schnelligkeit) resultiert.
     */
    private function ensureDeletionIsAllowed(SwimmingProof $proof, ExamParticipant $ep): void
    {
        $via = $proof->getRequirementMetVia();
        
        // Nur prüfen wenn via "DISCIPLINE:123" ist
        if (!$via || !str_starts_with($via, 'DISCIPLINE:')) {
            return;
        }

        $parts = explode(':', $via);
        $disciplineId = $parts[1] ?? null;

        if (!$disciplineId) {
            return;
        }

        $discipline = $this->em->getRepository(Discipline::class)->find($disciplineId);
        if (!$discipline) {
            return;
        }

        // Kategorie prüfen
        $catRaw = $discipline->getCategory();
        // Hier nutzen wir, wenn möglich, den Getter oder String-Cast, um sicher zu sein
        $catName = is_object($catRaw) && method_exists($catRaw, 'getName') 
            ? $catRaw->getName() 
            : (string)$catRaw;
        
        if (in_array(strtoupper($catName), self::BLOCKING_CATEGORIES, true)) {
            throw new \RuntimeException('Dieser Nachweis resultiert aus einer Leistung in Ausdauer/Schnelligkeit. Bitte löschen Sie die Zeit in der Leistungstabelle.');
        }

        // Wenn wir hier sind, ist es eine "erlaubte" Disziplin (z.B. reines Schwimmen).
        // Ergebnisse nullen:
        foreach ($ep->getResults() as $result) {
            if ($result->getDiscipline()?->getId() === $discipline->getId()) {
                $result->setValue(0);
                $result->setPoints(0);
                $result->setData(null);
            }
        }
    }

    private function buildSuccessResponse(ExamParticipant $ep): JsonResponse
    {
        // Zusammenfassung aktualisieren & Refresh (falls nötig für Berechnungen)
        $summary = $this->service->syncSummary($ep);
        // Refresh ist meistens unnötig, wenn der Service sauber arbeitet, 
        // aber bei komplexen DB-Triggern manchmal erforderlich.
        // $this->em->refresh($ep); 

        return new JsonResponse([
            'status' => 'ok',
            'success' => true,
            'epId' => $ep->getId(),
            'has_swimming' => $summary['has_swimming'] ?? false,
            'swimming_met_via' => $summary['met_via'] ?? null,
            'total_points' => $summary['total'] ?? 0,
            'final_medal' => $summary['medal'] ?? 'none'
        ]);
    }

    private function buildErrorResponse(\Throwable $e): JsonResponse
    {
        // Niemals File/Line in Production ausgeben!
        return new JsonResponse([
            'status' => 'error',
            'success' => false,
            'message' => 'Fehler: ' . $e->getMessage()
        ], 500);
    }
    
    private function decodeRequest(Request $request): array
    {
        $content = $request->getContent();
        return !empty($content) ? json_decode($content, true, 512, JSON_THROW_ON_ERROR) : [];
    }
}
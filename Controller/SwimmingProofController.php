#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_swimming_')]
final class SwimmingProofController extends AbstractPageController
{
    #[Route('/toggle', name: 'toggle', methods: ['POST'])]
    public function toggle(Request $request, SportabzeichenService $service): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ep = $this->em->getRepository(ExamParticipant::class)->find($data['ep_id']);
        
        if (!$ep) return new JsonResponse(['error' => 'Not found'], 404);

        $service->handleSwimmingProof($ep, (bool)$data['swimming']);
        
        // Nutzt eine standardisierte Antwort
        return new JsonResponse(['status' => 'ok']); 
    }
}
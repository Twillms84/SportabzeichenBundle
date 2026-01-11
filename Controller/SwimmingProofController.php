<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

// IMPORTS VORERST WEGLASSEN UM FEHLER ZU VERMEIDEN

#[Route('/sportabzeichen/swimming', name: 'sportabzeichen_swimming_')]
#[IsGranted('PRIV_SPORTABZEICHEN_RESULTS')]
final class SwimmingProofController extends AbstractPageController
{
    // Konstruktor erstmal leer lassen, um Service-Fehler auszuschließen
    /*
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SportabzeichenService $service
    ) {}
    */

    #[Route('/exam/swimming/add-proof', name: 'exam_swimming_add_proof', methods: ['POST'])]
    public function addSwimmingProof(Request $request): JsonResponse
    {
        return new JsonResponse(['status' => 'test_ok', 'message' => 'Controller läuft wieder']);
    }
}
<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/results', name: 'sportabzeichen_results_')]
final class ResultsController extends AbstractPageController
{
    /**
     * Übersicht Prüfungen
     */
    #[Route('/exams', name: 'exams')]
    public function exams(): Response
    {
        $this->denyAccessUnlessGranted('sportabzeichen_results');

        return $this->render('@PulsRSportabzeichen/results/exams.html.twig', [
            'activeTab' => 'exams',
        ]);
    }

    /**
     * Teilnehmer einer Prüfung
     */
    #[Route('/participants', name: 'participants')]
    public function participants(): Response
    {
        $this->denyAccessUnlessGranted('sportabzeichen_results');

        return $this->render('@PulsRSportabzeichen/results/participants.html.twig', [
            'activeTab' => 'participants',
        ]);
    }

    /**
     * Ergebnisse erfassen / bearbeiten
     */
    #[Route('/edit', name: 'edit')]
    public function edit(): Response
    {
        $this->denyAccessUnlessGranted('sportabzeichen_results');

        return $this->render('@PulsRSportabzeichen/results/edit.html.twig', [
            'activeTab' => 'results',
        ]);
    }
}

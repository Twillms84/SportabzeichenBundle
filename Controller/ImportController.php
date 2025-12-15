<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ImportController extends AbstractPageController
{
    #[Route('/sportabzeichen/import', name: 'sportabzeichen_import')]
    public function index(): Response
    {
        // Zugriffsbeschränkung: Nur Nutzer mit Verwaltungsrecht
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE');

        return $this->render('@PulsRSportabzeichen/import/index.html.twig', [
            'title' => 'Sportabzeichen Datenimport',
        ]);
    }
}

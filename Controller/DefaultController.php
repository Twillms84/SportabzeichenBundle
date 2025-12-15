<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DefaultController extends AbstractPageController
{
    #[Route('/sportabzeichen', name: 'sportabzeichen_index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_VIEW');

        return $this->render('@PulsRSportabzeichen/default/index.html.twig', [
            'title' => _('Sportabzeichen Übersicht'),
        ]);
    }

    #[Route('/sportabzeichen/manage', name: 'sportabzeichen_manage')]
    public function manage(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_MANAGE');

        // Hier können wir später dynamische Inhalte einfügen (z. B. CSV-Status)
        return $this->render('@PulsRSportabzeichen/manage/index.html.twig', [
            'title' => _('Sportabzeichen Verwaltung'),
            'tabs' => [
                [
                    'label' => _('Datenverwaltung'),
                    'route' => 'sportabzeichen_manage',
                    'active' => true,
                ],
                [
                    'label' => _('Anforderungsdatei hochladen'),
                    'route' => 'sportabzeichen_admin_upload',
                    'active' => false,
                ],
            ],
        ]);
    }
}

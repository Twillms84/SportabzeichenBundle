<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\Controller;

use IServ\CoreBundle\Controller\AbstractPageController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/sportabzeichen/admin', name: 'sportabzeichen_admin_')]
final class AdminController extends AbstractPageController
{
    /**
     * Dashboard / Einstieg Verwaltung
     */
    #[Route('/', name: 'dashboard')]
    public function dashboard(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/dashboard.html.twig', [
            'activeTab' => 'requirements_upload',
        ]);
    }

    /**
     * Anforderungen (CSV) hochladen
     */
    #[Route('/upload-requirements', name: 'upload')]
    public function uploadRequirements(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload.html.twig', [
            'activeTab' => 'requirements_upload',
        ]);
    }

    /**
     * Teilnehmer (CSV) hochladen
     */
    #[Route('/upload-participants', name: 'upload_participants')]
    public function uploadParticipants(): Response
    {
        $this->denyAccessUnlessGranted('PRIV_SPORTABZEICHEN_ADMIN');

        return $this->render('@PulsRSportabzeichen/admin/upload_participants.html.twig', [
            'activeTab' => 'participants_upload',
        ]);
    }
}

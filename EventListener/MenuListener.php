<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\EventListener;

use IServ\CoreBundle\Event\MenuEvent;
use IServ\CoreBundle\EventListener\MainMenuListenerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuListener implements MainMenuListenerInterface
{
    private AuthorizationCheckerInterface $auth;

    public function __construct(AuthorizationCheckerInterface $auth)
    {
        $this->auth = $auth;
    }

    public function onBuildMainMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        // Wird angezeigt, sobald der Benutzer irgendein Recht besitzt
        if (
            $this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS') ||
            $this->auth->isGranted('PRIV_SPORTABZEICHEN_MANAGE_PARTICIPANTS') ||
            $this->auth->isGranted('PRIV_SPORTABZEICHEN_REQUIREMENTS')
        ) {
            $root = $menu->addChild('sportabzeichen', [
                'route' => 'sportabzeichen_results_exams',
                'label' => _('Sportabzeichen'),
                'extras' => [
                    'icon' => 'medal',
                    'icon_style' => 'fas',
                ],
            ]);
        } else {
            return;
        }

        /* ----------------------------------------------------------
         * Ergebnisse eintragen
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $root->addChild('sportabzeichen_results', [
                'route' => 'sportabzeichen_results_exams',
                'label' => _('Ergebnisse eintragen'),
                'extras' => [
                    'icon' => 'table',
                    'icon_style' => 'fas',
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * Teilnehmer verwalten
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_MANAGE_PARTICIPANTS')) {
            $root->addChild('sportabzeichen_participants', [
                'route' => 'sportabzeichen_exam_index',
                'label' => _('Teilnehmer verwalten'),
                'extras' => [
                    'icon' => 'users',
                    'icon_style' => 'fas',
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * Verwaltung – nur für Admin/Koordinator
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_REQUIREMENTS')) {

            $verwaltung = $root->addChild('sportabzeichen_admin', [
                'route' => 'sportabzeichen_admin_upload',
                'label' => _('Verwaltung'),
                'extras' => [
                    'icon' => 'cog',
                    'icon_style' => 'fas',
                ],
            ]);

            // Unterpunkt: Anforderungen anzeigen
            $verwaltung->addChild('sportabzeichen_requirements_view', [
                'route' => 'sportabzeichen_manage_view',
                'label' => _('Anforderungen anzeigen'),
                'extras' => [
                    'icon' => 'eye',
                    'icon_style' => 'fas',
                ],
            ]);

            // Unterpunkt: CRUD Anforderungen bearbeiten
            $verwaltung->addChild('sportabzeichen_requirements_edit', [
                'route' => 'iserv_crud_sportabzeichenrequirement_index',
                'label' => _('Anforderungen bearbeiten'),
                'extras' => [
                    'icon' => 'edit',
                    'icon_style' => 'fas',
                ],
            ]);

            // Unterpunkt: CSV Upload
            $verwaltung->addChild('sportabzeichen_requirements_upload', [
                'route' => 'sportabzeichen_admin_upload',
                'label' => _('CSV-Upload Anforderungen'),
                'extras' => [
                    'icon' => 'file-upload',
                    'icon_style' => 'fas',
                ],
            ]);
        }
    }
}

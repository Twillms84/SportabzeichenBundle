<?php

declare(strict_types=1);

namespace PulsR\SportabzeichenBundle\EventListener;

use IServ\CoreBundle\Event\MenuEvent;
use IServ\CoreBundle\EventListener\MainMenuListenerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuListener implements MainMenuListenerInterface
{
    public function __construct(
        private AuthorizationCheckerInterface $auth
    ) {}

    public function onBuildMainMenu(MenuEvent $event): void
    {
        $menu = $event->getMenu();

        /* ----------------------------------------------------------
         * SCHÜLER / TEILNEHMER ANSICHT (NEU)
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_VIEW_OWN')) {
            $menu->addChild('sportabzeichen_my_results', [
                'route' => 'puls_r_sportabzeichen_my_results', // Die Route müssen wir gleich noch erstellen
                'label' => _('Mein Sportabzeichen'),
                'extras' => [
                    'icon' => 'user-graduate', // Passendes Icon für Schüler/Absolvent
                    'icon_style' => 'fas',
                    'weight' => 10, // Damit es im Menü oben steht (optional)
                ],
            ]);
        }
        
        /* ----------------------------------------------------------
         * Ergebnisse eintragen
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $menu->addChild('sportabzeichen_exams', [
                'route' => 'sportabzeichen_exams_dashboard',
                'label' => _('SPA-Ergebnisse'),
                'extras' => [
                    'icon' => 'table',
                    'icon_style' => 'fas',
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * Verwaltung / Administration
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_ADMIN')) {
            $menu->addChild('sportabzeichen_admin', [
                'route' => 'sportabzeichen_admin_dashboard',
                'label' => _('SPA–Verwaltung'),
                'extras' => [
                    'icon' => 'cog',
                    'icon_style' => 'fas',
                ],
            ]);
        }
    }
}

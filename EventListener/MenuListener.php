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
         * SCHÜLER: Mein Sportabzeichen
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_VIEW_OWN')) {
            $menu->addChild('sportabzeichen_my_results', [
                'route' => 'pulsr_sportabzeichen_my_results',
                'label' => _('Mein Sportabzeichen'),
                'extras' => [
                    'icon' => 'medal', // Sieht in "Light" sehr edel aus
                    'icon_style' => 'fa', // WICHTIG: 'fa' statt 'fas' (Classic Light)
                    'weight' => 10,
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * LEHRER: Ergebnisse erfassen
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $menu->addChild('sportabzeichen_exams', [
                'route' => 'sportabzeichen_exams_dashboard',
                'label' => _('SPA-Ergebnisse'),
                'extras' => [
                    'icon' => 'stopwatch', // Passt perfekt zum Sportplatz
                    'icon_style' => 'fa',
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * ADMIN: Verwaltung
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_ADMIN')) {
            $menu->addChild('sportabzeichen_admin', [
                'route' => 'sportabzeichen_admin_dashboard',
                'label' => _('SPA–Verwaltung'),
                'extras' => [
                    'icon' => 'gears', // 'gears' ist in Light immer sicher
                    'icon_style' => 'fa',
                ],
            ]);
        }
    }
}
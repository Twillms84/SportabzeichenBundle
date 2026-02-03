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
         * SCHÜLER: Mein Sportabzeichen (GOLDIG)
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_VIEW_OWN')) {
            $menu->addChild('sportabzeichen_my_results', [
                'route' => 'pulsr_sportabzeichen_my_results',
                'label' => _('Mein Sportabzeichen'),
                'extras' => [
                    'icon' => 'medal',
                    // 'text-warning' macht es gelb/gold
                    'icon_style' => 'fa text-warning', 
                    'weight' => 10,
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * LEHRER: Ergebnisse (IServ-Blau)
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $menu->addChild('sportabzeichen_exams', [
                'route' => 'sportabzeichen_exams_dashboard',
                'label' => _('SPA-Ergebnisse'),
                'extras' => [
                    'icon' => 'stopwatch',
                    // 'text-primary' ist das IServ-Blau (Dunkelblau)
                    'icon_style' => 'fa text-primary', 
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * ADMIN: Verwaltung (Neutral / Grau)
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_ADMIN')) {
            $menu->addChild('sportabzeichen_admin', [
                'route' => 'sportabzeichen_admin_dashboard',
                'label' => _('SPA–Verwaltung'),
                'extras' => [
                    'icon' => 'gears',
                    // Grau lassen oder 'text-muted' für Dezenz
                    'icon_style' => 'fa', 
                ],
            ]);
        }
    }
}
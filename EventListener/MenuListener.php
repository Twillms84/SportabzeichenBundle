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
                'route' => 'pulsr_sportabzeichen_my_results',
                'label' => _('Mein Sportabzeichen'),
                'extras' => [
                    // 'medal' ist perfekt für das Ziel (Gold/Silber/Bronze)
                    'icon' => 'medal', 
                    'icon_style' => 'fas',
                    'weight' => 10,
                ],
            ]);
        }

        /* ----------------------------------------------------------
         * Ergebnisse eintragen (LEHRER)
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $menu->addChild('sportabzeichen_exams', [
                'route' => 'sportabzeichen_exams_dashboard',
                'label' => _('SPA-Ergebnisse'),
                'extras' => [
                    // 'stopwatch' wirkt sportlicher als eine Tabelle
                    // Alternativ: 'clipboard-list' (für das Klemmbrett-Feeling)
                    'icon' => 'stopwatch', 
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
                    // 'cogs' (Mehrere Zahnräder) funktioniert immer in IServ
                    // Alternativ: 'wrench' (Schraubenschlüssel)
                    'icon' => 'cogs', 
                    'icon_style' => 'fas',
                ],
            ]);
        }
    }
}

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
         * Ergebnisse eintragen
         * ---------------------------------------------------------- */
        if ($this->auth->isGranted('PRIV_SPORTABZEICHEN_RESULTS')) {
            $menu->addChild('sportabzeichen_results', [
                'route' => 'sportabzeichen_results_exams',
                'label' => _('Sportabzeichen – Ergebnisse'),
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
                'label' => _('Sportabzeichen – Verwaltung'),
                'extras' => [
                    'icon' => 'cog',
                    'icon_style' => 'fas',
                ],
            ]);
        }
    }
}

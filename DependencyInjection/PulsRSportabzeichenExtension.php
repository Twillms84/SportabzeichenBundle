<?php
namespace PulsR\SportabzeichenBundle\DependencyInjection;

use IServ\CoreBundle\DependencyInjection\IServBaseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;

final class PulsRSportabzeichenExtension extends IServBaseExtension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        // Hier wird die Konfiguration VOR der load-Phase injiziert
        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    'pulsr-sportabzeichen' => [
                        'json_manifest_path' => '/usr/share/iserv/web/public/assets/pulsr-sportabzeichen/manifest.json',
                    ],
                ],
            ],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        // Standardmäßig Services laden (services.yaml etc.)
        parent::load($configs, $container);
    }

    public function getAlias(): string
    {
        return 'pulsr_sportabzeichen';
    }
}
<?php
namespace PulsR\SportabzeichenBundle\DependencyInjection;

use IServ\CoreBundle\DependencyInjection\IServBaseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PulsRSportabzeichenExtension extends IServBaseExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        parent::load($configs, $container);

        // IServ-kompatible Asset-Registrierung
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

    public function getAlias(): string
    {
        return 'pulsr_sportabzeichen';
    }
}

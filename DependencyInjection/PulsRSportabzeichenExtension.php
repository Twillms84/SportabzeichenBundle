<?php
namespace PulsR\SportabzeichenBundle\DependencyInjection;

use IServ\CoreBundle\DependencyInjection\IServBaseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PulsRSportabzeichenExtension extends IServBaseExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        parent::load($configs, $container);

        // 🔧 IServ-kompatible Asset-Registrierung
        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    // Der Paketname MUSS der gleiche wie im public/assets/-Ordner sein!
                    'pulsr-sportabzeichen' => [
                        'json_manifest_path' => '/usr/share/iserv/web/public/assets/pulsr-sportabzeichen/manifest.json',
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
        // ⚙️ Muss exakt mit dem Bundle-Namen konform sein
        // "pulsr_sportabzeichen" wird von IServ automatisch erkannt
        return 'pulsr_sportabzeichen';
    }
}

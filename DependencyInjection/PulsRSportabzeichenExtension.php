<?php
namespace PulsR\SportabzeichenBundle\DependencyInjection;

use IServ\CoreBundle\DependencyInjection\IServBaseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PulsRSportabzeichenExtension extends IServBaseExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        parent::load($configs, $container);

<<<<<<< HEAD
        // 🔧 IServ-kompatible Asset-Registrierung
        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    // Der Paketname MUSS der gleiche wie im public/assets/-Ordner sein!
=======
        // IServ-kompatible Asset-Registrierung
        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
>>>>>>> d12843a1ae43dcff1589a029cc14534b8d9b3854
                    'pulsr-sportabzeichen' => [
                        'json_manifest_path' => '/usr/share/iserv/web/public/assets/pulsr-sportabzeichen/manifest.json',
                    ],
                ],
            ],
        ]);
    }

    public function getAlias(): string
    {
<<<<<<< HEAD
        // ⚙️ Muss exakt mit dem Bundle-Namen konform sein
        // "pulsr_sportabzeichen" wird von IServ automatisch erkannt
=======
>>>>>>> d12843a1ae43dcff1589a029cc14534b8d9b3854
        return 'pulsr_sportabzeichen';
    }
}

<?php
namespace PulsR\SportabzeichenBundle\DependencyInjection;

use IServ\CoreBundle\DependencyInjection\IServBaseExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class PulsRSportabzeichenExtension extends IServBaseExtension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        parent::load($configs, $container);
    }
}


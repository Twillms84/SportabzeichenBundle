<?php
namespace PulsR\SportabzeichenBundle;

use PulsR\SportabzeichenBundle\DependencyInjection\PulsRSportabzeichenExtension;
use IServ\CoreBundle\Routing\AutoloadRoutingBundleInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class PulsRSportabzeichenBundle extends Bundle implements AutoloadRoutingBundleInterface
{
    public function getContainerExtension()
    {
        if (null === $this->extension) {
            $this->extension = new PulsRSportabzeichenExtension();
        }

        return $this->extension;
    }
}

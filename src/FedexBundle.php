<?php

namespace SonnyDev\FedexBundle;

use SonnyDev\FedexBundle\DependencyInjection\FedexExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class FedexBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FedexExtension();
    }

}
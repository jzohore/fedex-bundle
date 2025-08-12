<?php
declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use SonnyDev\FedexBundle\Service\FedexTrackingService;
use SonnyDev\FedexBundle\Service\FedexAuthenticator;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('SonnyDev\\FedexBundle\\', __DIR__ . '/../../')
        ->exclude([__DIR__ . '/../../DependencyInjection/', __DIR__ . '/../../Entity/', __DIR__ . '/../../Tests/']);
     $services->load('SonnyDev\\FedexBundle\\Command\\', __DIR__.'/../../Command/')
         ->tag('console.command');
    // Paramètres spécifiques avec injection depuis .env
    $services->get(FedexTrackingService::class)
        ->arg('$fedexApiTracking', '%env(FEDEX_API_TRACKING)%');

    $services->get(FedexAuthenticator::class)
        ->arg('$fedexClientId', '%env(FEDEX_CLIENT_ID)%')
        ->arg('$fedexClientSecret', '%env(FEDEX_CLIENT_SECRET)%')
        ->arg('$fedexClientShipId', '%env(FEDEX_CLIENT_SHIP_ID)%')
        ->arg('$fedexClientShipSecret', '%env(FEDEX_CLIENT_SHIP_SECRET)%')
        ->arg('$fedexOauthToken', '%env(FEDEX_CLIENT_AUTH_LINK)%')
        ->arg('$fedexApiTracking', '%env(FEDEX_API_TRACKING)%');


};

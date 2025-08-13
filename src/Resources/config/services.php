<?php

declare(strict_types=1);

use SonnyDev\FedexBundle\Service\FedexAddressValidationService;
use SonnyDev\FedexBundle\Service\FedexAuthenticator;
use SonnyDev\FedexBundle\Service\FedexLocationService;
use SonnyDev\FedexBundle\Service\FedexRatesService;
use SonnyDev\FedexBundle\Service\FedexTrackingService;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
    ;

    $services->load('SonnyDev\FedexBundle\\', __DIR__ . '/../../')
        ->exclude([
            __DIR__ . '/../../DependencyInjection/',
            __DIR__ . '/../../Resources/',
            __DIR__ . '/../../Entity/',
            __DIR__ . '/../../Tests/',
        ])
    ;

    $services->load('SonnyDev\FedexBundle\Command\\', __DIR__ . '/../../Command/')
        ->tag('console.command')
    ;

    $services->get(FedexTrackingService::class)
        ->arg('$fedexApiTracking', '%env(FEDEX_API_TRACKING)%')
    ;
    $services->get(FedexRatesService::class)
        ->arg('$ratesEndpoint', '%env(FEDEX_API_RATES)%')
        ->arg('$accountNumber', '%env(FEDEX_ACCOUNT_NUMBER)%')
    ;

    $services->get(FedexLocationService::class)
        ->arg('$locationsEndpoint', '%env(FEDEX_LOCATIONS_URL)%')
    ;

    $services->get(FedexAddressValidationService::class)
        ->arg('$endpoint', '%env(FEDEX_ADDRESS_URL)%')
    ;

    $services->get(FedexAuthenticator::class)
        ->arg('$fedexClientId', '%env(FEDEX_CLIENT_ID)%')
        ->arg('$fedexClientSecret', '%env(FEDEX_CLIENT_SECRET)%')
        ->arg('$fedexClientShipId', '%env(FEDEX_CLIENT_SHIP_ID)%')
        ->arg('$fedexClientShipSecret', '%env(FEDEX_CLIENT_SHIP_SECRET)%')
        ->arg('$fedexOauthToken', '%env(FEDEX_CLIENT_AUTH_LINK)%');
};

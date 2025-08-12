<?php

declare(strict_types=1);

namespace SonnyDev\FedexBundle\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SonnyDev\FedexBundle\DTO\FedexTrackingEvent;
use SonnyDev\FedexBundle\Service\FedexAuthenticator;
use SonnyDev\FedexBundle\Service\FedexTrackingService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @coversNothing
 */
final class FedexTrackingServiceTest extends TestCase
{
    public function testTrackShipmentMapsValidEvents(): void
    {
        // 1) Mock authenticator -> token
        $auth = $this->createMock(FedexAuthenticator::class);
        $auth->method('getAccessToken')->willReturn('fake-token');

        // 2) Payload FedEx simulé (structure réelle simplifiée)
        $fedexPayload = [
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'scanEvents' => [
                                    [
                                        'eventType' => 'PU',
                                        'eventDescription' => 'Shipment picked up',
                                        'date' => '2025-08-10T09:15:00Z',
                                        'scanLocation' => [
                                            'city' => 'Paris',
                                            'countryName' => 'France',
                                        ],
                                    ],
                                    [
                                        'eventType' => 'AR',
                                        'eventDescription' => 'Arrived at FedEx location',
                                        'date' => '2025-08-11T13:45:00Z',
                                        'scanLocation' => [
                                            'city' => 'Roissy',
                                            'countryName' => 'France',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // 3) Mock HTTP -> renvoie le JSON ci-dessus
        $response = new MockResponse(json_encode($fedexPayload), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);

        $httpClient = new MockHttpClient($response);

        // 4) Service sous test
        $service = new FedexTrackingService(
            httpClient: $httpClient,
            authenticator: $auth,
            fedexApiTracking: 'https://apis-sandbox.fedex.com/track/v1/trackingnumbers',
        );

        // 5) Appel
        $events = $service->trackShipment('123456789012', includeDetailedScans: true, locale: 'fr_FR');

        $this->assertIsArray($events);
        $this->assertCount(2, $events);

        // On reçoit bien des DTO
        $this->assertContainsOnlyInstancesOf(FedexTrackingEvent::class, $events);

        // Le plus récent d’abord (AR du 11/08 avant PU du 10/08)
        $first = $events[0];
        $this->assertSame('AR', $first->type);
        $this->assertSame('Arrived at FedEx location', $first->description);
        $this->assertInstanceOf(DateTimeImmutable::class, $first->date);
        $this->assertSame('Roissy', $first->city);
        $this->assertSame('France', $first->country);

        $second = $events[1];
        $this->assertSame('PU', $second->type);
        $this->assertSame('Shipment picked up', $second->description);
        $this->assertInstanceOf(DateTimeImmutable::class, $second->date);
        $this->assertSame('Paris', $second->city);
        $this->assertSame('France', $second->country);
    }

    public function testTrackShipmentHandlesEmptyResults(): void
    {
        $auth = $this->createMock(FedexAuthenticator::class);
        $auth->method('getAccessToken')->willReturn('fake-token');

        $fedexPayload = ['output' => ['completeTrackResults' => []]];
        $response = new MockResponse(json_encode($fedexPayload), [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
        $httpClient = new MockHttpClient($response);

        $service = new FedexTrackingService(
            httpClient: $httpClient,
            authenticator: $auth,
            fedexApiTracking: 'https://apis-sandbox.fedex.com/track/v1/trackingnumbers',
        );

        $events = $service->trackShipment('123456789012');

        $this->assertIsArray($events);
        $this->assertCount(0, $events);
    }
}

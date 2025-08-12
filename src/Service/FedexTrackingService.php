<?php

namespace SonnyDev\FedexBundle\Service;

use SonnyDev\FedexBundle\DTO\FedexTrackingEvent;
use SonnyDev\FedexBundle\Exception\FedexApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

class FedexTrackingService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,
        private readonly string $fedexApiTracking, // injecté via param/env
    ) {
    }

    /**
     * @return FedexTrackingEvent[]
     */
    public function trackShipment(
        string $trackingNumber,
        bool $includeDetailedScans = true,
        string $locale = 'fr_FR',
    ): array {
        try {
            $response = $this->httpClient->request('POST', $this->fedexApiTracking, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->authenticator->getAccessToken(),
                    'X-locale' => $locale,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'includeDetailedScans' => $includeDetailedScans,
                    'trackingInfo' => [[
                        'trackingNumberInfo' => ['trackingNumber' => $trackingNumber],
                    ]],
                ],
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new FedexApiException(message: 'FedEx Tracking HTTP ' . $response->getStatusCode(), statusCode: $response->getStatusCode(), responseData: $response->toArray(false));
            }

            $data = $response->toArray(false);

            $rawEvents = [];
            foreach (($data['output']['completeTrackResults'] ?? []) as $complete) {
                foreach (($complete['trackResults'] ?? []) as $result) {
                    foreach (($result['scanEvents'] ?? []) as $event) {
                        $rawEvents[] = $event;
                    }
                }
            }

            // map -> DTO
            $events = array_map(static fn (array $e) => FedexTrackingEvent::fromApi($e), $rawEvents);

            // tri par date desc
            usort($events, static function (FedexTrackingEvent $a, FedexTrackingEvent $b): int {
                return $b->date <=> $a->date;
            });

            return $events;
        } catch (Throwable $e) {
            throw new FedexApiException(message: 'FedEx Tracking error: ' . $e->getMessage(), previous: $e);
        }
    }

    public function getLatestEvent(string $trackingNumber, string $locale = 'fr_FR'): ?FedexTrackingEvent
    {
        $events = $this->trackShipment($trackingNumber, includeDetailedScans: true, locale: $locale);

        return $events[0] ?? null;
    }
}

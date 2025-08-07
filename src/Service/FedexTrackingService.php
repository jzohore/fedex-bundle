<?php

namespace SonnyDev\FedexBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FedexTrackingService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,
        #[Autowire('%env(FEDEX_API_TRACKING)%')] private readonly string $fedexApiTracking,
    )
    {}
    public function trackingInFedex($trackingNumber)
    {
        $response = $this->httpClient->request(
            'POST',
            $this->fedexApiTracking, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->authenticator->getAccessToken(),
                    'X-locale' => 'fr_FR',
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'includeDetailedScans' => true,
                    'trackingInfo' => [
                        [
                            'trackingNumberInfo' => [
                                'trackingNumber' => $trackingNumber,
                            ],
                        ],
                    ],
                ],
            ]
        );

        $responseData = $response->toArray();

        if ($responseData) {
            $scanEvents = [];
            foreach ($responseData['output']['completeTrackResults'] as $completeTrackResult) {
                foreach ($completeTrackResult['trackResults'] as $trackResult) {
                    $scanEvents[] = $trackResult['scanEvents'];
                }
            }

            return $scanEvents;
        } else {
            return null;
        }
    }
}
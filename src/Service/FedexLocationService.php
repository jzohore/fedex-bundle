<?php

namespace SonnyDev\FedexBundle\Service;

use Psr\Cache\InvalidArgumentException;
use SonnyDev\FedexBundle\DTO\FedexLocation;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FedexLocationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,
        private readonly string $locationsEndpoint,
    ) {
    }

    /**
     * Recherche de points FedEx à partir d'une adresse.
     *
     * @param bool|null $sameState Filtre “même état”. null = ne pas envoyer le champ
     * @param bool|null $sameCountry Filtre “même pays”. null = ne pas envoyer le champ
     * @param string $locationType Ex: 'FEDEX_SHIP_AND_GET', 'ALL', ...
     * @param string $locale 'en_US', 'fr_FR'...
     *
     * @return array<int,array<string,mixed>> Liste simplifiée des points (id, nom, type, distance, adresse, horaires)
     *
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function searchLocationInFedex(
        string|array $streetLines,
        string $cityRecipient,
        string $postalCode,
        string $codeCountryRecipient,
        bool $sameState = true,
        bool $sameCountry = false,
        string $locationType = 'FEDEX_SHIP_AND_GET',
        string $locale = 'fr_FR',
        int $limit = 5
    ): array {
        // 1) normaliser streetLines
        if (!is_array($streetLines)) {
            $streetLines = [$streetLines];
        }
        $streetLines = array_values(array_filter($streetLines, static fn($l) => $l !== null && $l !== ''));

        // 2) payload sans champs vides
        $addr = [
            'streetLines' => $streetLines,
            'city'        => $cityRecipient ?: null,
            'stateOrProvinceCode' => null, // ← on n'envoie pas de chaîne vide
            'postalCode'  => $postalCode ?: null,
            'countryCode' => strtoupper($codeCountryRecipient),
            'residential' => false,
        ];
        $addr = array_filter($addr, static fn($v) => $v !== null);

        $requestData = [
            'locationSearchCriterion' => 'ADDRESS',
            'location' => [
                'address' => $addr,
                'phoneNumber' => '9015551234',
            ],
            'sameState'    => $sameState,
            'sameCountry'  => $sameCountry,
            'locationType' => $locationType,
        ];

        $response = $this->httpClient->request('POST', $this->locationsEndpoint, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->authenticator->getAccessToken('ship'),
                'X-locale'      => $locale,
                'Content-Type'  => 'application/json',
            ],
            'json' => $requestData,
        ]);

        if (200 !== $response->getStatusCode()) {
            return [
                'error'  => true,
                'status' => $response->getStatusCode(),
                'body'   => $response->getContent(false),
            ];
        }

        $responseData = $response->toArray(false);

        // 3) tolérer plusieurs clés de liste
        $locations = $responseData['output']['locationDetailList']
            ?? $responseData['output']['locationDetails']
            ?? [];

        // 4) tri par distance si dispo
        usort($locations, static function (array $a, array $b): int {
            $av = $a['distanceWithUnit']['value'] ?? PHP_FLOAT_MAX;
            $bv = $b['distanceWithUnit']['value'] ?? PHP_FLOAT_MAX;
            return $av <=> $bv;
        });

        // 5) mapping Twig-friendly + limit
        $mapped = [];
        foreach ($locations as $loc) {
            if ($limit > 0 && count($mapped) >= $limit) {
                break;
            }
            if (!isset($loc['contactAndAddress']['contact']['companyName'])) {
                continue;
            }

            $addr = $loc['contactAndAddress']['address'] ?? [];
            $mapped[] = [
                'locationId' => $loc['locationId'] ?? null,
                'contactAndAddress' => [
                    'contact' => [
                        'companyName' => $loc['contactAndAddress']['contact']['companyName'] ?? '',
                    ],
                    'address' => [
                        'streetLines' => $addr['streetLines'] ?? [],
                        'city'        => $addr['city'] ?? '',
                        'postalCode'  => $addr['postalCode'] ?? '',
                        'countryCode' => $addr['countryCode'] ?? '',
                    ],
                ],
                'distance'     => $loc['distanceWithUnit']['value'] ?? null,
                'distanceUnit' => $loc['distanceWithUnit']['unit'] ?? null,
            ];
        }

        return $mapped;
    }

    public function searchLocationInFedexDto(
        string $streetLines,
        string $cityRecipient,
        string $postalCode,
        string $countryCodeRecipient
    ): array {
        $raw = $this->searchLocationInFedex($streetLines, $cityRecipient, $postalCode, $countryCodeRecipient);

        // 6) gérer le cas erreur renvoyé par la méthode d’au-dessus
        if (isset($raw['error']) && $raw['error'] === true) {
            // à toi de voir : soit throw, soit retourner []
            return [];
        }

        $locations = [];
        foreach ($raw as $loc) {
            $addr = $loc['contactAndAddress']['address'] ?? [];
            $contact = $loc['contactAndAddress']['contact'] ?? [];

            $locations[] = new FedexLocation(
                id: (string)($loc['locationId'] ?? ''),
                name: (string)($contact['companyName'] ?? ''),
                street: (string)($addr['streetLines'][0] ?? ''),
                city: (string)($addr['city'] ?? ''),
                postalCode: (string)($addr['postalCode'] ?? ''),
                countryCode: (string)($addr['countryCode'] ?? '')
            );
        }

        return $locations;
    }

}

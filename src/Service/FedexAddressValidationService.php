<?php

namespace SonnyDev\FedexBundle\Service;

use SonnyDev\FedexBundle\DTO\AddressInput;
use SonnyDev\FedexBundle\DTO\AddressValidationResult;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class FedexAddressValidationService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,             // ->getAccessToken('ship')
        private readonly string $endpoint                   // ex: https://apis.fedex.com/address/v1/addresses/resolve
    ) {}

    /**
     * @param AddressInput[] $inputs max 100
     * @param string $locale
     * @return AddressValidationResult[]
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function validate(array $inputs, string $locale = 'fr_FR'): array
    {
        if (count($inputs) === 0) {
            return [];
        }

        $token = $this->authenticator->getAccessToken('ship');

        // Build request (batch)
        $addresses = [];
        foreach ($inputs as $idx => $in) {
            $streetLines = $in->streetLines;
            if (is_string($streetLines)) { $streetLines = [$streetLines]; }

            $addresses[] = [
                'address' => [
                    'streetLines'         => array_values(array_filter($streetLines)),
                    'city'                => $in->city,
                    'stateOrProvinceCode' => $in->state,
                    'postalCode'          => $in->postalCode,
                    'countryCode'         => $in->countryCode,
                    'residential'         => $in->residential,
                ],
            ];
        }

        $payload = [
            'addressesToValidate' => $this->removeNulls($addresses),
        ];

        $resp = $this->httpClient->request('POST', $this->endpoint, [
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type'  => 'application/json',
                'X-locale'      => $locale,
            ],
            'json' => $payload,
        ]);

        if (200 !== $resp->getStatusCode()) {
            $body = $resp->getContent(false);
            throw new \RuntimeException('Address Validation HTTP '.$resp->getStatusCode().' — '.$body);
        }

        $data = $resp->toArray(false);
        $results = [];

        // Selon l’API FedEx, la structure peut être output.addresses/validatedAddresses/etc.
        $list = $data['output']['resolvedAddresses']
            ?? $data['output']['validatedAddresses']
            ?? $data['addresses']
            ?? [];

        foreach ($list as $i => $item) {
            // Champs typiques (noms vus dans la doc) – on sécurise avec coalesce
            // Flags
            $resolved = false;
            if (isset($item['attributes']['Matched'])) {
                $resolved = ($item['attributes']['Matched'] === 'true');
            }
            $resolved = $resolved
                || !empty($item['resolved'])
                || !empty($item['isResolved'])
                || (($item['attributes']['AddressType'] ?? '') === 'STANDARDIZED');

            $dpvValid = $item['normalizedStatusNameDPV']
                ?? ($item['deliveryPointValidation']['valid'] ?? ($item['dpv']['isDPV'] ?? null));
            if (is_string($dpvValid)) {
                $dpvValid = $dpvValid === 'true';
            } elseif (!is_bool($dpvValid)) {
                $dpvValid = null; // 👈 tri-état
            }


            $interpolated = null;
            if (isset($item['attributes']['InterpolatedStreetAddress'])) {
                $interpolated = ($item['attributes']['InterpolatedStreetAddress'] === 'true');
            } else {
                $interpolated = (bool)($item['interpolated'] ?? $item['isInterpolated'] ?? false);
            }

            $classification = $item['classification'] ?? $item['addressClassification'] ?? 'UNKNOWN';

// Adresse normalisée / effective (FR renvoie à plat)
            $addrBlock =
                $item['effectiveAddress']
                ?? $item['standardizedAddress']
                ?? $item['normalizedAddress']
                ?? $item['resolvedAddress']
                ?? null;

            if (!$addrBlock) {
                // Construction à partir des champs à plat (cas FR)
                $addrBlock = [
                    'streetLines'        => $item['streetLinesToken'] ?? [],
                    'city'               => $item['city'] ?? null,
                    'stateOrProvinceCode'=> $item['stateOrProvinceCode'] ?? null,
                    'postalCode'         => $item['postalCode'] ?? null,
                    'countryCode'        => $item['countryCode'] ?? null,
                ];
            }

            $normalized = [
                'streetLines' => $addrBlock['streetLines'] ?? [],
                'city'        => $addrBlock['city'] ?? null,
                'state'       => $addrBlock['stateOrProvinceCode'] ?? null,
                'postalCode'  => $addrBlock['postalCode'] ?? null,
                'countryCode' => $addrBlock['countryCode'] ?? null,
            ];

// Annotations utiles
            $annotations = [
                'resolutionMethod' => $item['resolutionMethodName'] ?? null,
                'matchSource'      => $item['standardizedStatusNameMatchSource'] ?? null,
                'attributes'       => $item['attributes'] ?? [],
            ];


            // input hash pour rattacher facilement
            $inputHash = $this->hashInput($inputs[$i] ?? null);

            $results[] = new AddressValidationResult(
                inputHash: $inputHash,
                normalizedAddress: $normalized,
                resolved: $resolved,
                dpvValid: $dpvValid,
                interpolated: $interpolated,
                classification: $classification,
                annotations: $annotations,
                raw: $item
            );
        }

        return $results;
    }

    private function removeNulls(array $a): array
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $a[$k] = $this->removeNulls($v);
                if ($a[$k] === []) unset($a[$k]);
            } elseif ($v === null) unset($a[$k]);
        }
        return $a;
    }

    private function hashInput(?AddressInput $in): string
    {
        if (!$in) return bin2hex(random_bytes(6));
        return substr(sha1(json_encode([$in->streetLines, $in->city, $in->state, $in->postalCode, $in->countryCode, $in->residential])), 0, 12);
    }
}
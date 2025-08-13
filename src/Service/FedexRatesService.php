<?php

declare(strict_types=1);

namespace SonnyDev\FedexBundle\Service;

use DateTimeImmutable;
use DateTimeInterface;
use SonnyDev\FedexBundle\DTO\FedexRateQuote;
use SonnyDev\FedexBundle\Exception\FedexApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FedexRatesService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,
        private readonly string $ratesEndpoint,
        private readonly string $accountNumber,
    ) {
    }

    /**
     * Devis FedEx (tarifs + délais) — version conforme doc Rates.
     *
     * @param array $from ['postalCode','countryCode','city'?, 'stateOrProvinceCode'?]
     * @param array $to ['postalCode','countryCode','city'?, 'stateOrProvinceCode'?]
     * @param array<int,array> $packages chaque package: ['weight'=>float,'weightUnit'=>'KG|LB','length'?, 'width'?, 'height'?, 'dimensionUnit'=>'CM|IN'?]
     * @param string|null $preferredCurrency ex: 'EUR'
     * @param string|null $serviceCode ex: 'FEDEX_INTERNATIONAL_PRIORITY'
     * @param float|null $declaredValue requis pour international (marchandises). Fallback 1.0 si null.
     * @param string|null $carrierCode ex: 'FDXE' (Express) / 'FDXG' (Ground). Null = tous.
     * @param bool $isDocuments true = envoi de documents (pas de customsValue/commodities)
     *
     * @return FedexRateQuote[]
     *
     * @throws FedexApiException
     */
    public function getQuotes(
        array $from,
        array $to,
        array $packages,
        ?DateTimeInterface $shipDate = null,
        ?string $preferredCurrency = null,
        ?string $serviceCode = null,
        ?float $declaredValue = null,
        ?string $carrierCode = null,
        bool $isDocuments = false,
    ): array {
        // 1) Auth (API Rates/Ship)
        $token = $this->authenticator->getAccessToken('ship');

        // 2) Base flags
        $isInternational = strtoupper($from['countryCode']) !== strtoupper($to['countryCode']);
        $shipDate = $shipDate ?? new DateTimeImmutable();
        $ccy = $preferredCurrency ?: null; // laisse FedEx choisir si null

        // 3) Customs (international + marchandises uniquement)
        $customs = null;
        if ($isInternational && !$isDocuments) {
            $totalWeight = 0.0;
            foreach ($packages as $p) {
                $totalWeight += (float)$p['weight'];
            }

            $customs = [
                'dutiesPayment' => [
                    // Ajuste selon ton modèle (RECIPIENT / THIRD_PARTY)
                    'paymentType' => 'SENDER',
                ],
                'commodities' => [[
                    'description' => 'Merchandise',
                    'numberOfPieces' => max(1, (int)count($packages)),
                    'quantity' => 1,
                    'quantityUnits' => 'PCS',
                    'countryOfManufacture' => strtoupper($from['countryCode']),
                    'weight' => [
                        'units' => strtoupper($packages[0]['weightUnit'] ?? 'KG'),
                        'value' => max(0.01, (float)$totalWeight),
                    ],
                    'customsValue' => [
                        'currency' => $ccy ?: 'USD',         // si tu laisses null, FedEx peut refuser — mets un défaut
                        'amount' => (float)($declaredValue ?? 1.0),
                    ],
                    // Optionnels :
                    // 'harmonizedCode' => 'XXXX.XX',
                    // 'unitPrice' => ['currency' => $ccy ?: 'USD', 'amount' => (float) ($declaredValue ?? 1.0)],
                ]],
                'commercialInvoice' => [
                    'shipmentPurpose' => 'SOLD', // ou SAMPLE/GIFT/etc.
                ],
            ];
        }

        // 4) Payload conforme doc (RateRequestTypes, CarrierCode, returnTransitTimes)
        $payload = [
            'accountNumber' => ['value' => $this->accountNumber],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
                // 'rateSortOrder' => 'COMMITASCENDING', // valeur valide si tu veux trier côté API
            ],
            'requestedShipment' => [
                // La doc indique d'utiliser RateRequestTypes pour tarifs compte + publics
                'rateRequestType' => ['ACCOUNT', 'LIST'],
                'carrierCodes' => $carrierCode ? [strtoupper($carrierCode)] : null, // ex: ['FDXE']
                'serviceType' => $serviceCode ?: null,
                'shipDateStamp' => $shipDate->format('Y-m-d'),
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'preferredCurrency' => $ccy,
                'shipper' => [
                    'address' => [
                        'postalCode' => $from['postalCode'],
                        'countryCode' => $from['countryCode'],
                        'city' => $from['city'] ?? null,
                        'stateOrProvinceCode' => $from['stateOrProvinceCode'] ?? null,
                    ],
                ],
                'recipient' => [
                    'address' => [
                        'postalCode' => $to['postalCode'],
                        'countryCode' => $to['countryCode'],
                        'city' => $to['city'] ?? null,
                        'stateOrProvinceCode' => $to['stateOrProvinceCode'] ?? null,
                    ],
                ],
                // DOCUMENTS vs GOODS : si documents, on évite customsClearanceDetail
                'customsClearanceDetail' => $customs,
                // Paquets
                'requestedPackageLineItems' => array_map(
                    static function (array $p): array {
                        return [
                            'weight' => [
                                'units' => strtoupper($p['weightUnit'] ?? 'KG'),
                                'value' => (float)$p['weight'],
                            ],
                            'dimensions' => (isset($p['length'], $p['width'], $p['height']))
                                ? [
                                    'units' => strtoupper($p['dimensionUnit'] ?? 'CM'),
                                    'length' => (string)$p['length'],
                                    'width' => (string)$p['width'],
                                    'height' => (string)$p['height'],
                                ]
                                : null,
                        ];
                    },
                    $packages
                ),
            ],
        ];

        // Nettoyage des nulls
        $payload = $this->removeNulls($payload);

        // 5) Requête
        $resp = $this->httpClient->request('POST', $this->ratesEndpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        if (200 !== $resp->getStatusCode()) {
            $body = $resp->getContent(false);
            throw new FedexApiException('Rates HTTP ' . $resp->getStatusCode() . ' — ' . $body, $resp->getStatusCode(), ['response' => $body]);
        }

        // 6) Parsing
        $data = $resp->toArray(false);
        $quotes = [];

        foreach (($data['output']['rateReplyDetails'] ?? $data['rateReplyDetails'] ?? []) as $detail) {
            $serviceType = (string)($detail['serviceType'] ?? 'UNKNOWN');
            $serviceName = (string)($detail['serviceName'] ?? $serviceType);

            // Montant & devise
            $amount = null;
            $currency = null;
            $shipmentRateDetail = $detail['ratedShipmentDetails'][0]['shipmentRateDetail'] ?? null;
            if (is_array($shipmentRateDetail)) {
                $total = $shipmentRateDetail['totalNetCharge']
                    ?? $shipmentRateDetail['totalNetChargeWithDutiesAndTaxes']
                    ?? null;
                if (is_array($total)) {
                    $amount = (float)($total['amount'] ?? 0);
                    $currency = (string)($total['currency'] ?? 'USD');
                }
            }

            // ETA / transit
            $eta = null;
            if (!empty($detail['deliveryTimestamp'])) {
                $eta = new DateTimeImmutable($detail['deliveryTimestamp']);
            } elseif (!empty($detail['commit']['dateDetail']['day'] ?? null)) {
                $eta = new DateTimeImmutable($detail['commit']['dateDetail']['day']);
            }

            $transitDays = null;
            $tt = $detail['transitTime'] ?? ($detail['commit']['transitTime'] ?? null);
            if (is_numeric($tt)) {
                $transitDays = (int)$tt;
            }

            if (null !== $amount && null !== $currency) {
                $quotes[] = new FedexRateQuote(
                    serviceCode: $serviceType,
                    serviceName: $serviceName,
                    amount: $amount,
                    currency: $currency,
                    estimatedDeliveryDate: $eta,
                    transitDays: $transitDays
                );
            }
        }

        // 7) Tri par prix croissant
        usort($quotes, static fn (FedexRateQuote $a, FedexRateQuote $b) => $a->amount <=> $b->amount);

        return $quotes;
    }

    /** @param array<string,mixed> $value */
    private function removeNulls(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->removeNulls($v);
                if ([] === $value[$k]) {
                    unset($value[$k]);
                }
            } elseif (null === $v) {
                unset($value[$k]);
            }
        }

        return $value;
    }
}

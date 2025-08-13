<?php

declare(strict_types=1);

namespace SonnyDev\FedexBundle\Service;

use DateTimeInterface;
use Psr\Cache\InvalidArgumentException;
use SonnyDev\FedexBundle\DTO\FedexRateQuote;
use SonnyDev\FedexBundle\Exception\FedexApiException;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class FedexRatesService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FedexAuthenticator $authenticator,
        private readonly string $ratesEndpoint,
        private readonly string $accountNumber
    ) {}

    /**
     * @param array{countryCode:string, postalCode:string, city?:string, stateOrProvinceCode?:string} $from
     * @param array{countryCode:string, postalCode:string, city?:string, stateOrProvinceCode?:string} $to
     * @param array<int, array{weight:float, weightUnit?:string, length?:float, width?:float, height?:float, dimensionUnit?:string}> $packages
     * @param DateTimeInterface|null $shipDate
     * @param string|null $preferredCurrency
     * @param string|null $serviceCode
     * @return FedexRateQuote[]
     * @throws InvalidArgumentException
     * @throws ClientExceptionInterface
     * @throws DecodingExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    public function getQuotes(
        array              $from,
        array              $to,
        array              $packages,
        ?DateTimeInterface $shipDate = null,
        ?string            $preferredCurrency = null,
        ?string            $serviceCode = null
    ): array {
        $token = $this->authenticator->getAccessToken();

        $payload = [
            'accountNumber' => [
                'value' => $this->accountNumber,
            ],
            'rateRequestControlParameters' => [
                'returnTransitTimes' => true,
                'rateSortOrder' => 'LOWEST_PRICE',
            ],
            'requestedShipment' => [
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
                'shipDateStamp' => ($shipDate ?? new \DateTimeImmutable())->format('Y-m-d'),
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'preferredCurrency' => $preferredCurrency,
                'serviceType' => $serviceCode, // peut rester null
                'requestedPackageLineItems' => array_map(
                    static function (array $p): array {
                        return [
                            'weight' => [
                                'units' => strtoupper($p['weightUnit'] ?? 'KG'),
                                'value' => $p['weight'],
                            ],
                            'dimensions' => (isset($p['length'], $p['width'], $p['height']))
                                ? [
                                    'units'  => strtoupper($p['dimensionUnit'] ?? 'CM'),
                                    'length' => (string) $p['length'],
                                    'width'  => (string) $p['width'],
                                    'height' => (string) $p['height'],
                                ]
                                : null,
                        ];
                    },
                    $packages
                ),
            ],
        ];

        $payload = $this->removeNulls($payload);

        $resp = $this->httpClient->request('POST', $this->ratesEndpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        if (200 !== $resp->getStatusCode()) {
            throw new FedexApiException('Rates HTTP '.$resp->getStatusCode(), $resp->getStatusCode(), $resp->toArray(false));
        }

        $data = $resp->toArray(false);

        $quotes = [];
        foreach (($data['output']['rateReplyDetails'] ?? $data['rateReplyDetails'] ?? []) as $detail) {
            $serviceType = (string) ($detail['serviceType'] ?? 'UNKNOWN');
            $serviceName = (string) ($detail['serviceName'] ?? $serviceType);

            // Montant & devise
            $amount = null;
            $currency = null;
            $shipmentRateDetail = $detail['ratedShipmentDetails'][0]['shipmentRateDetail'] ?? null;
            if (is_array($shipmentRateDetail)) {
                $total = $shipmentRateDetail['totalNetCharge']
                    ?? $shipmentRateDetail['totalNetChargeWithDutiesAndTaxes']
                    ?? null;
                if (is_array($total)) {
                    $amount = (float) ($total['amount'] ?? 0);
                    $currency = (string) ($total['currency'] ?? 'USD');
                }
            }

            // ETA / transit
            $eta = null;
            if (!empty($detail['deliveryTimestamp'])) {
                $eta = new \DateTimeImmutable($detail['deliveryTimestamp']);
            } elseif (!empty($detail['commit']['dateDetail']['day'] ?? null)) {
                $eta = new \DateTimeImmutable($detail['commit']['dateDetail']['day']);
            }

            $transitDays = null;
            $tt = $detail['transitTime'] ?? ($detail['commit']['transitTime'] ?? null);
            if (is_numeric($tt)) {
                $transitDays = (int) $tt;
            }

            if ($amount !== null && $currency !== null) {
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

        usort($quotes, static fn(FedexRateQuote $a, FedexRateQuote $b) => $a->amount <=> $b->amount);

        return $quotes;
    }

    /** @param array<string,mixed> $value */
    private function removeNulls(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->removeNulls($v);
                if ($value[$k] === []) {
                    unset($value[$k]);
                }
            } elseif ($v === null) {
                unset($value[$k]);
            }
        }
        return $value;
    }
}
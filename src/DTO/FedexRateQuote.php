<?php

declare(strict_types=1);

namespace SonnyDev\FedexBundle\DTO;

final class FedexRateQuote
{
    /**
     * @param string $serviceCode
     * @param string $serviceName
     * @param float $amount
     * @param string $currency
     * @param \DateTimeImmutable|null $estimatedDeliveryDate
     * @param int|null $transitDays
     */
    public function __construct(
        public string $serviceCode,
        public string $serviceName,
        public float $amount,
        public string $currency,
        public ?\DateTimeImmutable $estimatedDeliveryDate,
        public ?int $transitDays
    ) {}
}
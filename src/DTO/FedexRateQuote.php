<?php

declare(strict_types=1);

namespace SonnyDev\FedexBundle\DTO;

use DateTimeImmutable;

final class FedexRateQuote
{
    public function __construct(
        public string $serviceCode,
        public string $serviceName,
        public float $amount,
        public string $currency,
        public ?DateTimeImmutable $estimatedDeliveryDate,
        public ?int $transitDays,
    ) {
    }
}

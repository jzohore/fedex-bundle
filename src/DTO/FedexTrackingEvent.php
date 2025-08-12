<?php

namespace SonnyDev\FedexBundle\DTO;

use Exception;

class FedexTrackingEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $description,
        public readonly \DateTimeImmutable $date,
        public readonly ?string $city,
        public readonly ?string $country
    ) {}

    /**
     * @param array $event
     * @return self
     * @throws Exception
     */
    public static function fromApi(array $event): self
    {
        return new self(
            type: $event['eventType'] ?? 'UNKNOWN',
            description: $event['eventDescription'] ?? 'No description',
            date: new \DateTimeImmutable($event['date'] ?? 'now'),
            city: $event['scanLocation']['city'] ?? null,
            country: $event['scanLocation']['countryName'] ?? null,
        );
    }
}
<?php

namespace SonnyDev\FedexBundle\DTO;

use DateTimeImmutable;
use Exception;

class FedexTrackingEvent
{
    public function __construct(
        public readonly string $type,
        public readonly string $description,
        public readonly DateTimeImmutable $date,
        public readonly ?string $city,
        public readonly ?string $country,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     *
     * @throws Exception
     */
    public static function fromApi(array $event = []): self
    {
        $scanLocation = $event['scanLocation'] ?? [];
        $city = is_array($scanLocation) ? ($scanLocation['city'] ?? null) : null;
        $country = is_array($scanLocation) ? ($scanLocation['countryName'] ?? null) : null;

        return new self(
            type: $event['eventType'] ?? '',
            description: $event['eventDescription'] ?? '',
            date: new DateTimeImmutable($event['date'] ?? 'now'),
            city: $city,
            country: $country
        );
    }
}

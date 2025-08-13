<?php

namespace SonnyDev\FedexBundle\DTO;

final class AddressValidationResult
{
    public function __construct(
        public string $inputHash,                 // pour rattacher au batch (hash de l’input)
        public array $normalizedAddress,          // adresse normalisée/standardisée (streetLines, city, state, postalCode, countryCode)
        public bool $resolved,                    // “resolved address” True/False
        public ?bool $dpvValid,                    // Delivery Point Valid (DPV)
        public bool $interpolated,                // True => probablement pas valide
        public ?string $classification,           // business|residential|mixed|unknown
        public array $annotations = [],           // messages/codes d’annotation
        public array $raw = []                    // réponse brute (debug)
    ) {}
}

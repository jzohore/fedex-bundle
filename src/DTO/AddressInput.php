<?php

namespace SonnyDev\FedexBundle\DTO;

final class AddressInput
{
    public function __construct(
        public array $streetLines,          // ['10 Rue de Rivoli']
        public ?string $city = null,        // 'Paris'
        public ?string $state = null,       // 'NY' / 'CA' / null
        public ?string $postalCode = null,  // '75001'
        public string $countryCode = 'FR',  // 'FR'
        public bool $residential = false
    ) {}
}
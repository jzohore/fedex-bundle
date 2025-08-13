<?php

namespace SonnyDev\FedexBundle\DTO;

class FedexLocation
{
    /**
     * @param string $id
     * @param string $name
     * @param string $street
     * @param string $city
     * @param string $postalCode
     * @param string $countryCode
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $street,
        public string $city,
        public string $postalCode,
        public string $countryCode
    ) {}
}
<?php

namespace SonnyDev\FedexBundle\Exception;


class FedexApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseData = null,
        \Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
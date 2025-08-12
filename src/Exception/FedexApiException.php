<?php
declare(strict_types=1);

namespace SonnyDev\FedexBundle\Exception;

use RuntimeException;
use Throwable;

class FedexApiException extends RuntimeException
{
    /**
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?array $responseData = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

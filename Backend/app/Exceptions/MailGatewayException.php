<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class MailGatewayException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $responseStatus = null,
        private readonly ?string $responseBody = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function responseStatus(): ?int
    {
        return $this->responseStatus;
    }

    public function responseBody(): ?string
    {
        return $this->responseBody;
    }
}

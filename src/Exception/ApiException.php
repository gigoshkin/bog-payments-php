<?php

declare(strict_types=1);

namespace Bog\Payments\Exception;

class ApiException extends BogException
{
    public function __construct(
        string              $message,
        public readonly int    $statusCode,
        public readonly string $responseBody,
        ?\Throwable         $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}

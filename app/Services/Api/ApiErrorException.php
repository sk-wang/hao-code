<?php

namespace App\Services\Api;

class ApiErrorException extends \RuntimeException
{
    private string $errorType;

    public function __construct(string $message, string $errorType = 'unknown', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errorType = $errorType;
    }

    public function getErrorType(): string
    {
        return $this->errorType;
    }
}

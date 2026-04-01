<?php

namespace App\Services\Api;

class StreamEvent
{
    public function __construct(
        public readonly string $type,
        public readonly ?array $data = null,
    ) {}

    public static function fromSse(string $eventType, string $rawData): self
    {
        return new self($eventType, json_decode($rawData, true));
    }
}

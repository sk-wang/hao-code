<?php

namespace App\Sdk;

/**
 * Wrapper for structured (JSON) responses from the agent.
 *
 * Provides array access, property access, and toArray() for convenience.
 *
 * @example
 *   $result = HaoCode::structured('Classify this ticket', $schema);
 *   $result->category;      // 'shipping'
 *   $result['priority'];    // 'high'
 *   $result->toArray();     // ['category' => 'shipping', 'priority' => 'high', ...]
 */
class StructuredResult implements \ArrayAccess, \Stringable
{
    private readonly array $data;

    public readonly string $rawText;

    public readonly ?QueryResult $queryResult;

    public function __construct(array $data, string $rawText = '', ?QueryResult $queryResult = null)
    {
        $this->data = $data;
        $this->rawText = $rawText;
        $this->queryResult = $queryResult;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    public function toJson(int $flags = 0): string
    {
        return json_encode($this->data, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function __toString(): string
    {
        return $this->toJson(JSON_PRETTY_PRINT);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \RuntimeException('StructuredResult is immutable.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \RuntimeException('StructuredResult is immutable.');
    }
}

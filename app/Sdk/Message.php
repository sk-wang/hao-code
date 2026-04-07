<?php

namespace App\Sdk;

/**
 * SDK message — typed envelope for streaming events.
 *
 * Modeled after Claude Agent SDK's SDKMessage types:
 * - text: streaming text delta
 * - tool_start: tool execution began
 * - tool_result: tool execution completed
 * - turn: a new agent turn started
 * - result: final result with usage/cost
 * - error: an error occurred
 */
class Message
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $text = null,
        public readonly ?string $toolName = null,
        public readonly ?array $toolInput = null,
        public readonly ?string $toolOutput = null,
        public readonly ?bool $toolIsError = null,
        public readonly ?int $turnNumber = null,
        public readonly ?string $sessionId = null,
        public readonly ?array $usage = null,
        public readonly ?float $cost = null,
        public readonly ?string $error = null,
    ) {}

    public static function text(string $delta): self
    {
        return new self(type: 'text', text: $delta);
    }

    public static function toolStart(string $toolName, array $input): self
    {
        return new self(type: 'tool_start', toolName: $toolName, toolInput: $input);
    }

    public static function toolResult(string $toolName, string $output, bool $isError = false): self
    {
        return new self(type: 'tool_result', toolName: $toolName, toolOutput: $output, toolIsError: $isError);
    }

    public static function turn(int $number): self
    {
        return new self(type: 'turn', turnNumber: $number);
    }

    public static function result(string $text, array $usage, float $cost, ?string $sessionId = null): self
    {
        return new self(type: 'result', text: $text, usage: $usage, cost: $cost, sessionId: $sessionId);
    }

    public static function error(string $message): self
    {
        return new self(type: 'error', error: $message);
    }

    public function isResult(): bool
    {
        return $this->type === 'result';
    }

    public function isError(): bool
    {
        return $this->type === 'error';
    }
}

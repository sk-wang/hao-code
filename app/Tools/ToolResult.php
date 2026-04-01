<?php

namespace App\Tools;

class ToolResult
{
    public function __construct(
        public readonly string $output,
        public readonly bool $isError = false,
        public readonly ?array $metadata = null,
    ) {}

    public static function success(string $output, ?array $metadata = null): self
    {
        return new self($output, false, $metadata);
    }

    public static function error(string $output, ?array $metadata = null): self
    {
        return new self($output, true, $metadata);
    }

    /**
     * Convert to the Anthropic API tool_result content block format.
     */
    public function toApiFormat(string $toolUseId): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $toolUseId,
            'content' => $this->output,
            'is_error' => $this->isError,
        ];
    }
}

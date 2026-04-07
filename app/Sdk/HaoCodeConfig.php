<?php

namespace App\Sdk;

/**
 * Configuration for HaoCode SDK queries.
 *
 * Modeled after Claude Agent SDK's ClaudeAgentOptions — a single config
 * object that controls model, tools, permissions, cost limits, and callbacks.
 */
class HaoCodeConfig
{
    public function __construct(
        /** Anthropic API key. Falls back to config('haocode.api_key'). */
        public readonly ?string $apiKey = null,

        /** Model identifier (e.g., 'claude-sonnet-4-20250514'). */
        public readonly ?string $model = null,

        /** API base URL (for custom endpoints / proxies). */
        public readonly ?string $baseUrl = null,

        /** Maximum output tokens per response. */
        public readonly ?int $maxTokens = null,

        /** Working directory for tool execution. Defaults to getcwd(). */
        public readonly ?string $cwd = null,

        /** Maximum agent turns (tool-use round trips). Default: 50. */
        public readonly int $maxTurns = 50,

        /** Maximum spending in USD before stopping. null = no limit. */
        public readonly ?float $maxBudgetUsd = null,

        /**
         * Permission mode: 'default', 'plan', 'accept_edits', 'bypass_permissions'.
         * Default: 'bypass_permissions' (SDK consumers handle their own safety).
         */
        public readonly string $permissionMode = 'bypass_permissions',

        /**
         * Tools to allow. ['*'] = all (default). Use tool names like ['Bash', 'Read', 'Write'].
         * @var string[]
         */
        public readonly array $allowedTools = ['*'],

        /**
         * Tools to deny. Takes precedence over allowedTools.
         * @var string[]
         */
        public readonly array $disallowedTools = [],

        /** Custom system prompt. null = use default. */
        public readonly ?string $systemPrompt = null,

        /** Text appended to the default system prompt. */
        public readonly ?string $appendSystemPrompt = null,

        /** Enable extended thinking. */
        public readonly bool $thinkingEnabled = false,

        /** Thinking token budget when thinking is enabled. */
        public readonly int $thinkingBudget = 10000,

        /**
         * Streaming text callback: fn(string $delta): void
         * Called for each text chunk as it arrives from the model.
         * @var callable|null
         */
        public readonly mixed $onText = null,

        /**
         * Tool start callback: fn(string $toolName, array $input): void
         * Called when a tool begins execution.
         * @var callable|null
         */
        public readonly mixed $onToolStart = null,

        /**
         * Tool complete callback: fn(string $toolName, ToolResult $result): void
         * Called when a tool finishes execution.
         * @var callable|null
         */
        public readonly mixed $onToolComplete = null,

        /**
         * Turn start callback: fn(int $turnNumber): void
         * Called at the start of each agent turn.
         * @var callable|null
         */
        public readonly mixed $onTurnStart = null,

        /** Disable session persistence (ephemeral query). */
        public readonly bool $ephemeral = false,
    ) {}

    /**
     * Create a minimal config for quick queries.
     */
    public static function make(?string $apiKey = null, ?string $model = null): self
    {
        return new self(apiKey: $apiKey, model: $model);
    }

    /**
     * Build a tool filter callable from allowedTools/disallowedTools.
     */
    public function toolFilter(): ?callable
    {
        if ($this->allowedTools === ['*'] && $this->disallowedTools === []) {
            return null;
        }

        return function (string $toolName): bool {
            if (in_array($toolName, $this->disallowedTools, true)) {
                return false;
            }

            if (in_array('*', $this->allowedTools, true)) {
                return true;
            }

            return in_array($toolName, $this->allowedTools, true);
        };
    }
}

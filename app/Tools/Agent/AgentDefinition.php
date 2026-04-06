<?php

namespace App\Tools\Agent;

/**
 * Definition for a built-in or user-defined agent type.
 *
 * Mirrors claude-code's BaseAgentDefinition from AgentTool/built-in/*.ts.
 */
class AgentDefinition
{
    /**
     * @param string $agentType Unique identifier (e.g., 'Explore', 'Plan', 'code-reviewer')
     * @param string $whenToUse Description of when to spawn this agent
     * @param string $systemPrompt System prompt for the agent
     * @param string[] $tools Allowed tools ('*' = all, or specific names)
     * @param string[] $disallowedTools Tools this agent cannot use
     * @param string $source 'built-in' or 'custom'
     * @param string|null $model Model override ('haiku', 'sonnet', 'opus', 'inherit', or null)
     * @param bool $readOnly Whether this agent should be read-only (no file modifications)
     * @param bool $background Always run in background
     * @param bool $omitClaudeMd Skip CLAUDE.md in context
     * @param int|null $maxTurns Maximum agent turns
     */
    public function __construct(
        public readonly string $agentType,
        public readonly string $whenToUse,
        public readonly string $systemPrompt,
        public readonly array $tools = ['*'],
        public readonly array $disallowedTools = [],
        public readonly string $source = 'built-in',
        public readonly ?string $model = null,
        public readonly bool $readOnly = false,
        public readonly bool $background = false,
        public readonly bool $omitClaudeMd = false,
        public readonly ?int $maxTurns = null,
    ) {}

    /**
     * Check if a tool is allowed for this agent.
     */
    public function isToolAllowed(string $toolName): bool
    {
        // Deny list takes precedence
        if (in_array($toolName, $this->disallowedTools, true)) {
            return false;
        }

        // Wildcard allows everything not denied
        if (in_array('*', $this->tools, true)) {
            return true;
        }

        return in_array($toolName, $this->tools, true);
    }
}

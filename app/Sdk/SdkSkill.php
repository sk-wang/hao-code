<?php

namespace App\Sdk;

use App\Tools\Skill\SkillDefinition;

/**
 * Simplified skill definition for SDK consumers.
 *
 * A skill is a named prompt template that the agent can invoke.
 * Unlike tools (which execute code), skills inject instructions
 * that guide the agent's behavior.
 *
 * @example
 *   $skill = new SdkSkill(
 *       name: 'review',
 *       description: 'Review code for security issues',
 *       prompt: 'Review the following code for OWASP Top 10 vulnerabilities. $ARGUMENTS',
 *   );
 *
 *   $result = HaoCode::query('Review auth.php', new HaoCodeConfig(
 *       skills: [$skill],
 *   ));
 */
class SdkSkill
{
    public function __construct(
        /** Skill name (used as /name slash command). */
        public readonly string $name,

        /** One-line description shown to the agent. */
        public readonly string $description,

        /** The prompt template. Use $ARGUMENTS for user-provided args. */
        public readonly string $prompt,

        /**
         * Tools allowed when executing this skill. Empty = all tools.
         * @var string[]
         */
        public readonly array $allowedTools = [],

        /** Optional model override for this skill. */
        public readonly ?string $model = null,
    ) {}

    /**
     * Convert to internal SkillDefinition.
     */
    public function toDefinition(): SkillDefinition
    {
        return new SkillDefinition(
            name: $this->name,
            description: $this->description,
            whenToUse: null,
            prompt: $this->prompt,
            allowedTools: $this->allowedTools,
            model: $this->model,
        );
    }
}

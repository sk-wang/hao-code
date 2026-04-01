<?php

namespace App\Tools\Skill;

class SkillDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $whenToUse,
        public readonly string $prompt,
        public readonly array $allowedTools = [],
        public readonly ?string $model = null,
        public readonly string $context = 'inline',  // 'inline' or 'fork'
        public readonly bool $userInvocable = true,
        public readonly ?string $argumentHint = null,
        public readonly string $skillDir = '',
    ) {}
}

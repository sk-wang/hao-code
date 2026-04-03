<?php

namespace App\Tools\Skill;

/**
 * Loads skill definitions from markdown files with YAML frontmatter.
 *
 * Skill discovery paths:
 * 1. ~/.haocode/skills/<name>/SKILL.md
 * 2. .haocode/skills/<name>/SKILL.md
 * 3. .haocode/commands/<name>.md (legacy)
 */
class SkillLoader
{
    /** @var array<string, SkillDefinition> */
    private ?array $skills = null;

    /**
     * Load and return all available skills.
     * @return array<string, SkillDefinition>
     */
    public function loadSkills(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $this->skills = [];

        // Load from multiple sources
        $dirs = $this->getSkillDirectories();

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $this->loadFromDirectory($dir);
        }

        return $this->skills;
    }

    /**
     * Find a skill by name.
     */
    public function findSkill(string $name): ?SkillDefinition
    {
        $name = ltrim($name, '/');
        $skills = $this->loadSkills();
        return $skills[$name] ?? null;
    }

    /**
     * Get skill descriptions for system prompt injection.
     */
    public function getSkillDescriptions(int $maxChars = 2000): string
    {
        $skills = $this->loadSkills();
        if (empty($skills)) {
            return '';
        }

        $descriptions = [];
        $totalLen = 0;

        foreach ($skills as $name => $skill) {
            $desc = "- /{$name}: {$skill->description}";
            if ($totalLen + strlen($desc) > $maxChars) break;
            $descriptions[] = $desc;
            $totalLen += strlen($desc);
        }

        return "Available skills (slash commands):\n" . implode("\n", $descriptions);
    }

    /**
     * List all skills as arrays for display.
     * @return array<int, array{name: string, description: string, user_invocable: bool}>
     */
    public function listSkills(): array
    {
        $skills = $this->loadSkills();
        $list = [];
        foreach ($skills as $name => $skill) {
            $list[] = [
                'name' => $name,
                'description' => $skill->description,
                'user_invocable' => $skill->userInvocable,
            ];
        }
        return $list;
    }

    private function getSkillDirectories(): array
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();
        $cwd = getcwd();

        return [
            "{$home}/.haocode/skills",
            $cwd . '/.haocode/skills',
            $cwd . '/.haocode/commands', // legacy
        ];
    }

    private function loadFromDirectory(string $dir): void
    {
        // New format: <name>/SKILL.md
        foreach (glob($dir . '/*/SKILL.md') as $file) {
            $name = basename(dirname($file));
            $this->registerSkill($name, $file);
        }

        // Legacy format: <name>.md
        foreach (glob($dir . '/*.md') as $file) {
            $name = basename($file, '.md');
            if (!isset($this->skills[$name])) {
                $this->registerSkill($name, $file);
            }
        }
    }

    private function registerSkill(string $name, string $file): void
    {
        $content = file_get_contents($file);
        $def = $this->parseSkillFile($name, $content, dirname($file));
        $this->skills[$name] = $def;
    }

    private function parseSkillFile(string $name, string $content, string $dir): SkillDefinition
    {
        $frontmatter = [];
        $body = $content;

        // Parse YAML frontmatter
        if (preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)/s', $content, $m)) {
            $frontmatter = $this->parseYaml($m[1]);
            $body = $m[2];
        }

        return new SkillDefinition(
            name: $name,
            description: $frontmatter['description'] ?? $this->firstLine($body),
            whenToUse: $frontmatter['when_to_use'] ?? null,
            prompt: trim($body),
            allowedTools: $this->parseList($frontmatter['allowed-tools'] ?? ''),
            model: $frontmatter['model'] ?? null,
            context: $frontmatter['context'] ?? 'inline',
            userInvocable: ($frontmatter['user-invocable'] ?? 'true') !== 'false',
            argumentHint: $frontmatter['argument-hint'] ?? null,
            skillDir: $dir,
        );
    }

    private function parseYaml(string $yaml): array
    {
        $result = [];
        foreach (explode("\n", $yaml) as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, '#')) continue;
            if (preg_match('/^(\w[\w-]*):\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $value = trim($m[2], '"\' ');
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function parseList(string $value): array
    {
        if (empty($value)) return [];
        return array_map('trim', explode(',', $value));
    }

    private function firstLine(string $text): string
    {
        $line = trim(explode("\n", trim($text))[0] ?? '');
        return mb_substr($line, 0, 100);
    }
}

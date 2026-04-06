<?php

namespace App\Tools\Agent;

/**
 * Loads user-defined agent definitions from .claude/agents/ directories.
 *
 * Agents can be defined as Markdown files (.md) with YAML frontmatter
 * or JSON files (.json). This matches claude-code's loadAgentsDir.ts.
 */
class AgentLoader
{
    /**
     * Load all agent definitions (built-in + user-defined).
     *
     * @return array<string, AgentDefinition>
     */
    public static function loadAll(string $projectDir): array
    {
        $agents = BuiltInAgents::all();

        // Load from project .claude/agents/
        foreach (self::loadFromDirectory($projectDir . '/.claude/agents') as $agent) {
            $agents[$agent->agentType] = $agent;
        }

        // Load from global ~/.claude/agents/
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
        if ($home !== '') {
            foreach (self::loadFromDirectory($home . '/.claude/agents') as $agent) {
                // Don't override project-level agents
                if (!isset($agents[$agent->agentType])) {
                    $agents[$agent->agentType] = $agent;
                }
            }
        }

        return $agents;
    }

    /**
     * Load agent definitions from a directory.
     *
     * @return AgentDefinition[]
     */
    private static function loadFromDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $agents = [];
        $files = glob($dir . '/*.{md,json}', GLOB_BRACE);

        foreach ($files as $file) {
            $agent = self::parseFile($file);
            if ($agent !== null) {
                $agents[] = $agent;
            }
        }

        return $agents;
    }

    private static function parseFile(string $file): ?AgentDefinition
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return match ($ext) {
            'md' => self::parseMarkdown($file),
            'json' => self::parseJson($file),
            default => null,
        };
    }

    /**
     * Parse a Markdown agent definition with YAML frontmatter.
     *
     * Format:
     * ---
     * name: my-agent
     * description: When to use this agent
     * model: sonnet
     * tools: ["Read", "Grep"]
     * ---
     * System prompt content here...
     */
    private static function parseMarkdown(string $file): ?AgentDefinition
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        // Parse YAML frontmatter
        if (!preg_match('/^---\s*\n(.*?)\n---\s*\n(.*)$/s', $content, $m)) {
            return null;
        }

        $frontmatter = self::parseYamlFrontmatter($m[1]);
        $body = trim($m[2]);

        $name = $frontmatter['name'] ?? pathinfo($file, PATHINFO_FILENAME);
        $tools = $frontmatter['tools'] ?? ['*'];
        $disallowed = $frontmatter['disallowedTools'] ?? [];

        // Ensure tools/disallowed are arrays (YAML may produce strings)
        if (is_string($tools)) {
            $tools = array_map('trim', explode(',', $tools));
        }
        if (is_string($disallowed)) {
            $disallowed = array_map('trim', explode(',', $disallowed));
        }

        return new AgentDefinition(
            agentType: $name,
            whenToUse: $frontmatter['description'] ?? $frontmatter['whenToUse'] ?? '',
            systemPrompt: $body,
            tools: $tools,
            disallowedTools: $disallowed,
            source: 'custom',
            model: $frontmatter['model'] ?? null,
            readOnly: (bool) ($frontmatter['readOnly'] ?? false),
            background: (bool) ($frontmatter['background'] ?? false),
            omitClaudeMd: (bool) ($frontmatter['omitClaudeMd'] ?? false),
            maxTurns: isset($frontmatter['maxTurns']) ? (int) $frontmatter['maxTurns'] : null,
        );
    }

    private static function parseJson(string $file): ?AgentDefinition
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['name'])) {
            return null;
        }

        return new AgentDefinition(
            agentType: $data['name'],
            whenToUse: $data['description'] ?? $data['whenToUse'] ?? '',
            systemPrompt: $data['systemPrompt'] ?? $data['prompt'] ?? '',
            tools: $data['tools'] ?? ['*'],
            disallowedTools: $data['disallowedTools'] ?? [],
            source: 'custom',
            model: $data['model'] ?? null,
            readOnly: (bool) ($data['readOnly'] ?? false),
            background: (bool) ($data['background'] ?? false),
            omitClaudeMd: (bool) ($data['omitClaudeMd'] ?? false),
            maxTurns: isset($data['maxTurns']) ? (int) $data['maxTurns'] : null,
        );
    }

    /**
     * Simple YAML frontmatter parser for agent definitions.
     * Handles: key: value, key: [array], key: "string", key: true/false
     *
     * @return array<string, mixed>
     */
    private static function parseYamlFrontmatter(string $yaml): array
    {
        $result = [];

        foreach (explode("\n", $yaml) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^(\w+)\s*:\s*(.*)$/', $line, $m)) {
                continue;
            }

            $key = $m[1];
            $value = trim($m[2]);

            // JSON array
            if (str_starts_with($value, '[')) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $result[$key] = $decoded;
                    continue;
                }
            }

            // Boolean
            if ($value === 'true') {
                $result[$key] = true;
                continue;
            }
            if ($value === 'false') {
                $result[$key] = false;
                continue;
            }

            // Number
            if (is_numeric($value)) {
                $result[$key] = (int) $value;
                continue;
            }

            // Strip quotes
            if ((str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}

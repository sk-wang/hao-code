<?php

namespace App\Services\Agent;

class TeamManager
{
    public function __construct(
        private readonly ?string $storagePath = null,
    ) {
        $this->ensureStoragePath();
    }

    /**
     * @param  array<int, array{role: string, agent_type: string, prompt: string, model?: string|null}>  $members
     * @return array<string, mixed>
     */
    public function create(string $name, array $members): array
    {
        $team = [
            'name' => $name,
            'members' => array_map(fn (array $m) => [
                'role' => $m['role'],
                'agent_id' => self::memberAgentId($name, $m['role']),
                'agent_type' => $m['agent_type'] ?? 'general-purpose',
                'model' => $m['model'] ?? null,
                'prompt' => $m['prompt'],
            ], $members),
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $this->writeJson($this->teamPath($name), $team);

        return $team;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $name): ?array
    {
        $path = $this->teamPath($name);
        if (!is_file($path)) {
            return null;
        }

        return $this->readJson($path);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $teams = [];

        foreach (glob($this->storageRoot() . '/*.team.json') ?: [] as $path) {
            $team = $this->readJson($path);
            if (is_array($team)) {
                $teams[] = $team;
            }
        }

        usort($teams, fn (array $a, array $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        return $teams;
    }

    public function delete(string $name): bool
    {
        $path = $this->teamPath($name);
        if (!is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * Generate the deterministic agent ID for a team member.
     */
    public static function memberAgentId(string $teamName, string $role): string
    {
        $sanitized = preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($role)));
        $sanitized = trim($sanitized, '-');

        return $teamName . '_' . $sanitized;
    }

    private function teamPath(string $name): string
    {
        return $this->storageRoot() . "/{$name}.team.json";
    }

    private function storageRoot(): string
    {
        return $this->storagePath ?? sys_get_temp_dir() . '/haocode_teams';
    }

    private function ensureStoragePath(): void
    {
        if (!is_dir($this->storageRoot())) {
            mkdir($this->storageRoot(), 0755, true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        file_put_contents(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX,
        );
    }
}

<?php

namespace App\Tools;

use App\Contracts\ToolInterface;

class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $tools = [];

    public function register(ToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function getTool(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return array<string, ToolInterface>
     */
    public function getAllTools(): array
    {
        return array_filter($this->tools, fn(ToolInterface $t) => $t->isEnabled());
    }

    /**
     * Remove a tool by name (e.g. for dynamic MCP tool cleanup).
     */
    public function unregister(string $name): void
    {
        unset($this->tools[$name]);
    }

    /**
     * Check if a tool with the given name is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Convert all enabled tools to Anthropic API tool format.
     */
    public function toApiTools(): array
    {
        return array_values(array_map(function (ToolInterface $tool) {
            return [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'input_schema' => $tool->inputSchema()->toJsonSchema(),
            ];
        }, $this->getAllTools()));
    }
}

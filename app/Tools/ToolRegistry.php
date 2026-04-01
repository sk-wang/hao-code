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

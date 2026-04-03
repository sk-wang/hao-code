<?php

namespace App\Services\Agent;

use App\Services\Git\GitContext;
use App\Services\Memory\SessionMemory;
use App\Services\OutputStyle\OutputStyleLoader;
use App\Services\Settings\SettingsManager;
use App\Tools\Skill\SkillLoader;
use App\Tools\ToolRegistry;

class ContextBuilder
{
    public function __construct(
        private readonly SettingsManager $settings,
        private readonly ToolRegistry $toolRegistry,
        private readonly SessionMemory $sessionMemory,
        private readonly SkillLoader $skillLoader,
        private readonly GitContext $gitContext,
        private readonly ?OutputStyleLoader $outputStyleLoader = null,
    ) {}

    public function buildSystemPrompt(): array
    {
        $prompt = $this->getDefaultSystemPrompt();

        $appendPrompt = $this->settings->getAppendSystemPrompt();
        if ($appendPrompt) {
            $prompt .= "\n\n" . $appendPrompt;
        }

        $prompt .= $this->getEnvironmentContext();

        // Load memory files (HAOCODE.md / CLAUDE.md)
        $memoryContent = $this->loadMemoryFiles();
        if ($memoryContent) {
            $prompt .= "\n\n# Project Instructions (from memory files)\n\n" . $memoryContent;
        }

        // Load persistent session memory
        $memories = $this->sessionMemory->forSystemPrompt();
        if ($memories) {
            $prompt .= "\n\n# Session Memory\n\n" . $memories;
        }

        // Load available skills
        $skillDescs = $this->skillLoader->getSkillDescriptions();
        if ($skillDescs) {
            $prompt .= "\n\n# Skills\n\n" . $skillDescs;
        }

        $prompt .= $this->getHaoCodeConventions();

        // Append git context (current diff, branch info)
        $gitContext = $this->gitContext->getDiffContext();
        if ($gitContext) {
            $prompt .= $gitContext;
        }

        // Inject active output style instructions
        $activeStyle = $this->settings->getOutputStyle();
        if ($activeStyle && $this->outputStyleLoader) {
            $styleContent = $this->outputStyleLoader->getActiveStyleContent($activeStyle);
            if ($styleContent) {
                $prompt .= "\n\n# Output Style Instructions\n\n" . $styleContent;
            }
        }

        return [['type' => 'text', 'text' => $prompt, 'cache_control' => ['type' => 'ephemeral']]];
    }

    private function getDefaultSystemPrompt(): string
    {
        $path = resource_path('prompts/system.md');
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return $this->getFallbackSystemPrompt();
    }

    private function getFallbackSystemPrompt(): string
    {
        return <<<'PROMPT'
You are Hao Code, an interactive CLI agent powered by Anthropic's Claude. You help users with software engineering tasks.

# System

- All text you output outside of tool use is displayed to the user.
- Tools are executed in a user-selected permission mode.

# Doing tasks

- The user will primarily request you to perform software engineering tasks.
- In general, do not propose changes to code you haven't read.
- Do not create files unless they're absolutely necessary.
- Be careful not to introduce security vulnerabilities.

# Tone and style

- Only use emojis if the user explicitly requests it.
- Your responses should be short and concise.
- Lead with the answer or action, not the reasoning.
PROMPT;
    }

    private function getEnvironmentContext(): string
    {
        $cwd = getcwd();
        $date = date('Y-m-d');

        $context = "\n\n# Environment\n";
        $context .= "- Current date: {$date}\n";
        $context .= "- Working directory: {$cwd}\n";
        $context .= "- PHP: " . PHP_VERSION . "\n";
        $context .= "- OS: " . PHP_OS_FAMILY . "\n";

        $gitBranch = exec('git rev-parse --abbrev-ref HEAD 2>/dev/null');
        if (!empty($gitBranch)) {
            $context .= "- Git branch: {$gitBranch}\n";
        }

        return $context;
    }

    /**
     * Load memory/instruction files from the project hierarchy.
     * Checks for: HAOCODE.md, CLAUDE.md, .haocode/instructions.md
     */
    private function loadMemoryFiles(): string
    {
        $content = '';
        $cwd = getcwd();
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: sys_get_temp_dir();

        // Global user instructions
        $globalPaths = [
            "{$home}/.haocode/HAOCODE.md",
            "{$home}/.haocode/CLAUDE.md",
        ];

        foreach ($globalPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content .= "## Global Instructions ({$path})\n";
                $content .= file_get_contents($path) . "\n\n";
            }
        }

        // Project-level instructions
        $projectPaths = [
            "{$cwd}/HAOCODE.md",
            "{$cwd}/CLAUDE.md",
            "{$cwd}/.haocode/instructions.md",
            "{$cwd}/.haocode/HAOCODE.md",
            "{$cwd}/.haocode/CLAUDE.md",
        ];

        foreach ($projectPaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content .= "## Project Instructions ({$path})\n";
                $content .= file_get_contents($path) . "\n\n";
            }
        }

        // Load rule files from .haocode/rules/*.md
        $rulesDir = "{$cwd}/.haocode/rules";
        if (is_dir($rulesDir)) {
            foreach (glob("{$rulesDir}/*.md") as $ruleFile) {
                $content .= "## Rule: " . basename($ruleFile) . "\n";
                $content .= file_get_contents($ruleFile) . "\n\n";
            }
        }

        return trim($content);
    }

    private function getHaoCodeConventions(): string
    {
        return <<<'TEXT'


# Hao Code Conventions

- Hao Code-owned files and generated artifacts must use `.haocode`, not `.claude`.
- Store skills under `~/.haocode/skills/` or `.haocode/skills/`.
- If imported compatibility instructions mention Claude Code paths like `.claude/...`, translate them to the Hao Code equivalent under `.haocode/...`.
- Do not create or modify `.claude/` files unless the user explicitly asks for Claude Code compatibility work.
TEXT;
    }
}

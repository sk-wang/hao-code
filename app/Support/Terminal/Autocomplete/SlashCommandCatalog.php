<?php

declare(strict_types=1);

namespace App\Support\Terminal\Autocomplete;

final class SlashCommandCatalog
{
    /**
     * @return array<int, array{name: string, aliases: string[], description: string, help?: string}>
     */
    public function all(): array
    {
        return [
            ['name' => 'help', 'aliases' => ['h', '?'], 'description' => 'show help'],
            ['name' => 'exit', 'aliases' => ['quit', 'q'], 'description' => 'exit the REPL'],
            ['name' => 'clear', 'aliases' => [], 'description' => 'clear conversation history'],
            ['name' => 'compact', 'aliases' => [], 'description' => 'compact conversation context'],
            ['name' => 'config', 'aliases' => [], 'description' => 'view or change runtime settings', 'help' => 'view or change runtime settings (list|get|set)'],
            ['name' => 'cost', 'aliases' => ['usage'], 'description' => 'show token usage and cost'],
            ['name' => 'history', 'aliases' => [], 'description' => 'show message count'],
            ['name' => 'hooks', 'aliases' => [], 'description' => 'view configured hooks'],
            ['name' => 'files', 'aliases' => [], 'description' => 'list files currently in context'],
            ['name' => 'mcp', 'aliases' => [], 'description' => 'manage MCP server configs', 'help' => 'manage MCP servers (/mcp list|add|show|enable|disable|remove)'],
            ['name' => 'model', 'aliases' => [], 'description' => 'show or set current model'],
            ['name' => 'provider', 'aliases' => [], 'description' => 'show or switch API provider', 'help' => 'show or switch API provider (/provider list|use|clear)'],
            ['name' => 'plan', 'aliases' => [], 'description' => 'enable plan mode or run a planning prompt', 'help' => 'enable plan mode or run a planning prompt (/plan off to exit)'],
            ['name' => 'review', 'aliases' => [], 'description' => 'review current branch or a PR'],
            ['name' => 'status', 'aliases' => [], 'description' => 'show session status'],
            ['name' => 'statusline', 'aliases' => [], 'description' => 'configure the REPL status line footer'],
            ['name' => 'stats', 'aliases' => [], 'description' => 'show session analytics'],
            ['name' => 'transcript', 'aliases' => ['t'], 'description' => 'browse transcript mode'],
            ['name' => 'search', 'aliases' => [], 'description' => 'open transcript search'],
            ['name' => 'tasks', 'aliases' => [], 'description' => 'list background tasks'],
            ['name' => 'resume', 'aliases' => [], 'description' => 'resume a previous session'],
            ['name' => 'branch', 'aliases' => ['fork'], 'description' => 'branch the current conversation', 'help' => 'branch the current conversation (/branch [title])'],
            ['name' => 'commit', 'aliases' => [], 'description' => 'create a git commit'],
            ['name' => 'diff', 'aliases' => [], 'description' => 'show uncommitted changes'],
            ['name' => 'memory', 'aliases' => [], 'description' => 'view or edit session memory'],
            ['name' => 'context', 'aliases' => [], 'description' => 'show context usage'],
            ['name' => 'rewind', 'aliases' => [], 'description' => 'undo last change'],
            ['name' => 'doctor', 'aliases' => [], 'description' => 'run diagnostics'],
            ['name' => 'skills', 'aliases' => [], 'description' => 'list available skills'],
            ['name' => 'theme', 'aliases' => [], 'description' => 'toggle color theme'],
            ['name' => 'permissions', 'aliases' => ['perm'], 'description' => 'manage permission rules'],
            ['name' => 'fast', 'aliases' => [], 'description' => 'toggle fast model mode'],
            ['name' => 'snapshot', 'aliases' => [], 'description' => 'export session markdown'],
            ['name' => 'export', 'aliases' => [], 'description' => 'export conversation to markdown'],
            ['name' => 'init', 'aliases' => [], 'description' => 'initialize .haocode project setup'],
            ['name' => 'loop', 'aliases' => [], 'description' => 'schedule a recurring prompt'],
            ['name' => 'version', 'aliases' => [], 'description' => 'show version information'],
            ['name' => 'output-style', 'aliases' => [], 'description' => 'list or set output style'],
            ['name' => 'dream', 'aliases' => [], 'description' => 'run memory consolidation'],
            ['name' => 'buddy', 'aliases' => [], 'description' => 'companion pet', 'help' => 'companion pet (card|hatch|pet|feed|mute|release)'],
            ['name' => 'rename', 'aliases' => [], 'description' => 'rename current session', 'help' => 'rename current session (/rename [title])'],
            ['name' => 'effort', 'aliases' => [], 'description' => 'set reasoning effort level', 'help' => 'set reasoning effort (/effort low|medium|high|max|auto)'],
            ['name' => 'vim', 'aliases' => [], 'description' => 'toggle vim editing mode'],
            ['name' => 'copy', 'aliases' => [], 'description' => 'copy last response to clipboard'],
            ['name' => 'env', 'aliases' => [], 'description' => 'show environment info'],
            ['name' => 'release-notes', 'aliases' => ['changelog'], 'description' => 'show version release notes'],
            ['name' => 'upgrade', 'aliases' => [], 'description' => 'show upgrade instructions'],
            ['name' => 'session', 'aliases' => [], 'description' => 'show current session info'],
            ['name' => 'add-dir', 'aliases' => [], 'description' => 'add directory to allowed paths', 'help' => 'add directory to allowed paths (/add-dir <path>)'],
            ['name' => 'pr-comments', 'aliases' => ['pr_comments'], 'description' => 'fetch GitHub PR comments', 'help' => 'fetch PR comments (/pr-comments [number])'],
            ['name' => 'agents', 'aliases' => [], 'description' => 'list available tools and agents'],
            ['name' => 'feedback', 'aliases' => [], 'description' => 'submit feedback or bug report'],
            ['name' => 'login', 'aliases' => [], 'description' => 'set or update API key'],
            ['name' => 'logout', 'aliases' => [], 'description' => 'clear API key and session'],
            ['name' => 'keybindings', 'aliases' => [], 'description' => 'open keybindings configuration'],
        ];
    }

    /**
     * @return array{name: string, aliases: string[], description: string, help?: string}|null
     */
    public function resolve(string $command): ?array
    {
        $needle = ltrim(strtolower(trim($command)), '/');
        if ($needle === '') {
            return null;
        }

        foreach ($this->all() as $definition) {
            if ($definition['name'] === $needle) {
                return $definition;
            }

            foreach ($definition['aliases'] as $alias) {
                if ($alias === $needle) {
                    return $definition;
                }
            }
        }

        return null;
    }
}

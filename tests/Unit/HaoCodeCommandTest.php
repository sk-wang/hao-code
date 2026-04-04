<?php

namespace Tests\Unit;

use App\Console\Commands\HaoCodeCommand;
use PHPUnit\Framework\TestCase;

class HaoCodeCommandTest extends TestCase
{
    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($target);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    public function test_find_history_match_returns_latest_matching_entry(): void
    {
        $command = new HaoCodeCommand;

        $match = $this->invoke($command, 'findHistoryMatch', [
            'git status',
            'php artisan test',
            'git diff --stat',
        ], 'git', '');

        $this->assertSame('git diff --stat', $match);
    }

    public function test_find_history_match_skips_current_input_when_query_empty(): void
    {
        $command = new HaoCodeCommand;

        $match = $this->invoke($command, 'findHistoryMatch', [
            'first',
            'second',
            'third',
        ], '', 'third');

        $this->assertSame('second', $match);
    }

    public function test_find_history_match_returns_null_when_no_match_exists(): void
    {
        $command = new HaoCodeCommand;

        $match = $this->invoke($command, 'findHistoryMatch', [
            'alpha',
            'beta',
        ], 'gamma', '');

        $this->assertNull($match);
    }

    public function test_find_history_matches_returns_all_matches_newest_first(): void
    {
        $command = new HaoCodeCommand;

        $matches = $this->invoke($command, 'findHistoryMatches', [
            'git status',
            'php artisan test',
            'git diff --stat',
            'git log --oneline',
        ], 'git', '');

        $this->assertSame([
            'git log --oneline',
            'git diff --stat',
            'git status',
        ], $matches);
    }

    public function test_find_history_matches_skips_fallback_when_query_empty(): void
    {
        $command = new HaoCodeCommand;

        $matches = $this->invoke($command, 'findHistoryMatches', [
            'first',
            'second',
            'third',
        ], '', 'third');

        $this->assertSame(['second', 'first'], $matches);
    }

    public function test_build_permission_rule_uses_the_observable_tool_input(): void
    {
        $command = new HaoCodeCommand;

        $bashRule = $this->invoke($command, 'buildPermissionRule', 'Bash', [
            'command' => 'git status',
        ]);
        $writeRule = $this->invoke($command, 'buildPermissionRule', 'Write', [
            'file_path' => '/tmp/README.md',
        ]);

        $this->assertSame('Bash(git status)', $bashRule);
        $this->assertSame('Write(/tmp/README.md)', $writeRule);
    }

    public function test_matches_permission_rule_supports_exact_and_prefix_patterns(): void
    {
        $command = new HaoCodeCommand;

        $exact = $this->invoke($command, 'matchesPermissionRule', 'Write(/tmp/README.md)', 'Write', [
            'file_path' => '/tmp/README.md',
        ]);
        $prefix = $this->invoke($command, 'matchesPermissionRule', 'Bash(git:*)', 'Bash', [
            'command' => 'git status --short',
        ]);
        $nonMatch = $this->invoke($command, 'matchesPermissionRule', 'Bash(git:*)', 'Bash', [
            'command' => 'gitlint',
        ]);

        $this->assertTrue($exact);
        $this->assertTrue($prefix);
        $this->assertFalse($nonMatch);
    }

    public function test_normalize_config_key_accepts_claude_style_aliases(): void
    {
        $command = new HaoCodeCommand;

        $permission = $this->invoke($command, 'normalizeConfigKey', 'permission-mode');
        $outputStyle = $this->invoke($command, 'normalizeConfigKey', 'output-style');
        $api = $this->invoke($command, 'normalizeConfigKey', 'api');

        $this->assertSame('permission_mode', $permission);
        $this->assertSame('output_style', $outputStyle);
        $this->assertSame('api_base_url', $api);
    }

    public function test_extract_context_files_deduplicates_and_relativizes_paths(): void
    {
        $command = new HaoCodeCommand;
        $cwd = getcwd();

        $messages = [
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'name' => 'Read',
                        'input' => ['file_path' => $cwd.'/app/Console/Commands/HaoCodeCommand.php'],
                    ],
                    [
                        'type' => 'tool_use',
                        'name' => 'Edit',
                        'input' => ['file_path' => './README.md'],
                    ],
                    [
                        'type' => 'tool_use',
                        'name' => 'Read',
                        'input' => ['file_path' => $cwd.'/app/Console/Commands/HaoCodeCommand.php'],
                    ],
                ],
            ],
        ];

        $files = $this->invoke($command, 'extractContextFiles', $messages);

        $this->assertSame([
            'app/Console/Commands/HaoCodeCommand.php',
            'README.md',
        ], $files);
    }

    public function test_tokenize_arguments_preserves_quoted_segments(): void
    {
        $command = new HaoCodeCommand;

        $tokens = $this->invoke($command, 'tokenizeArguments', 'add sentry "npx -y @acme/server" --scope global');

        $this->assertSame(['add', 'sentry', 'npx -y @acme/server', '--scope', 'global'], $tokens);
    }

    public function test_parse_long_options_separates_positionals_and_options(): void
    {
        $command = new HaoCodeCommand;

        [$options, $positionals] = $this->invoke($command, 'parseLongOptions', [
            'demo',
            'https://example.test/mcp',
            '--scope=global',
            '--transport',
            'http',
            '--env',
            'TOKEN=abc',
        ]);

        $this->assertSame(['demo', 'https://example.test/mcp'], $positionals);
        $this->assertSame(['global'], $options['scope']);
        $this->assertSame(['http'], $options['transport']);
        $this->assertSame(['TOKEN=abc'], $options['env']);
    }

    public function test_choose_startup_prompt_prefers_print_option_then_prompt_then_argument(): void
    {
        $command = new HaoCodeCommand;

        $prompt = $this->invoke($command, 'chooseStartupPrompt', 'from-print', 'from-prompt', 'from-arg');
        $deprecated = $this->invoke($command, 'chooseStartupPrompt', null, 'from-prompt', 'from-arg');
        $argument = $this->invoke($command, 'chooseStartupPrompt', null, null, 'from-arg');

        $this->assertSame('from-print', $prompt);
        $this->assertSame('from-prompt', $deprecated);
        $this->assertSame('from-arg', $argument);
    }
}

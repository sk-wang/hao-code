<?php

namespace Tests\Unit;

use App\Console\Commands\HaoCodeCommand;
use App\Support\Terminal\DockedPromptScreen;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class HaoCodeCommandTest extends TestCase
{
    private function setPrivateProperty(object $target, string $property, mixed $value): void
    {
        $ref = new \ReflectionClass($target);
        $p = $ref->getProperty($property);
        $p->setAccessible(true);
        $p->setValue($target, $value);
    }

    private function invoke(object $target, string $method, mixed ...$args): mixed
    {
        $ref = new \ReflectionClass($target);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($target, ...$args);
    }

    private function withWorkingDirectory(string $directory, callable $callback): mixed
    {
        $original = getcwd();
        chdir($directory);

        try {
            return $callback();
        } finally {
            if ($original !== false) {
                chdir($original);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
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

        $this->assertSame('Bash(git:*)', $bashRule);
        $this->assertSame('Write(/tmp/*)', $writeRule);
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
        $streamOutput = $this->invoke($command, 'normalizeConfigKey', 'stream-output');
        $api = $this->invoke($command, 'normalizeConfigKey', 'api');

        $this->assertSame('permission_mode', $permission);
        $this->assertSame('output_style', $outputStyle);
        $this->assertSame('stream_output', $streamOutput);
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

    public function test_detect_project_info_recognizes_split_full_stack_project(): void
    {
        $root = sys_get_temp_dir() . '/haocode-fullstack-' . bin2hex(random_bytes(4));
        mkdir($root . '/backend', 0755, true);
        mkdir($root . '/frontend', 0755, true);

        file_put_contents($root . '/backend/artisan', "#!/usr/bin/env php\n");
        file_put_contents($root . '/backend/composer.json', json_encode([
            'require' => ['laravel/framework' => '^12.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/frontend/package.json', json_encode([
            'dependencies' => ['react' => '^19.0.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        file_put_contents($root . '/frontend/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");

        try {
            $command = new HaoCodeCommand;
            $info = $this->withWorkingDirectory(
                $root,
                fn () => $this->invoke($command, 'detectProjectInfo'),
            );

            $this->assertSame('Full-stack (Laravel + React/Next.js)', $info['framework']);
            $this->assertSame('(cd backend && php artisan test) && (cd frontend && pnpm test)', $info['test_command']);
            $this->assertSame('Composer + pnpm', $info['package_manager']);
            $this->assertSame('full-stack', $info['structure']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_render_docked_prompt_screen_delegates_to_the_active_docked_prompt_screen(): void
    {
        $command = new HaoCodeCommand;
        $spy = new class extends DockedPromptScreen
        {
            public array $renderCalls = [];

            public function __construct()
            {
                parent::__construct(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true), static fn (): int => 10);
            }

            public function render(array $suggestionLines, array $promptLines, int $cursorLineIndex, int $cursorColumn, array $hudLines): void
            {
                $this->renderCalls[] = [
                    'suggestionLines' => $suggestionLines,
                    'promptLines' => $promptLines,
                    'cursorLineIndex' => $cursorLineIndex,
                    'cursorColumn' => $cursorColumn,
                    'hudLines' => $hudLines,
                ];
            }
        };

        $this->invoke(
            $command,
            'renderDockedPromptScreen',
            $spy,
            ['suggestion one'],
            ['prompt line'],
            0,
            7,
            ['hud line 1', 'hud line 2'],
        );

        $this->assertCount(1, $spy->renderCalls);
        $this->assertSame(['suggestion one'], $spy->renderCalls[0]['suggestionLines']);
        $this->assertSame(['prompt line'], $spy->renderCalls[0]['promptLines']);
        $this->assertSame(0, $spy->renderCalls[0]['cursorLineIndex']);
        $this->assertSame(7, $spy->renderCalls[0]['cursorColumn']);
        $this->assertSame(['hud line 1', 'hud line 2'], $spy->renderCalls[0]['hudLines']);
    }

    public function test_clear_docked_prompt_screen_clears_the_active_screen(): void
    {
        $command = new HaoCodeCommand;
        $spy = new class extends DockedPromptScreen
        {
            public int $clearCalls = 0;

            public function __construct()
            {
                parent::__construct(new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, true), static fn (): int => 10);
            }

            public function clear(): void
            {
                $this->clearCalls++;
            }
        };

        $this->setPrivateProperty($command, 'dockedPromptScreen', $spy);

        $this->invoke($command, 'clearDockedPromptScreen');

        $this->assertSame(1, $spy->clearCalls);
    }

    public function test_load_input_history_reads_json_entries_and_preserves_multiline_values(): void
    {
        $command = new HaoCodeCommand;
        $directory = sys_get_temp_dir() . '/haocode-history-' . bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);
        $historyFile = $directory . '/input_history.json';
        file_put_contents($historyFile, json_encode([
            'alpha',
            "beta\ngamma",
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $history = $this->invoke($command, 'loadInputHistory', $historyFile, $directory . '/legacy.txt');

            $this->assertSame(['alpha', "beta\ngamma"], $history);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_load_input_history_falls_back_to_legacy_plain_text_history(): void
    {
        $command = new HaoCodeCommand;
        $directory = sys_get_temp_dir() . '/haocode-history-' . bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);
        $legacyHistoryFile = $directory . '/input_history';
        file_put_contents($legacyHistoryFile, "alpha\nbeta\n");

        try {
            $history = $this->invoke($command, 'loadInputHistory', $directory . '/missing.json', $legacyHistoryFile);

            $this->assertSame(['alpha', 'beta'], $history);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_save_input_history_writes_json_for_multiline_entries(): void
    {
        $command = new HaoCodeCommand;
        $directory = sys_get_temp_dir() . '/haocode-history-' . bin2hex(random_bytes(4));
        mkdir($directory, 0755, true);
        $historyFile = $directory . '/input_history.json';

        try {
            $this->invoke($command, 'saveInputHistory', $historyFile, [
                'alpha',
                "beta\ngamma",
            ]);

            $decoded = json_decode((string) file_get_contents($historyFile), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['alpha', "beta\ngamma"], $decoded);
        } finally {
            $this->removeDirectory($directory);
        }
    }
}

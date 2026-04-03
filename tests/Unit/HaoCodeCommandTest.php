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
}

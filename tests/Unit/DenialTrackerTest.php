<?php

namespace Tests\Unit;

use App\Services\Permissions\DenialTracker;
use PHPUnit\Framework\TestCase;

class DenialTrackerTest extends TestCase
{
    public function test_count_starts_at_zero(): void
    {
        $tracker = new DenialTracker;
        $this->assertSame(0, $tracker->count());
    }

    public function test_record_increments_count(): void
    {
        $tracker = new DenialTracker;
        $tracker->record('Bash', 'rm -rf /', 'dangerous');
        $this->assertSame(1, $tracker->count());
    }

    public function test_clear_resets_count(): void
    {
        $tracker = new DenialTracker;
        $tracker->record('Bash', 'rm -rf /', 'dangerous');
        $tracker->clear();
        $this->assertSame(0, $tracker->count());
    }

    public function test_get_recent_returns_most_recent_first(): void
    {
        $tracker = new DenialTracker;
        $tracker->record('Bash', 'cmd_1', 'r1');
        $tracker->record('Bash', 'cmd_2', 'r2');

        $recent = $tracker->getRecent(2);
        $this->assertSame('cmd_2', $recent[0]['command']);
        $this->assertSame('cmd_1', $recent[1]['command']);
    }

    public function test_get_recent_respects_limit(): void
    {
        $tracker = new DenialTracker;
        for ($i = 0; $i < 5; $i++) {
            $tracker->record('Bash', "cmd_{$i}", 'reason');
        }

        $this->assertCount(3, $tracker->getRecent(3));
    }

    public function test_should_force_ask_returns_false_below_threshold(): void
    {
        $tracker = new DenialTracker;
        $tracker->record('Bash', 'git push origin main', 'rule');
        $tracker->record('Bash', 'git push origin main', 'rule');

        // 2 denials < threshold of 3
        $this->assertFalse($tracker->shouldForceAsk('Bash', 'git push origin main'));
    }

    public function test_should_force_ask_returns_true_at_threshold(): void
    {
        $tracker = new DenialTracker;
        for ($i = 0; $i < 3; $i++) {
            $tracker->record('Bash', 'git push origin main', 'rule');
        }

        $this->assertTrue($tracker->shouldForceAsk('Bash', 'git push origin main'));
    }

    public function test_should_force_ask_only_counts_matching_tool(): void
    {
        $tracker = new DenialTracker;
        for ($i = 0; $i < 3; $i++) {
            $tracker->record('Write', 'git push origin main', 'rule');
        }

        // Different tool — should not count
        $this->assertFalse($tracker->shouldForceAsk('Bash', 'git push origin main'));
    }

    public function test_ring_buffer_trims_to_max_denials(): void
    {
        $tracker = new DenialTracker;
        // MAX_DENIALS = 20 — add 25
        for ($i = 0; $i < 25; $i++) {
            $tracker->record('Bash', "cmd_{$i}", 'reason');
        }

        // Count should be capped at 20
        $this->assertSame(20, $tracker->count());
    }

    public function test_get_recent_entry_has_required_fields(): void
    {
        $tracker = new DenialTracker;
        $tracker->record('Read', '/etc/passwd', 'sensitive file');

        $entry = $tracker->getRecent(1)[0];
        $this->assertArrayHasKey('tool', $entry);
        $this->assertArrayHasKey('command', $entry);
        $this->assertArrayHasKey('reason', $entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertSame('Read', $entry['tool']);
    }

    public function test_should_force_ask_matches_when_new_command_is_longer(): void
    {
        // Stored: short "git push", new: longer "git push --force origin main"
        // str_starts_with("git push", "git push --force...") = FALSE without fix
        $tracker = new DenialTracker;
        for ($i = 0; $i < 3; $i++) {
            $tracker->record('Bash', 'git push', 'rule');
        }

        $this->assertTrue($tracker->shouldForceAsk('Bash', 'git push --force origin main'));
    }

    public function test_should_force_ask_matches_when_stored_command_is_longer(): void
    {
        $tracker = new DenialTracker;
        for ($i = 0; $i < 3; $i++) {
            $tracker->record('Bash', 'git push --force origin main', 'rule');
        }

        $this->assertTrue($tracker->shouldForceAsk('Bash', 'git push'));
    }

    public function test_should_force_ask_does_not_match_unrelated_commands(): void
    {
        $tracker = new DenialTracker;
        for ($i = 0; $i < 3; $i++) {
            $tracker->record('Bash', 'rm -rf /tmp', 'rule');
        }

        $this->assertFalse($tracker->shouldForceAsk('Bash', 'git push origin main'));
    }

    public function test_record_truncates_long_commands(): void
    {
        $tracker = new DenialTracker;
        $longCommand = str_repeat('x', 300);
        $tracker->record('Bash', $longCommand, 'reason');

        $entry = $tracker->getRecent(1)[0];
        $this->assertLessThanOrEqual(200, strlen($entry['command']));
    }
}

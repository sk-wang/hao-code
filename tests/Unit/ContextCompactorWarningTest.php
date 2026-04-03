<?php

namespace Tests\Unit;

use App\Services\Compact\ContextCompactor;
use App\Services\Agent\QueryEngine;
use PHPUnit\Framework\TestCase;

class ContextCompactorWarningTest extends TestCase
{
    /**
     * Constants mirrored from ContextCompactor (private).
     *
     * EFFECTIVE_CONTEXT_WINDOW = 200_000 - 20_000 = 180_000
     * WARNING_BUFFER_TOKENS    = 30_000  → isWarning  when tokens >= 150_000
     * ERROR_BUFFER_TOKENS      = 20_000  → isError    when tokens >= 160_000
     * BLOCKING_BUFFER_TOKENS   =  3_000  → isBlocking when tokens >= 177_000
     */
    private const EFFECTIVE = 180_000;
    private const WARNING_THRESHOLD = self::EFFECTIVE - 30_000; // 150_000
    private const ERROR_THRESHOLD   = self::EFFECTIVE - 20_000; // 160_000
    private const BLOCKING_THRESHOLD = self::EFFECTIVE - 3_000; // 177_000

    private function makeCompactor(): ContextCompactor
    {
        $queryEngine = $this->createMock(QueryEngine::class);
        return new ContextCompactor($queryEngine);
    }

    // ─── percentUsed ──────────────────────────────────────────────────────

    public function test_percent_used_at_zero_tokens(): void
    {
        $state = $this->makeCompactor()->getWarningState(0);
        $this->assertSame(0.0, $state['percentUsed']);
    }

    public function test_percent_used_at_full_window(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::EFFECTIVE);
        $this->assertSame(100.0, $state['percentUsed']);
    }

    public function test_percent_used_at_half_window(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::EFFECTIVE / 2);
        $this->assertSame(50.0, $state['percentUsed']);
    }

    // ─── no warnings below threshold ──────────────────────────────────────

    public function test_no_warning_when_well_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(100_000);

        $this->assertFalse($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);
        $this->assertNull($state['message']);
    }

    // ─── warning tier (isWarning) ─────────────────────────────────────────

    public function test_is_warning_just_at_warning_threshold(): void
    {
        // exactly at threshold: remaining = 30_000 → isWarning
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertFalse($state['isError']);
        $this->assertFalse($state['isBlocking']);
        $this->assertNotNull($state['message']);
        $this->assertStringContainsString('%', $state['message']);
    }

    public function test_is_warning_just_below_warning_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD - 1);

        $this->assertFalse($state['isWarning']);
    }

    public function test_warning_message_mentions_auto_compact(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::WARNING_THRESHOLD);
        $this->assertStringContainsString('Auto-compact', $state['message']);
    }

    // ─── error tier (isError) ────────────────────────────────────────────

    public function test_is_error_just_at_error_threshold(): void
    {
        // remaining = 20_000 → isError
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertFalse($state['isBlocking']);
    }

    public function test_error_message_suggests_compact(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD);
        $this->assertStringContainsString('/compact', $state['message']);
        $this->assertStringContainsString('nearly full', $state['message']);
    }

    public function test_is_error_false_just_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::ERROR_THRESHOLD - 1);
        $this->assertFalse($state['isError']);
    }

    // ─── blocking tier (isBlocking) ───────────────────────────────────────

    public function test_is_blocking_at_blocking_threshold(): void
    {
        // remaining = 3_000 → isBlocking
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD);

        $this->assertTrue($state['isWarning']);
        $this->assertTrue($state['isError']);
        $this->assertTrue($state['isBlocking']);
    }

    public function test_blocking_message_says_critically_full(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD);
        $this->assertStringContainsString('critically full', $state['message']);
        $this->assertStringContainsString('/compact', $state['message']);
    }

    public function test_is_blocking_false_just_below_threshold(): void
    {
        $state = $this->makeCompactor()->getWarningState(self::BLOCKING_THRESHOLD - 1);
        $this->assertFalse($state['isBlocking']);
    }

    // ─── return shape ─────────────────────────────────────────────────────

    public function test_return_array_has_all_required_keys(): void
    {
        $state = $this->makeCompactor()->getWarningState(50_000);

        $this->assertArrayHasKey('percentUsed', $state);
        $this->assertArrayHasKey('isWarning', $state);
        $this->assertArrayHasKey('isError', $state);
        $this->assertArrayHasKey('isBlocking', $state);
        $this->assertArrayHasKey('message', $state);
    }

    public function test_percent_used_is_float(): void
    {
        $state = $this->makeCompactor()->getWarningState(90_000);
        $this->assertIsFloat($state['percentUsed']);
    }

    // ─── shouldAutoCompact ────────────────────────────────────────────────

    public function test_should_auto_compact_returns_false_below_threshold(): void
    {
        $compactor = $this->makeCompactor();
        // AUTO_COMPACT_THRESHOLD = 180_000 - 13_000 = 167_000
        $this->assertFalse($compactor->shouldAutoCompact(160_000));
    }

    public function test_should_auto_compact_returns_true_at_threshold(): void
    {
        $compactor = $this->makeCompactor();
        // Just above AUTO_COMPACT_THRESHOLD
        $this->assertTrue($compactor->shouldAutoCompact(168_000));
    }
}

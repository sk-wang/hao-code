<?php

namespace Tests\Unit;

use App\Tools\FileEdit\DiffGenerator;
use PHPUnit\Framework\TestCase;

class DiffGeneratorTest extends TestCase
{
    public function test_unified_diff_shows_changes(): void
    {
        $old = "line1\nline2\nline3\n";
        $new = "line1\nmodified\nline3\n";

        $diff = DiffGenerator::unifiedDiff($old, $new, 'test.txt');
        $this->assertStringContainsString('-line2', $diff);
        $this->assertStringContainsString('+modified', $diff);
    }

    public function test_unified_diff_empty_for_identical(): void
    {
        $content = "same content\n";
        $diff = DiffGenerator::unifiedDiff($content, $content);
        $this->assertSame('', $diff);
    }

    public function test_structured_patch_returns_hunks(): void
    {
        $old = "a\nb\nc\n";
        $new = "a\nB\nc\n";

        $hunks = DiffGenerator::structuredPatch($old, $new);
        $this->assertNotEmpty($hunks);
        $this->assertArrayHasKey('oldStart', $hunks[0]);
        $this->assertArrayHasKey('newStart', $hunks[0]);
        $this->assertArrayHasKey('lines', $hunks[0]);
    }

    public function test_change_summary_additions(): void
    {
        $old = "a\n";
        $new = "a\nb\nc\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('+2', $summary);
    }

    public function test_change_summary_deletions(): void
    {
        $old = "a\nb\nc\n";
        $new = "a\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('-2', $summary);
    }

    public function test_change_summary_modification(): void
    {
        $old = "line1\n";
        $new = "line2\n";

        $summary = DiffGenerator::changeSummary($old, $new);
        $this->assertStringContainsString('modified', $summary);
    }
}

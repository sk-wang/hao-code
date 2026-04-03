<?php

namespace Tests\Unit;

use App\Tools\ToolResult;
use PHPUnit\Framework\TestCase;

class ToolResultTest extends TestCase
{
    public function test_success_factory_sets_is_error_to_false(): void
    {
        $result = ToolResult::success('everything worked');

        $this->assertSame('everything worked', $result->output);
        $this->assertFalse($result->isError);
        $this->assertNull($result->metadata);
    }

    public function test_error_factory_sets_is_error_to_true(): void
    {
        $result = ToolResult::error('something went wrong');

        $this->assertSame('something went wrong', $result->output);
        $this->assertTrue($result->isError);
    }

    public function test_success_stores_metadata(): void
    {
        $result = ToolResult::success('ok', ['lines' => 42]);

        $this->assertSame(['lines' => 42], $result->metadata);
    }

    public function test_to_api_format_produces_correct_structure(): void
    {
        $result = ToolResult::success('file content here');

        $api = $result->toApiFormat('toolu_abc123');

        $this->assertSame([
            'type'        => 'tool_result',
            'tool_use_id' => 'toolu_abc123',
            'content'     => 'file content here',
            'is_error'    => false,
        ], $api);
    }

    public function test_to_api_format_includes_is_error_true_for_errors(): void
    {
        $result = ToolResult::error('permission denied');

        $api = $result->toApiFormat('toolu_xyz');

        $this->assertTrue($api['is_error']);
    }
}

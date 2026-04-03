<?php

namespace Tests\Feature;

use App\Tools\ToolInputSchema;
use Tests\TestCase;

class ToolInputSchemaTest extends TestCase
{
    // ─── make / toJsonSchema ──────────────────────────────────────────────

    public function test_to_json_schema_returns_schema(): void
    {
        $schema = ToolInputSchema::make([
            'type' => 'object',
            'properties' => ['q' => ['type' => 'string']],
            'required' => ['q'],
        ]);

        $raw = $schema->toJsonSchema();
        $this->assertSame('object', $raw['type']);
        $this->assertArrayHasKey('q', $raw['properties']);
    }

    public function test_make_stores_json_schema(): void
    {
        $def = ['type' => 'object', 'properties' => []];
        $schema = ToolInputSchema::make($def);
        $this->assertSame($def, $schema->toJsonSchema());
    }

    // ─── validate — no rules passes through ───────────────────────────────

    public function test_validate_with_no_rules_returns_input(): void
    {
        $schema = ToolInputSchema::make(['type' => 'object'], []);
        $input = ['anything' => 'goes'];
        $this->assertSame($input, $schema->validate($input));
    }

    // ─── validate — with rules ────────────────────────────────────────────

    public function test_validate_required_field_passes(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['query' => 'required|string'],
        );
        $result = $schema->validate(['query' => 'hello']);
        $this->assertSame('hello', $result['query']);
    }

    public function test_validate_missing_required_field_throws(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['query' => 'required|string'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/validation failed/i');
        $schema->validate([]);
    }

    public function test_validate_wrong_type_throws(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 'not-an-int']);
    }

    public function test_validate_min_rule_enforced(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer|min:1'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 0]);
    }

    public function test_validate_max_rule_enforced(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['count' => 'required|integer|max:100'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['count' => 200]);
    }

    public function test_validate_nullable_field_allows_null(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['format' => 'nullable|string'],
        );

        $result = $schema->validate(['format' => null]);
        $this->assertNull($result['format']);
    }

    public function test_validate_in_rule_accepts_valid_value(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['mode' => 'required|string|in:fast,slow'],
        );

        $result = $schema->validate(['mode' => 'fast']);
        $this->assertSame('fast', $result['mode']);
    }

    public function test_validate_in_rule_rejects_invalid_value(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['mode' => 'required|string|in:fast,slow'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['mode' => 'turbo']);
    }

    public function test_validate_url_rule_accepts_valid_url(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['url' => 'required|url'],
        );

        $result = $schema->validate(['url' => 'https://example.com']);
        $this->assertSame('https://example.com', $result['url']);
    }

    public function test_validate_url_rule_rejects_non_url(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['url' => 'required|url'],
        );

        $this->expectException(\InvalidArgumentException::class);
        $schema->validate(['url' => 'not-a-url']);
    }

    public function test_validate_exception_message_mentions_first_error(): void
    {
        $schema = ToolInputSchema::make(
            ['type' => 'object'],
            ['name' => 'required|string', 'age' => 'required|integer'],
        );

        try {
            $schema->validate([]);
            $this->fail('Expected exception');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('validation failed', strtolower($e->getMessage()));
        }
    }
}

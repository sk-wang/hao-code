<?php

namespace Tests\Unit;

use App\Tools\WebFetch\WebFetchTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class WebFetchToolTest extends TestCase
{
    private WebFetchTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new WebFetchTool;
        $this->ref = new \ReflectionClass($this->tool);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    private function htmlToText(string $html): string
    {
        $m = $this->ref->getMethod('htmlToText');
        $m->setAccessible(true);
        return $m->invoke($this->tool, $html);
    }

    // ─── name / description / isReadOnly ─────────────────────────────────

    public function test_name_is_web_fetch(): void
    {
        $this->assertSame('WebFetch', $this->tool->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_description_mentions_url(): void
    {
        $this->assertStringContainsString('URL', $this->tool->description());
    }

    // ─── htmlToText ───────────────────────────────────────────────────────

    public function test_strips_script_tags_and_content(): void
    {
        $html = '<html><script>alert("evil")</script><p>Good content</p></html>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('alert', $text);
        $this->assertStringContainsString('Good content', $text);
    }

    public function test_strips_style_tags_and_content(): void
    {
        $html = '<html><style>.foo { color: red; }</style><p>Text here</p></html>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('color', $text);
        $this->assertStringContainsString('Text here', $text);
    }

    public function test_converts_br_to_newline(): void
    {
        $html = 'Line one<br>Line two<br/>Line three';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString("\n", $text);
        $this->assertStringContainsString('Line one', $text);
        $this->assertStringContainsString('Line two', $text);
    }

    public function test_converts_closing_p_to_double_newline(): void
    {
        $html = '<p>First</p><p>Second</p>';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString('First', $text);
        $this->assertStringContainsString('Second', $text);
    }

    public function test_strips_all_html_tags(): void
    {
        $html = '<div><h1>Title</h1><p>Body <strong>text</strong></p></div>';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('<', $text);
        $this->assertStringNotContainsString('>', $text);
        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('Body', $text);
    }

    public function test_decodes_html_entities(): void
    {
        $html = '<p>Tom &amp; Jerry &lt;mice&gt;</p>';
        $text = $this->htmlToText($html);
        $this->assertStringContainsString('Tom & Jerry', $text);
    }

    public function test_strips_html_comments(): void
    {
        $html = '<!-- This is a comment -->Visible text';
        $text = $this->htmlToText($html);
        $this->assertStringNotContainsString('This is a comment', $text);
        $this->assertStringContainsString('Visible text', $text);
    }

    public function test_collapses_multiple_newlines(): void
    {
        $html = '<p>A</p><p></p><p>B</p>';
        $text = $this->htmlToText($html);
        // Should not have 3+ consecutive newlines
        $this->assertSame(0, preg_match('/\n{3,}/', $text));
    }

    // ─── call — network failure ───────────────────────────────────────────

    public function test_call_returns_error_on_connection_failure(): void
    {
        $result = $this->tool->call(
            ['url' => 'https://localhost:19999/nonexistent'],
            $this->context,
        );
        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Failed to fetch', $result->output);
    }
}

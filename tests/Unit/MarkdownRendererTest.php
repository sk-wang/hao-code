<?php

namespace Tests\Unit;

use App\Support\Terminal\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    private MarkdownRenderer $r;

    protected function setUp(): void
    {
        $this->r = new MarkdownRenderer(80);
    }

    // ─── empty string ─────────────────────────────────────────────────────

    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', $this->r->render(''));
    }

    // ─── plain text ───────────────────────────────────────────────────────

    public function test_plain_paragraph(): void
    {
        $result = $this->r->render('Hello world');
        $this->assertSame('Hello world', $result);
    }

    // ─── inline elements ─────────────────────────────────────────────────

    public function test_inline_code_wraps_in_yellow(): void
    {
        $result = $this->r->render('Use `echo hello`');
        $this->assertStringContainsString('<fg=yellow;options=bold>echo hello</>', $result);
    }

    public function test_bold_wraps_in_bold(): void
    {
        $result = $this->r->render('**important**');
        $this->assertStringContainsString('<options=bold>important</>', $result);
    }

    public function test_emphasis_wraps_in_underscore(): void
    {
        $result = $this->r->render('*emphasized*');
        $this->assertStringContainsString('<options=underscore>emphasized</>', $result);
    }

    public function test_link_shows_text_and_url(): void
    {
        $result = $this->r->render('[click](https://example.com)');
        $this->assertStringContainsString('<fg=blue;options=underscore>click</>', $result);
        $this->assertStringContainsString('https://example.com', $result);
    }

    // ─── headings ─────────────────────────────────────────────────────────

    public function test_h1_heading_with_cyan_bold(): void
    {
        $result = $this->r->render('# Title');
        $this->assertStringContainsString('<fg=cyan;options=bold>Title</>', $result);
    }

    public function test_h2_heading_with_separator(): void
    {
        $result = $this->r->render('## Section');
        $this->assertStringContainsString('Section', $result);
    }

    // ─── thematic break ───────────────────────────────────────────────────

    public function test_thematic_break_renders_horizontal_rule(): void
    {
        $result = $this->r->render("text\n\n---\n\nmore text");
        $this->assertStringContainsString('<fg=gray>────────────────────</>', $result);
    }

    // ─── code block ───────────────────────────────────────────────────────

    public function test_code_block_with_language_label(): void
    {
        $result = $this->r->render("```php\n\$x = 1;\n```");
        $this->assertStringContainsString('<fg=gray>php</>', $result);
        $this->assertStringContainsString('$x = 1;', $result);
    }

    public function test_code_block_without_language(): void
    {
        $result = $this->r->render("```\nplain code\n```");
        $this->assertStringContainsString('plain code', $result);
    }

    // ─── blockquote ───────────────────────────────────────────────────────

    public function test_blockquote_has_pipe_prefix(): void
    {
        $result = $this->r->render('> quoted text');
        $this->assertStringContainsString('<fg=gray>│</>', $result);
        $this->assertStringContainsString('quoted text', $result);
    }

    // ─── lists ────────────────────────────────────────────────────────────

    public function test_unordered_list_renders_items(): void
    {
        $result = $this->r->render("- item one\n- item two");
        $this->assertStringContainsString('item one', $result);
        $this->assertStringContainsString('item two', $result);
    }

    public function test_ordered_list_includes_numbers(): void
    {
        $result = $this->r->render("1. first\n2. second");
        $this->assertStringContainsString('1.', $result);
        $this->assertStringContainsString('first', $result);
    }

    // ─── multiple paragraphs ──────────────────────────────────────────────

    public function test_multiple_paragraphs_separated_by_blank_lines(): void
    {
        $result = $this->r->render("Para one.\n\nPara two.");
        $this->assertStringContainsString('Para one.', $result);
        $this->assertStringContainsString('Para two.', $result);
        // Paragraphs separated by blank line (double newline)
        $this->assertStringContainsString("\n\n", $result);
    }

    // ─── existing tests below ─────────────────────────────────────────────

    public function test_it_renders_headings_and_inline_markdown_for_the_terminal(): void
    {
        $renderer = new MarkdownRenderer(80);

        $rendered = $renderer->render("# Hello\n\nUse **bold**, *emphasis*, `code`, and [docs](https://example.com).");

        $this->assertSame(
            "<fg=cyan;options=bold>Hello</>\n".
            "<fg=cyan>─────</>\n\n".
            "Use <options=bold>bold</>, <options=underscore>emphasis</>, <fg=yellow;options=bold>code</>, and <fg=blue;options=underscore>docs</> <fg=gray>(https://example.com)</>.",
            $rendered,
        );
    }

    public function test_it_renders_lists_blockquotes_and_code_blocks(): void
    {
        $renderer = new MarkdownRenderer(80);

        $rendered = $renderer->render("> note\n\n1. First\n2. Second\n\n```php\necho 1;\n```");

        $this->assertSame(
            "<fg=gray>│</> note\n\n".
            "1. First\n\n".
            "2. Second\n\n".
            "<fg=gray>php</>\n".
            "    <fg=yellow>echo 1;</>",
            $rendered,
        );
    }

    public function test_it_renders_tables_with_box_drawing_borders(): void
    {
        $renderer = new MarkdownRenderer(80);

        $rendered = $renderer->render("| Name | Qty |\n| --- | ---: |\n| apple | 2 |\n| pear | 12 |");

        $this->assertStringContainsString('┌───────┬─────┐', $rendered);
        $this->assertStringContainsString('│ <options=bold>Name</>  │ <options=bold>Qty</> │', $rendered);
        $this->assertStringContainsString('│ apple │   2 │', $rendered);
        $this->assertStringContainsString('│ pear  │  12 │', $rendered);
        $this->assertStringContainsString('└───────┴─────┘', $rendered);
    }

    public function test_it_falls_back_to_row_view_for_wide_tables(): void
    {
        $renderer = new MarkdownRenderer(24);

        $rendered = $renderer->render("| Name | Description |\n| --- | --- |\n| apple | very crisp fruit |\n");

        $this->assertSame(
            "<options=bold>Row 1</>\n".
            "  <options=bold>Name:</> apple\n".
            "  <options=bold>Description:</> very crisp fruit",
            $rendered,
        );
    }

    public function test_it_renders_header_only_tables_while_streaming_partial_markdown(): void
    {
        $renderer = new MarkdownRenderer(80);

        $rendered = $renderer->render("| Name | Qty |\n| --- | ---: |\n");

        $this->assertStringContainsString('┌──────┬─────┐', $rendered);
        $this->assertStringContainsString('│ <options=bold>Name</> │ <options=bold>Qty</> │', $rendered);
        $this->assertStringContainsString('└──────┴─────┘', $rendered);
    }
}

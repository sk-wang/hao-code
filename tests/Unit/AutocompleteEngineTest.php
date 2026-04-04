<?php

namespace Tests\Unit;

use App\Support\Terminal\Autocomplete\AutocompleteEngine;
use App\Support\Terminal\Autocomplete\CommandSuggestionProvider;
use App\Support\Terminal\Autocomplete\FilePathSuggestionProvider;
use PHPUnit\Framework\TestCase;

class AutocompleteEngineTest extends TestCase
{
    private AutocompleteEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new AutocompleteEngine();
    }

    // ─── Ghost Text ──────────────────────────────────────────────────────

    public function test_ghost_text_completes_slash_command(): void
    {
        $ghost = $this->engine->getGhostText('/hel');
        $this->assertSame('p', $ghost);
    }

    public function test_ghost_text_returns_null_for_complete_command(): void
    {
        $ghost = $this->engine->getGhostText('/help');
        $this->assertNull($ghost);
    }

    public function test_ghost_text_returns_null_for_empty_slash(): void
    {
        $ghost = $this->engine->getGhostText('/');
        $this->assertNull($ghost);
    }

    public function test_ghost_text_returns_null_for_non_slash(): void
    {
        $ghost = $this->engine->getGhostText('hello world');
        $this->assertNull($ghost);
    }

    public function test_ghost_text_completes_mid_input_slash(): void
    {
        $ghost = $this->engine->getGhostText('run /drea');
        $this->assertSame('m', $ghost);
    }

    public function test_ghost_text_completes_partial_command(): void
    {
        $ghost = $this->engine->getGhostText('/com');
        // Could match 'compact' or 'commit'-like
        $this->assertNotNull($ghost);
    }

    // ─── Suggestions ─────────────────────────────────────────────────────

    public function test_get_suggestions_returns_commands_for_slash(): void
    {
        $suggestions = $this->engine->getSuggestions('/h');
        $this->assertNotEmpty($suggestions);
        $this->assertSame('command', $suggestions[0]['type']);
    }

    public function test_get_suggestions_include_help(): void
    {
        $suggestions = $this->engine->getSuggestions('/hel');
        $labels = array_column($suggestions, 'label');
        $this->assertContains('/help', $labels);
    }

    public function test_get_suggestions_returns_empty_for_plain_text(): void
    {
        $suggestions = $this->engine->getSuggestions('hello world');
        $this->assertEmpty($suggestions);
    }

    public function test_get_suggestions_returns_files_for_at_prefix(): void
    {
        // This test depends on filesystem; test with current directory
        $suggestions = $this->engine->getSuggestions('@comp');
        // May or may not have matches depending on cwd, but should not error
        $this->assertIsArray($suggestions);
    }

    public function test_get_suggestions_limit(): void
    {
        $suggestions = $this->engine->getSuggestions('/');
        $this->assertLessThanOrEqual(5, count($suggestions));
    }

    public function test_get_live_suggestions_returns_commands_for_slash(): void
    {
        $suggestions = $this->engine->getLiveSuggestions('/h');
        $this->assertNotEmpty($suggestions);
        $this->assertSame('command', $suggestions[0]['type']);
    }

    public function test_get_live_suggestions_does_not_return_shell_history_for_plain_text(): void
    {
        $suggestions = $this->engine->getLiveSuggestions('git');
        $this->assertSame([], $suggestions);
    }

    // ─── Accept Ghost Text ───────────────────────────────────────────────

    public function test_accept_ghost_text_appends_to_input(): void
    {
        $result = $this->engine->acceptGhostText('/hel', 'p');
        $this->assertSame('/help', $result);
    }

    // ─── Accept Suggestion ───────────────────────────────────────────────

    public function test_accept_suggestion_replaces_partial_command(): void
    {
        $result = $this->engine->acceptSuggestion('/hel', '/help');
        $this->assertSame('/help', $result);
    }

    public function test_accept_suggestion_preserves_trailing_text(): void
    {
        $result = $this->engine->acceptSuggestion('/hel world', '/help');
        $this->assertSame('/help world', $result);
    }

    public function test_accept_suggestion_for_file_replaces_at_partial(): void
    {
        $result = $this->engine->acceptSuggestion('read @comp', '@composer.json');
        $this->assertSame('read @composer.json', $result);
    }

    // ─── Render ──────────────────────────────────────────────────────────

    public function test_render_suggestion_not_selected(): void
    {
        $rendered = $this->engine->renderSuggestion([
            'label' => '/help',
            'description' => 'show help',
            'type' => 'command',
        ]);
        $this->assertStringContainsString('/help', $rendered);
        $this->assertStringContainsString('show help', $rendered);
    }

    public function test_render_suggestion_selected(): void
    {
        $rendered = $this->engine->renderSuggestion([
            'label' => '/help',
            'description' => 'show help',
            'type' => 'command',
        ], selected: true);
        $this->assertStringContainsString('/help', $rendered);
    }

    public function test_render_ghost_text(): void
    {
        $rendered = $this->engine->renderGhostText('p');
        $this->assertStringContainsString('p', $rendered);
    }
}

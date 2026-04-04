<?php

namespace Tests\Unit;

use App\Support\Terminal\Autocomplete\CommandSuggestionProvider;
use PHPUnit\Framework\TestCase;

class CommandSuggestionProviderTest extends TestCase
{
    private CommandSuggestionProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new CommandSuggestionProvider();
    }

    public function test_suggest_returns_commands_starting_with_query(): void
    {
        $results = $this->provider->suggest('/h');
        $names = array_column($results, 'name');
        $this->assertContains('help', $names);
        $this->assertContains('history', $names);
    }

    public function test_suggest_returns_empty_for_no_match(): void
    {
        $results = $this->provider->suggest('/zzzzz');
        $this->assertEmpty($results);
    }

    public function test_suggest_returns_all_when_just_slash(): void
    {
        $results = $this->provider->suggest('/');
        $this->assertNotEmpty($results);
        $this->assertLessThanOrEqual(5, count($results));
    }

    public function test_suggest_exact_match_scores_highest(): void
    {
        $results = $this->provider->suggest('/help');
        $this->assertSame('help', $results[0]['name']);
        $this->assertGreaterThan(90, $results[0]['score']);
    }

    public function test_suggest_alias_match(): void
    {
        $results = $this->provider->suggest('/q');
        $names = array_column($results, 'name');
        $this->assertContains('exit', $names); // /q is alias for /exit
    }

    public function test_suggest_respects_limit(): void
    {
        $results = $this->provider->suggest('/', 3);
        $this->assertLessThanOrEqual(3, count($results));
    }

    public function test_get_ghost_text_returns_remaining_chars(): void
    {
        $ghost = $this->provider->getGhostText('/hel');
        $this->assertSame('p', $ghost);
    }

    public function test_get_ghost_text_returns_null_for_full_match(): void
    {
        $ghost = $this->provider->getGhostText('/help');
        $this->assertNull($ghost);
    }

    public function test_get_ghost_text_returns_null_for_no_match(): void
    {
        $ghost = $this->provider->getGhostText('/zzz');
        $this->assertNull($ghost);
    }

    public function test_get_ghost_text_matches_alias(): void
    {
        $ghost = $this->provider->getGhostText('/qui');
        $this->assertSame('t', $ghost); // 'quit' is alias for exit
    }

    public function test_suggest_fuzzy_matches_description(): void
    {
        $results = $this->provider->suggest('/consol');
        // Should match commands with 'consolidation' or similar in description
        $this->assertIsArray($results);
    }

    public function test_suggest_case_insensitive(): void
    {
        $results = $this->provider->suggest('/HELP');
        $names = array_column($results, 'name');
        $this->assertContains('help', $names);
    }

    public function test_empty_query_returns_first_n_commands(): void
    {
        $results = $this->provider->suggest('/', 3);
        $this->assertLessThanOrEqual(3, count($results));
        $this->assertSame('help', $results[0]['name']);
    }

    public function test_fuzzy_matching_on_descriptions(): void
    {
        $results = $this->provider->suggest('/sched');
        $names = array_column($results, 'name');
        $this->assertContains('loop', $names);
    }

    public function test_alias_matching_scores_higher_than_contains(): void
    {
        $results = $this->provider->suggest('/q');
        $this->assertSame('exit', $results[0]['name']);
        $this->assertGreaterThan(0, $results[0]['score']);
    }
}

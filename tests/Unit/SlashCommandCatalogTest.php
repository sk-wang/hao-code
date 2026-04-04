<?php

namespace Tests\Unit;

use App\Support\Terminal\Autocomplete\SlashCommandCatalog;
use PHPUnit\Framework\TestCase;

class SlashCommandCatalogTest extends TestCase
{
    public function test_all_returns_known_commands(): void
    {
        $catalog = new SlashCommandCatalog();

        $names = array_column($catalog->all(), 'name');

        $this->assertContains('help', $names);
        $this->assertContains('buddy', $names);
        $this->assertContains('output-style', $names);
        $this->assertContains('config', $names);
        $this->assertContains('provider', $names);
        $this->assertContains('hooks', $names);
        $this->assertContains('files', $names);
        $this->assertContains('plan', $names);
        $this->assertContains('mcp', $names);
        $this->assertContains('branch', $names);
        $this->assertContains('commit', $names);
        $this->assertContains('review', $names);
        $this->assertContains('stats', $names);
        $this->assertContains('statusline', $names);
    }

    public function test_resolve_matches_canonical_command(): void
    {
        $catalog = new SlashCommandCatalog();

        $resolved = $catalog->resolve('/help');

        $this->assertNotNull($resolved);
        $this->assertSame('help', $resolved['name']);
    }

    public function test_resolve_matches_alias(): void
    {
        $catalog = new SlashCommandCatalog();

        $resolved = $catalog->resolve('/perm');

        $this->assertNotNull($resolved);
        $this->assertSame('permissions', $resolved['name']);
    }

    public function test_resolve_matches_branch_alias(): void
    {
        $catalog = new SlashCommandCatalog();

        $resolved = $catalog->resolve('/fork');

        $this->assertNotNull($resolved);
        $this->assertSame('branch', $resolved['name']);
    }

    public function test_resolve_returns_null_for_unknown_command(): void
    {
        $catalog = new SlashCommandCatalog();

        $this->assertNull($catalog->resolve('/does-not-exist'));
    }
}

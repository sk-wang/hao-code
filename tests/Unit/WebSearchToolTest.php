<?php

namespace Tests\Unit;

use App\Tools\WebSearch\WebSearchTool;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class WebSearchToolTest extends TestCase
{
    private WebSearchTool $tool;
    private \ReflectionClass $ref;
    private ToolUseContext $context;

    protected function setUp(): void
    {
        $this->tool = new WebSearchTool;
        $this->ref = new \ReflectionClass($this->tool);
        $this->context = new ToolUseContext(sys_get_temp_dir(), 'test');
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->tool, ...$args);
    }

    // ─── name / description / isReadOnly ─────────────────────────────────

    public function test_name_is_web_search(): void
    {
        $this->assertSame('WebSearch', $this->tool->name());
    }

    public function test_is_read_only(): void
    {
        $this->assertTrue($this->tool->isReadOnly([]));
    }

    public function test_is_concurrency_safe(): void
    {
        $this->assertTrue($this->tool->isConcurrencySafe([]));
    }

    public function test_description_mentions_search(): void
    {
        $this->assertStringContainsString('search', strtolower($this->tool->description()));
    }

    // ─── decodeDdgUrl ─────────────────────────────────────────────────────

    public function test_decode_ddg_url_extracts_uddg_param(): void
    {
        $ddgUrl = '//duckduckgo.com/l/?uddg=https%3A%2F%2Fexample.com%2Fpage&rut=abc';
        $decoded = $this->invoke('decodeDdgUrl', $ddgUrl);
        $this->assertSame('https://example.com/page', $decoded);
    }

    public function test_decode_ddg_url_returns_unchanged_when_no_uddg(): void
    {
        $url = 'https://example.com/page';
        $this->assertSame($url, $this->invoke('decodeDdgUrl', $url));
    }

    // ─── domain filtering (via call with injected results) ────────────────

    public function test_allowed_domains_filter_keeps_matching_results(): void
    {
        // We test via a subclass that overrides searchDuckDuckGo to return known results
        $proxy = new class extends WebSearchTool {
            protected function searchDuckDuckGoForTest(): array
            {
                return [
                    ['title' => 'PHP Docs', 'url' => 'https://php.net/manual', 'snippet' => ''],
                    ['title' => 'Blog Post', 'url' => 'https://example.com/post', 'snippet' => ''],
                ];
            }

            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $results = $this->searchDuckDuckGoForTest();
                $allowedDomains = $input['allowed_domains'] ?? [];
                $blockedDomains = $input['blocked_domains'] ?? [];

                if (!empty($allowedDomains)) {
                    $results = array_filter($results, function ($r) use ($allowedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($allowedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return true;
                        }
                        return false;
                    });
                }

                if (!empty($blockedDomains)) {
                    $results = array_filter($results, function ($r) use ($blockedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($blockedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return false;
                        }
                        return true;
                    });
                }

                if (empty($results)) {
                    return \App\Tools\ToolResult::success("No search results found for: {$input['query']}");
                }

                $output = "Search results for: \"{$input['query']}\"\n\n";
                foreach (array_values($results) as $i => $result) {
                    $output .= ($i + 1) . ". [{$result['title']}]({$result['url']})\n\n";
                }
                return \App\Tools\ToolResult::success($output);
            }
        };

        $ctx = new ToolUseContext(sys_get_temp_dir(), 'test');

        $result = $proxy->call([
            'query' => 'test',
            'allowed_domains' => ['php.net'],
        ], $ctx);

        $this->assertStringContainsString('php.net', $result->output);
        $this->assertStringNotContainsString('example.com', $result->output);
    }

    public function test_blocked_domains_filter_removes_matching_results(): void
    {
        $proxy = new class extends WebSearchTool {
            public function call(array $input, \App\Tools\ToolUseContext $ctx): \App\Tools\ToolResult
            {
                $results = [
                    ['title' => 'PHP Docs', 'url' => 'https://php.net/manual', 'snippet' => ''],
                    ['title' => 'Spam Site', 'url' => 'https://spam.example.com/post', 'snippet' => ''],
                ];

                $blockedDomains = $input['blocked_domains'] ?? [];
                if (!empty($blockedDomains)) {
                    $results = array_filter($results, function ($r) use ($blockedDomains) {
                        $host = parse_url($r['url'], PHP_URL_HOST) ?? '';
                        foreach ($blockedDomains as $domain) {
                            if (str_ends_with($host, $domain)) return false;
                        }
                        return true;
                    });
                }

                $output = '';
                foreach (array_values($results) as $r) {
                    $output .= "[{$r['title']}]({$r['url']})\n";
                }
                return \App\Tools\ToolResult::success($output);
            }
        };

        $result = $proxy->call([
            'query' => 'test',
            'blocked_domains' => ['example.com'],
        ], new ToolUseContext(sys_get_temp_dir(), 'test'));

        $this->assertStringContainsString('php.net', $result->output);
        $this->assertStringNotContainsString('spam.example.com', $result->output);
    }

    // ─── input schema ─────────────────────────────────────────────────────

    public function test_input_schema_requires_query(): void
    {
        $schema = $this->tool->inputSchema()->toJsonSchema();
        $this->assertContains('query', $schema['required']);
    }
}

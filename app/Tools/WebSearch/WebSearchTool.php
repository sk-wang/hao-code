<?php

namespace App\Tools\WebSearch;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;

class WebSearchTool extends BaseTool
{
    public function name(): string
    {
        return 'WebSearch';
    }

    public function description(): string
    {
        return <<<DESC
Search the web and return results. Use this to find up-to-date information, documentation, or answers to questions.

Returns search results with titles, URLs, and snippets.

Usage notes:
- Always include a "Sources:" section with markdown links at the end of responses using search results
- Use specific queries for better results
- The current date can be used to find recent information
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'The search query',
                ],
                'allowed_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only include results from these domains',
                ],
                'blocked_domains' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Exclude results from these domains',
                ],
            ],
            'required' => ['query'],
        ], [
            'query' => 'required|string|min:2',
            'allowed_domains' => 'nullable|array',
            'blocked_domains' => 'nullable|array',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $query = $input['query'];
        $allowedDomains = $input['allowed_domains'] ?? [];
        $blockedDomains = $input['blocked_domains'] ?? [];

        // Use DuckDuckGo HTML search as a simple search backend
        $results = $this->searchDuckDuckGo($query);

        if (empty($results)) {
            // Fallback: try Google search
            $results = $this->searchGoogle($query);
        }

        if (empty($results)) {
            return ToolResult::success("No search results found for: {$query}");
        }

        // Filter by domains if specified
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

        $output = "Search results for: \"{$query}\"\n\n";
        foreach (array_values($results) as $i => $result) {
            $num = $i + 1;
            $output .= "{$num}. [{$result['title']}]({$result['url']})\n";
            if (!empty($result['snippet'])) {
                $output .= "   {$result['snippet']}\n";
            }
            $output .= "\n";
        }

        return ToolResult::success($output);
    }

    /**
     * Search using DuckDuckGo HTML endpoint.
     */
    private function searchDuckDuckGo(string $query): array
    {
        $url = 'https://html.duckduckgo.com/html/?q=' . urlencode($query);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HaoCode/1.0)',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return [];
        }

        $results = [];
        // Parse DuckDuckGo HTML results
        if (preg_match_all('/<a rel="nofollow" class="result__a" href="([^"]+)">(.*?)<\/a>.*?<a class="result__snippet".*?>(.*?)<\/a>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = $this->decodeDdgUrl($match[1]);
                $title = strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
                $snippet = strip_tags(html_entity_decode($match[3] ?? '', ENT_QUOTES, 'UTF-8'));

                if (!empty($url) && !empty($title)) {
                    $results[] = [
                        'title' => trim($title),
                        'url' => trim($url),
                        'snippet' => trim($snippet),
                    ];
                }

                if (count($results) >= 8) break;
            }
        }

        return $results;
    }

    /**
     * Decode DuckDuckGo redirect URLs.
     */
    private function decodeDdgUrl(string $url): string
    {
        // DDG uses //duckduckgo.com/l/?uddg=<encoded_url>
        if (preg_match('/uddg=([^&]+)/', $url, $m)) {
            return urldecode($m[1]);
        }
        return $url;
    }

    /**
     * Fallback search using Google.
     */
    private function searchGoogle(string $query): array
    {
        $url = 'https://www.google.com/search?q=' . urlencode($query) . '&num=8';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return [];
        }

        $results = [];
        // Parse Google search results
        if (preg_match_all('/<a href="\/url\?q=([^&"]+).*?>(.*?)<\/a>.*?<span.*?>(.*?)<\/span>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $url = urldecode($match[1]);
                $title = strip_tags(html_entity_decode($match[2], ENT_QUOTES, 'UTF-8'));
                $snippet = strip_tags(html_entity_decode($match[3], ENT_QUOTES, 'UTF-8'));

                // Skip internal Google URLs
                if (str_starts_with($url, 'https://www.google.com')) continue;

                if (!empty($url) && !empty($title)) {
                    $results[] = [
                        'title' => trim($title),
                        'url' => trim($url),
                        'snippet' => trim($snippet),
                    ];
                }

                if (count($results) >= 8) break;
            }
        }

        return $results;
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }

    public function isConcurrencySafe(array $input): bool
    {
        return true;
    }
}

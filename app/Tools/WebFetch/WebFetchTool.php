<?php

namespace App\Tools\WebFetch;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;

class WebFetchTool extends BaseTool
{
    /** @var array<string, array{content: string, time: int}> */
    private static array $cache = [];

    private const CACHE_TTL = 900; // 15 minutes
    private const MAX_CONTENT_SIZE = 100000;

    public function name(): string
    {
        return 'WebFetch';
    }

    public function description(): string
    {
        return <<<DESC
Fetch content from a URL. Returns the page content as text/markdown.
Use this tool to read web pages, API documentation, or other online resources.
Supports an optional `prompt` parameter to extract specific information from the page.
Results are cached for 15 minutes.
DESC;
    }

    public function inputSchema(): ToolInputSchema
    {
        return ToolInputSchema::make([
            'type' => 'object',
            'properties' => [
                'url' => [
                    'type' => 'string',
                    'description' => 'The URL to fetch',
                ],
                'prompt' => [
                    'type' => 'string',
                    'description' => 'Optional prompt describing what information to extract from the page',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'markdown'],
                    'description' => 'Output format (default: text)',
                ],
            ],
            'required' => ['url'],
        ], [
            'url' => 'required|url',
            'prompt' => 'nullable|string',
            'format' => 'nullable|string|in:text,markdown',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $url = $input['url'];
        $prompt = $input['prompt'] ?? null;

        // Check cache
        $cacheKey = md5($url);
        if (isset(self::$cache[$cacheKey]) && (time() - self::$cache[$cacheKey]['time']) < self::CACHE_TTL) {
            $content = self::$cache[$cacheKey]['content'];
            $header = "[Cached result]\n";
        } else {
            try {
                $client = HttpClient::create(['timeout' => 30, 'max_duration' => 60]);
                $response = $client->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => 'HaoCode/1.0 (CLI Agent)',
                        'Accept' => 'text/html,text/plain,application/json,*/*',
                    ],
                    'max_redirects' => 5,
                ]);

                $statusCode = $response->getStatusCode();
                $content = $response->getContent(false);
                $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
                $finalUrl = $response->getInfo('url') ?? $url;

                if ($statusCode >= 400) {
                    return ToolResult::error("HTTP {$statusCode} for URL: {$url}");
                }

                $header = '';
                if ($finalUrl !== $url) {
                    $header .= "[Redirected to: {$finalUrl}]\n";
                }

                // Strip HTML tags for basic text extraction
                if (str_contains($contentType, 'text/html')) {
                    $content = $this->htmlToText($content);
                }

                // Cache the result
                self::$cache[$cacheKey] = ['content' => $content, 'time' => time()];
            } catch (\Throwable $e) {
                return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
            }
        }

        // Truncate very large responses
        if (mb_strlen($content) > self::MAX_CONTENT_SIZE) {
            $content = mb_substr($content, 0, self::MAX_CONTENT_SIZE) . "\n\n[Content truncated at " . self::MAX_CONTENT_SIZE . " characters]";
        }

        $result = $header . $content;

        if ($prompt !== null) {
            $result = "[Extraction prompt: {$prompt}]\n\n" . $result;
        }

        return ToolResult::success($result);
    }

    private function htmlToText(string $html): string
    {
        // Remove scripts, styles, and HTML comments
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/si', '', $html);
        $html = preg_replace('/<footer[^>]*>.*?<\/footer>/si', '', $html);

        // Convert headings to markdown-style
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/si', "\n# $1\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/si', "\n## $1\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/si', "\n### $1\n", $html);
        $html = preg_replace('/<h[4-6][^>]*>(.*?)<\/h[4-6]>/si', "\n#### $1\n", $html);

        // Convert links to markdown
        $html = preg_replace('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html);

        // Convert common elements to text
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        $html = preg_replace('/<li[^>]*>/i', "- ", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);

        // Convert code blocks
        $html = preg_replace('/<pre[^>]*><code[^>]*>(.*?)<\/code><\/pre>/si', "\n```\n$1\n```\n", $html);
        $html = preg_replace('/<code[^>]*>(.*?)<\/code>/si', '`$1`', $html);

        // Bold and italic
        $html = preg_replace('/<(strong|b)[^>]*>(.*?)<\/(strong|b)>/si', '**$2**', $html);
        $html = preg_replace('/<(em|i)[^>]*>(.*?)<\/(em|i)>/si', '*$2*', $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim($text);
    }

    public function isReadOnly(array $input): bool
    {
        return true;
    }
}

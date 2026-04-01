<?php

namespace App\Tools\WebFetch;

use App\Tools\BaseTool;
use App\Tools\ToolInputSchema;
use App\Tools\ToolResult;
use App\Tools\ToolUseContext;
use Symfony\Component\HttpClient\HttpClient;

class WebFetchTool extends BaseTool
{
    public function name(): string
    {
        return 'WebFetch';
    }

    public function description(): string
    {
        return <<<DESC
Fetch content from a URL. Returns the page content as text/markdown.
Use this tool to read web pages, API documentation, or other online resources.
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
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'markdown'],
                    'description' => 'Output format (default: text)',
                ],
            ],
            'required' => ['url'],
        ], [
            'url' => 'required|url',
            'format' => 'nullable|string|in:text,markdown',
        ]);
    }

    public function call(array $input, ToolUseContext $context): ToolResult
    {
        $url = $input['url'];

        try {
            $client = HttpClient::create(['timeout' => 30, 'max_duration' => 60]);
            $response = $client->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'HaoCode/1.0 (CLI Agent)',
                    'Accept' => 'text/html,text/plain,application/json,*/*',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $contentType = $response->getHeaders()['content-type'][0] ?? '';

            if ($statusCode >= 400) {
                return ToolResult::error("HTTP {$statusCode} for URL: {$url}");
            }

            // Strip HTML tags for basic text extraction
            if (str_contains($contentType, 'text/html')) {
                $content = $this->htmlToText($content);
            }

            // Truncate very large responses
            if (mb_strlen($content) > 50000) {
                $content = mb_substr($content, 0, 50000) . "\n\n[Content truncated at 50000 characters]";
            }

            return ToolResult::success($content);
        } catch (\Throwable $e) {
            return ToolResult::error("Failed to fetch URL: {$e->getMessage()}");
        }
    }

    private function htmlToText(string $html): string
    {
        // Remove scripts, styles, and HTML comments
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);
        $html = preg_replace('/<!--.*?-->/s', '', $html);

        // Convert common elements to text
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/h[1-6]>/i', "\n\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);
        $html = preg_replace('/<\/div>/i', "\n", $html);

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

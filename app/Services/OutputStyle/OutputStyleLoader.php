<?php

namespace App\Services\OutputStyle;

/**
 * Load custom output style instructions from Markdown files.
 *
 * Mirrors claude-code's output-styles system: scans for *.md files in
 * ~/.haocode/output-styles/ (user) and .haocode/output-styles/ (project),
 * parses optional YAML-like frontmatter, and returns the active style's
 * content to be injected into the system prompt.
 *
 * Frontmatter fields (all optional):
 *   name:        display name (defaults to filename without .md)
 *   description: one-line summary shown in /output-style list
 */
class OutputStyleLoader
{
    /** @var array<string, array{name: string, description: string, content: string, path: string}>|null */
    private ?array $cachedStyles = null;

    /**
     * Return all discovered output styles, keyed by slug.
     */
    public function listStyles(): array
    {
        return $this->loadStyles();
    }

    /**
     * Return the body of the active output style, or null if none is set.
     */
    public function getActiveStyleContent(string $activeSlug): ?string
    {
        $styles = $this->loadStyles();
        return $styles[$activeSlug]['content'] ?? null;
    }

    private function loadStyles(): array
    {
        if ($this->cachedStyles !== null) {
            return $this->cachedStyles;
        }

        $dirs = [
            ($_SERVER['HOME'] ?? '') . '/.haocode/output-styles',
            getcwd() . '/.haocode/output-styles',
        ];

        $styles = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            foreach (glob($dir . '/*.md') ?: [] as $path) {
                $raw = file_get_contents($path);
                if ($raw === false) {
                    continue;
                }

                [$meta, $body] = $this->parseFrontmatter($raw);
                $filename = pathinfo($path, PATHINFO_FILENAME);
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $filename));

                $styles[$slug] = [
                    'name' => $meta['name'] ?? $filename,
                    'description' => $meta['description'] ?? '',
                    'content' => trim($body),
                    'path' => $path,
                ];
            }
        }

        $this->cachedStyles = $styles;
        return $styles;
    }

    /**
     * Parse optional YAML-like frontmatter (--- ... ---) from a markdown string.
     *
     * @return array{0: array<string,string>, 1: string}  [meta, body]
     */
    private function parseFrontmatter(string $content): array
    {
        if (!str_starts_with(ltrim($content), '---')) {
            return [[], $content];
        }

        $lines = explode("\n", $content);
        $inFrontmatter = false;
        $metaLines = [];
        $bodyStart = null;

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($i === 0 && $trimmed === '---') {
                $inFrontmatter = true;
                continue;
            }
            if ($inFrontmatter && $trimmed === '---') {
                $bodyStart = $i + 1;
                break;
            }
            if ($inFrontmatter) {
                $metaLines[] = $line;
            }
        }

        // No closing delimiter found — treat as no frontmatter
        if ($bodyStart === null) {
            return [[], $content];
        }

        $meta = [];
        foreach ($metaLines as $line) {
            if (preg_match('/^(\w+)\s*:\s*(.+)$/', $line, $m)) {
                $meta[trim($m[1])] = trim($m[2]);
            }
        }

        $body = implode("\n", array_slice($lines, $bodyStart));

        return [$meta, $body];
    }
}

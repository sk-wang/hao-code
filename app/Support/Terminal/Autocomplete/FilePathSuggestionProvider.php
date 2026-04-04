<?php

declare(strict_types=1);

namespace App\Support\Terminal\Autocomplete;

/**
 * File/directory path completion.
 * Triggered by @ prefix or path-like tokens.
 */
class FilePathSuggestionProvider
{
    private int $maxResults = 8;
    private int $cacheTtlSeconds = 300; // 5 minutes
    /** @var array<string, array{time: int, entries: string[]}> */
    private array $directoryCache = [];

    /**
     * Get file/directory suggestions for a partial path.
     *
     * @return array<int, array{name: string, path: string, type: 'file'|'directory'}>
     */
    public function suggest(string $partial, ?string $baseDir = null): array
    {
        $baseDir = $baseDir ?? getcwd();
        $partial = trim($partial);

        if ($partial === '') {
            return $this->listDirectory($baseDir);
        }

        // Expand ~ to home directory
        if (str_starts_with($partial, '~')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/';
            $partial = $home . substr($partial, 1);
        }

        // Resolve the directory and prefix to match
        if (str_starts_with($partial, '/')) {
            // Absolute path
            if (is_dir($partial)) {
                $dir = $partial;
                $prefix = '';
            } else {
                $dir = dirname($partial);
                $prefix = basename($partial);
            }
        } else {
            // Relative path
            $resolved = $baseDir . '/' . $partial;
            if (is_dir($resolved)) {
                $dir = $resolved;
                $prefix = '';
            } else {
                $dir = dirname($resolved);
                $prefix = basename($resolved);
            }
        }

        if (!is_dir($dir)) {
            return [];
        }

        $entries = $this->scanDirectory($dir);
        $results = [];

        foreach ($entries as $entry) {
            if ($entry[0] === '.') {
                continue; // Skip hidden files
            }

            if ($prefix !== '' && !str_starts_with(strtolower($entry), strtolower($prefix))) {
                continue;
            }

            $fullPath = rtrim($dir, '/') . '/' . $entry;
            $isDir = is_dir($fullPath);

            $results[] = [
                'name' => $entry . ($isDir ? '/' : ''),
                'path' => $fullPath,
                'type' => $isDir ? 'directory' : 'file',
            ];

            if (count($results) >= $this->maxResults) {
                break;
            }
        }

        // Directories first, then files, alphabetical within each group
        usort($results, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $results;
    }

    /**
     * List directory contents.
     *
     * @return array<int, array{name: string, path: string, type: 'file'|'directory'}>
     */
    private function listDirectory(string $dir): array
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $entries = $this->scanDirectory($dir);
        $results = [];

        foreach ($entries as $entry) {
            if ($entry[0] === '.') {
                continue;
            }

            $fullPath = rtrim($dir, '/') . '/' . $entry;
            $isDir = is_dir($fullPath);

            $results[] = [
                'name' => $entry . ($isDir ? '/' : ''),
                'path' => $fullPath,
                'type' => $isDir ? 'directory' : 'file',
            ];

            if (count($results) >= $this->maxResults) {
                break;
            }
        }

        usort($results, function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'directory' ? -1 : 1;
            }
            return strcmp($a['name'], $b['name']);
        });

        return $results;
    }

    /**
     * Scan directory with caching.
     *
     * @return string[]
     */
    private function scanDirectory(string $dir): array
    {
        if (!is_dir($dir) || !is_readable($dir)) {
            return [];
        }

        $cacheKey = $dir;
        $now = time();

        if (isset($this->directoryCache[$cacheKey])) {
            $cached = $this->directoryCache[$cacheKey];
            if (($now - $cached['time']) < $this->cacheTtlSeconds) {
                return $cached['entries'];
            }
        }

        $entries = scandir($dir) ?: [];
        $entries = array_values(array_filter($entries, fn ($e) => $e !== '.' && $e !== '..'));

        $this->directoryCache[$cacheKey] = [
            'time' => $now,
            'entries' => $entries,
        ];

        return $entries;
    }

    /**
     * Check if input looks like a file path that should trigger completion.
     */
    public function isPathLike(string $token): bool
    {
        return str_starts_with($token, './')
            || str_starts_with($token, '../')
            || str_starts_with($token, '/')
            || str_starts_with($token, '~/');
    }
}

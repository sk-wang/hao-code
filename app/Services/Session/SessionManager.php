<?php

namespace App\Services\Session;

use App\Support\Config\Config;
use App\Support\Config\PathHelper;

class SessionManager
{
    private string $sessionId;
    private string $sessionPath;
    private ?string $title = null;

    public function __construct()
    {
        $this->sessionId = $this->generateSessionId();
        $this->sessionPath = Config::get('session_path', PathHelper::storagePath('app/haocode/sessions'));
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->recordEntry(['type' => 'session_title', 'title' => $title]);
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function switchToSession(string $sessionId, ?string $title = null): void
    {
        $this->sessionId = $sessionId;
        $this->title = $title;
    }

    /**
     * @return array{session_id: string, source_session_id: string, title: string}
     */
    public function branchSession(?string $customTitle = null): array
    {
        $sourceSessionId = $this->sessionId;
        $sourceEntries = $this->loadSession($sourceSessionId);

        if ($sourceEntries === []) {
            throw new \RuntimeException('No conversation to branch.');
        }

        $branchedSessionId = $this->generateSessionId();
        $branchTitle = $this->makeUniqueBranchTitle(
            $customTitle ?: $this->deriveBranchTitleBase($sourceEntries)
        );
        $now = date('c');
        $branchedEntries = [
            [
                'timestamp' => $now,
                'session_id' => $branchedSessionId,
                'type' => 'session_title',
                'title' => $branchTitle,
            ],
            [
                'timestamp' => $now,
                'session_id' => $branchedSessionId,
                'type' => 'session_branch',
                'source_session_id' => $sourceSessionId,
            ],
        ];

        foreach ($sourceEntries as $entry) {
            if (in_array($entry['type'] ?? null, ['session_title', 'session_branch'], true)) {
                continue;
            }

            $entry['session_id'] = $branchedSessionId;
            $branchedEntries[] = $entry;
        }

        $this->writeSessionEntries($branchedSessionId, $branchedEntries);
        $this->switchToSession($branchedSessionId, $branchTitle);

        return [
            'session_id' => $branchedSessionId,
            'source_session_id' => $sourceSessionId,
            'title' => $branchTitle,
        ];
    }

    /**
     * Extract the stored title from a list of JSONL entries.
     */
    public static function extractTitleFromEntries(array $entries): ?string
    {
        foreach ($entries as $entry) {
            if (($entry['type'] ?? '') === 'session_title') {
                return $entry['title'] ?? null;
            }
        }
        return null;
    }

    /**
     * Record an entry to the session transcript (JSONL format).
     */
    public function recordEntry(array $entry): void
    {
        if (!is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }

        $line = json_encode(
            array_merge(
                ['timestamp' => date('c'), 'session_id' => $this->sessionId, 'cwd' => getcwd() ?: null],
                $entry
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";

        file_put_contents($this->getFilePath(), $line, FILE_APPEND);
    }

    /**
     * Record a complete turn (user message → assistant response → tool results).
     */
    public function recordTurn(array $assistantMessage, array $toolResults): void
    {
        $this->recordEntry([
            'type' => 'assistant_turn',
            'message' => $assistantMessage,
            'tool_results' => $toolResults,
        ]);
    }

    public function recordUserMessage(string $text): void
    {
        $this->recordEntry([
            'type' => 'user_message',
            'content' => $text,
        ]);
    }

    /**
     * Load a previous session from transcript.
     */
    public function loadSession(string $sessionId): array
    {
        // Try exact match first (file format: {sessionId}.jsonl)
        $exactPath = $this->sessionPath . '/' . $sessionId . '.jsonl';
        $files = file_exists($exactPath) ? [$exactPath] : [];

        // Fallback to glob for partial ID matching
        if (empty($files)) {
            $pattern = $this->sessionPath . '/' . $sessionId . '*.jsonl';
            $files = glob($pattern);
        }

        if (empty($files)) {
            return [];
        }

        $lines = file($files[0]);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            if (trim($line)) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }
        }

        return $entries;
    }

    private function getFilePath(): string
    {
        return $this->sessionPath . '/' . $this->sessionId . '.jsonl';
    }

    public function findMostRecentSessionId(?string $cwd = null): ?string
    {
        $files = glob($this->sessionPath.'/*.jsonl') ?: [];
        if ($files === []) {
            return null;
        }

        $candidates = [];
        foreach ($files as $file) {
            $entries = $this->loadSession(basename($file, '.jsonl'));
            if ($entries === []) {
                continue;
            }

            $latestTimestamp = null;
            $matchesCwd = false;
            foreach ($entries as $entry) {
                $timestamp = $entry['timestamp'] ?? null;
                if (is_string($timestamp)) {
                    $latestTimestamp = $timestamp;
                }

                if ($cwd !== null && is_string($entry['cwd'] ?? null) && $entry['cwd'] === $cwd) {
                    $matchesCwd = true;
                }
            }

            $candidates[] = [
                'session_id' => basename($file, '.jsonl'),
                'timestamp' => $latestTimestamp ?? date('c', filemtime($file) ?: time()),
                'cwd_match' => $matchesCwd,
            ];
        }

        usort($candidates, static function (array $left, array $right): int {
            if ($left['cwd_match'] !== $right['cwd_match']) {
                return $left['cwd_match'] ? -1 : 1;
            }

            return strcmp($right['timestamp'], $left['timestamp']);
        });

        return $candidates[0]['session_id'] ?? null;
    }

    private function generateSessionId(): string
    {
        return date('Y-m-d_His') . '_' . bin2hex(random_bytes(4));
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function deriveBranchTitleBase(array $entries): string
    {
        $existingTitle = $this->title ?? self::extractTitleFromEntries($entries);
        if (is_string($existingTitle) && trim($existingTitle) !== '') {
            return trim($existingTitle);
        }

        foreach ($entries as $entry) {
            if (($entry['type'] ?? null) !== 'user_message') {
                continue;
            }

            $content = trim((string) ($entry['content'] ?? ''));
            if ($content !== '') {
                $singleLine = preg_replace('/\s+/', ' ', $content) ?: $content;

                return mb_substr($singleLine, 0, 100);
            }
        }

        return 'Branched conversation';
    }

    private function makeUniqueBranchTitle(string $baseTitle): string
    {
        $baseTitle = trim($baseTitle);
        $baseTitle = $baseTitle !== '' ? $baseTitle : 'Branched conversation';
        $candidate = "{$baseTitle} (Branch)";
        $existingTitles = $this->existingTitles();

        if (! in_array($candidate, $existingTitles, true)) {
            return $candidate;
        }

        $suffix = 2;
        while (in_array("{$baseTitle} (Branch {$suffix})", $existingTitles, true)) {
            $suffix++;
        }

        return "{$baseTitle} (Branch {$suffix})";
    }

    /**
     * @return array<int, string>
     */
    private function existingTitles(): array
    {
        $titles = [];

        foreach (glob($this->sessionPath.'/*.jsonl') ?: [] as $file) {
            $title = self::extractTitleFromEntries($this->loadSession(basename($file, '.jsonl')));
            if (is_string($title) && trim($title) !== '') {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     */
    private function writeSessionEntries(string $sessionId, array $entries): void
    {
        if (! is_dir($this->sessionPath)) {
            mkdir($this->sessionPath, 0755, true);
        }

        $lines = array_map(function (array $entry) use ($sessionId): string {
            $entry['session_id'] = $sessionId;
            $entry['timestamp'] = (string) ($entry['timestamp'] ?? date('c'));
            $entry['cwd'] = $entry['cwd'] ?? (getcwd() ?: null);

            return json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, $entries);

        file_put_contents($this->sessionPath.'/'.$sessionId.'.jsonl', implode("\n", $lines)."\n");
    }
}

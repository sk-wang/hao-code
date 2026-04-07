<?php

namespace App\Services\Session;

use App\Support\Config\Config;
use App\Support\Config\PathHelper;

final class SessionStatsService
{
    /**
     * @return array{
     *     sessions_count: int,
     *     sessions_today: int,
     *     active_days: int,
     *     total_user_messages: int,
     *     total_assistant_turns: int,
     *     total_tool_results: int,
     *     latest_activity: ?string,
     *     sessions: array<int, array{
     *         session_id: string,
     *         title: ?string,
     *         branch_source: ?string,
     *         user_messages: int,
     *         assistant_turns: int,
     *         tool_results: int,
     *         first_activity: ?string,
     *         last_activity: ?string,
     *         duration_seconds: int
     *     }>
     * }
     */
    public function getOverview(?string $currentSessionId = null): array
    {
        $sessionPath = Config::get('session_path', PathHelper::storagePath('app/haocode/sessions'));
        $today = date('Y-m-d');

        if (! is_dir($sessionPath)) {
            return [
                'sessions_count' => 0,
                'sessions_today' => 0,
                'active_days' => 0,
                'total_user_messages' => 0,
                'total_assistant_turns' => 0,
                'total_tool_results' => 0,
                'latest_activity' => null,
                'sessions' => [],
            ];
        }

        $files = glob($sessionPath.'/*.jsonl') ?: [];
        $sessions = [];
        $activeDays = [];
        $sessionsToday = 0;
        $latestActivity = null;
        $totalUserMessages = 0;
        $totalAssistantTurns = 0;
        $totalToolResults = 0;

        foreach ($files as $file) {
            $entries = $this->loadEntries($file);
            if ($entries === []) {
                continue;
            }

            $stats = $this->buildSessionStats($entries, basename($file, '.jsonl'));
            $sessions[] = $stats;

            $totalUserMessages += $stats['user_messages'];
            $totalAssistantTurns += $stats['assistant_turns'];
            $totalToolResults += $stats['tool_results'];

            if ($stats['last_activity'] !== null) {
                $day = substr($stats['last_activity'], 0, 10);
                $activeDays[$day] = true;

                if ($day === $today) {
                    $sessionsToday++;
                }

                if ($latestActivity === null || strcmp($stats['last_activity'], $latestActivity) > 0) {
                    $latestActivity = $stats['last_activity'];
                }
            }
        }

        usort($sessions, function (array $left, array $right) use ($currentSessionId): int {
            if ($currentSessionId !== null) {
                if ($left['session_id'] === $currentSessionId && $right['session_id'] !== $currentSessionId) {
                    return -1;
                }

                if ($right['session_id'] === $currentSessionId && $left['session_id'] !== $currentSessionId) {
                    return 1;
                }
            }

            return strcmp($right['last_activity'] ?? '', $left['last_activity'] ?? '');
        });

        return [
            'sessions_count' => count($sessions),
            'sessions_today' => $sessionsToday,
            'active_days' => count($activeDays),
            'total_user_messages' => $totalUserMessages,
            'total_assistant_turns' => $totalAssistantTurns,
            'total_tool_results' => $totalToolResults,
            'latest_activity' => $latestActivity,
            'sessions' => $sessions,
        ];
    }

    /**
     * @return array{
     *     session_id: string,
     *     title: ?string,
     *     branch_source: ?string,
     *     user_messages: int,
     *     assistant_turns: int,
     *     tool_results: int,
     *     first_activity: ?string,
     *     last_activity: ?string,
     *     duration_seconds: int
     * }
     */
    public function getSession(string $sessionId): array
    {
        $overview = $this->getOverview($sessionId);

        foreach ($overview['sessions'] as $session) {
            if ($session['session_id'] === $sessionId) {
                return $session;
            }
        }

        return [
            'session_id' => $sessionId,
            'title' => null,
            'branch_source' => null,
            'user_messages' => 0,
            'assistant_turns' => 0,
            'tool_results' => 0,
            'first_activity' => null,
            'last_activity' => null,
            'duration_seconds' => 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadEntries(string $file): array
    {
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $entries = [];

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array{
     *     session_id: string,
     *     title: ?string,
     *     branch_source: ?string,
     *     user_messages: int,
     *     assistant_turns: int,
     *     tool_results: int,
     *     first_activity: ?string,
     *     last_activity: ?string,
     *     duration_seconds: int
     * }
     */
    private function buildSessionStats(array $entries, string $fallbackSessionId): array
    {
        $title = SessionManager::extractTitleFromEntries($entries);
        $branchSource = null;
        $sessionId = $fallbackSessionId;
        $userMessages = 0;
        $assistantTurns = 0;
        $toolResults = 0;
        $timestamps = [];

        foreach ($entries as $entry) {
            $sessionId = (string) ($entry['session_id'] ?? $sessionId);

            $timestamp = $entry['timestamp'] ?? null;
            if (is_string($timestamp) && $timestamp !== '') {
                $timestamps[] = $timestamp;
            }

            $type = $entry['type'] ?? null;
            if ($type === 'user_message') {
                $userMessages++;
            } elseif ($type === 'assistant_turn') {
                $assistantTurns++;
                $toolResults += count($entry['tool_results'] ?? []);
            } elseif ($type === 'session_branch') {
                $branchSource = is_string($entry['source_session_id'] ?? null)
                    ? $entry['source_session_id']
                    : $branchSource;
            }
        }

        sort($timestamps);
        $firstActivity = $timestamps[0] ?? null;
        $lastActivity = $timestamps[array_key_last($timestamps)] ?? null;

        return [
            'session_id' => $sessionId,
            'title' => $title,
            'branch_source' => $branchSource,
            'user_messages' => $userMessages,
            'assistant_turns' => $assistantTurns,
            'tool_results' => $toolResults,
            'first_activity' => $firstActivity,
            'last_activity' => $lastActivity,
            'duration_seconds' => $this->durationInSeconds($firstActivity, $lastActivity),
        ];
    }

    private function durationInSeconds(?string $firstActivity, ?string $lastActivity): int
    {
        if ($firstActivity === null || $lastActivity === null) {
            return 0;
        }

        $first = strtotime($firstActivity);
        $last = strtotime($lastActivity);

        if ($first === false || $last === false) {
            return 0;
        }

        return max(0, $last - $first);
    }
}

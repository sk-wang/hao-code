<?php

namespace App\Tools\Cron;

/**
 * In-memory cron job store with optional disk persistence.
 */
class CronScheduler
{
    /** @var array<string, array> */
    private static array $jobs = [];

    public static function addJob(array $job): void
    {
        self::$jobs[$job['id']] = $job;
    }

    public static function removeJob(string $id): bool
    {
        if (!isset(self::$jobs[$id])) return false;
        unset(self::$jobs[$id]);

        // Also remove from disk if durable
        $path = ($_SERVER['HOME'] ?? '~') . '/.haocode/scheduled_tasks.json';
        if (file_exists($path)) {
            $jobs = json_decode(file_get_contents($path), true) ?: [];
            unset($jobs[$id]);
            file_put_contents($path, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        return true;
    }

    public static function getJob(string $id): ?array
    {
        return self::$jobs[$id] ?? null;
    }

    /**
     * @return array<string, array>
     */
    public static function getAllJobs(): array
    {
        return self::$jobs;
    }

    public static function loadDurableJobs(): int
    {
        $path = ($_SERVER['HOME'] ?? '~') . '/.haocode/scheduled_tasks.json';
        if (!file_exists($path)) return 0;

        $jobs = json_decode(file_get_contents($path), true) ?: [];
        $loaded = 0;
        $now = time();

        foreach ($jobs as $id => $job) {
            // Skip expired jobs (7-day auto-expiry)
            $created = strtotime($job['created_at'] ?? 'now');
            if ($now - $created > 7 * 86400) {
                unset($jobs[$id]);
                continue;
            }
            if ($job['status'] === 'active') {
                self::$jobs[$id] = $job;
                $loaded++;
            }
        }

        // Save cleaned list
        file_put_contents($path, json_encode($jobs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $loaded;
    }

    /**
     * Check if any jobs are due and return their prompts.
     * @return array<string, string> jobId => prompt
     */
    public static function checkDue(): array
    {
        $due = [];
        $now = new \DateTime();

        foreach (self::$jobs as $id => $job) {
            if ($job['status'] !== 'active') continue;

            if (self::isCronDue($job['cron'], $now)) {
                $lastFired = $job['last_fired'] ? strtotime($job['last_fired']) : 0;
                // Don't fire more than once per minute
                if (time() - $lastFired < 60) continue;

                $due[$id] = $job['prompt'];
                $job['last_fired'] = date('c');
                $job['fire_count'] = ($job['fire_count'] ?? 0) + 1;

                if (!$job['recurring']) {
                    $job['status'] = 'completed';
                }

                self::$jobs[$id] = $job;
            }
        }

        return $due;
    }

    private static function isCronDue(string $cron, \DateTime $now): bool
    {
        $parts = preg_split('/\s+/', trim($cron));
        if (count($parts) !== 5) return false;

        $minute = (int) $now->format('i');
        $hour = (int) $now->format('H');
        $dayOfMonth = (int) $now->format('j');
        $month = (int) $now->format('n');
        $dayOfWeek = (int) $now->format('w'); // 0=Sun

        return self::matchesField($parts[0], $minute, 0, 59)
            && self::matchesField($parts[1], $hour, 0, 23)
            && self::matchesField($parts[2], $dayOfMonth, 1, 31)
            && self::matchesField($parts[3], $month, 1, 12)
            && self::matchesField($parts[4], $dayOfWeek, 0, 6);
    }

    private static function matchesField(string $field, int $value, int $min, int $max): bool
    {
        if ($field === '*') return true;

        // */N pattern
        if (preg_match('/^\*\/(\d+)$/', $field, $m)) {
            $step = (int) $m[1];
            return $step > 0 && ($value - $min) % $step === 0;
        }

        // N pattern (exact value)
        if (is_numeric($field)) {
            return $value === (int) $field;
        }

        // N-M range
        if (preg_match('/^(\d+)-(\d+)$/', $field, $m)) {
            return $value >= (int) $m[1] && $value <= (int) $m[2];
        }

        // N,M list
        if (str_contains($field, ',')) {
            $values = array_map('intval', explode(',', $field));
            return in_array($value, $values);
        }

        return false;
    }
}

<?php

namespace Tests\Unit;

use App\Tools\Cron\CronScheduler;
use PHPUnit\Framework\TestCase;

class CronSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear static state before each test
        foreach (array_keys(CronScheduler::getAllJobs()) as $id) {
            CronScheduler::removeJob($id);
        }
    }

    private function makeJob(array $overrides = []): array
    {
        return array_merge([
            'id'         => uniqid('job_'),
            'cron'       => '* * * * *',
            'prompt'     => 'test prompt',
            'recurring'  => true,
            'status'     => 'active',
            'created_at' => date('c'),
            'last_fired' => null,
            'fire_count' => 0,
            'durable'    => false,
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // CRUD
    // ---------------------------------------------------------------

    public function test_add_and_get_job(): void
    {
        $job = $this->makeJob(['id' => 'abc123']);
        CronScheduler::addJob($job);

        $fetched = CronScheduler::getJob('abc123');
        $this->assertNotNull($fetched);
        $this->assertSame('test prompt', $fetched['prompt']);
    }

    public function test_get_job_returns_null_for_unknown_id(): void
    {
        $this->assertNull(CronScheduler::getJob('no_such_id'));
    }

    public function test_remove_job_returns_true(): void
    {
        $job = $this->makeJob(['id' => 'rm_me']);
        CronScheduler::addJob($job);

        $this->assertTrue(CronScheduler::removeJob('rm_me'));
        $this->assertNull(CronScheduler::getJob('rm_me'));
    }

    public function test_remove_job_returns_false_for_unknown_id(): void
    {
        $this->assertFalse(CronScheduler::removeJob('ghost'));
    }

    public function test_get_all_jobs_returns_all_registered_jobs(): void
    {
        CronScheduler::addJob($this->makeJob(['id' => 'j1']));
        CronScheduler::addJob($this->makeJob(['id' => 'j2']));

        $all = CronScheduler::getAllJobs();
        $this->assertArrayHasKey('j1', $all);
        $this->assertArrayHasKey('j2', $all);
    }

    // ---------------------------------------------------------------
    // checkDue — cron matching
    // ---------------------------------------------------------------

    private function makeJobDueAt(\DateTime $dt, array $overrides = []): array
    {
        $minute     = (int) $dt->format('i');
        $hour       = (int) $dt->format('H');
        $dayOfMonth = (int) $dt->format('j');
        $month      = (int) $dt->format('n');
        $dayOfWeek  = (int) $dt->format('w');

        return $this->makeJob(array_merge([
            'cron' => "{$minute} {$hour} {$dayOfMonth} {$month} {$dayOfWeek}",
        ], $overrides));
    }

    /**
     * Call the private isCronDue logic indirectly via checkDue,
     * injecting a job whose cron exactly matches $now.
     */
    private function assertIsDue(string $cron, \DateTime $now): void
    {
        $job = $this->makeJob(['id' => 'due_' . uniqid(), 'cron' => $cron]);
        CronScheduler::addJob($job);

        // Temporarily expose via reflection
        $ref = new \ReflectionClass(CronScheduler::class);
        $isCronDue = $ref->getMethod('isCronDue');
        $isCronDue->setAccessible(true);

        $this->assertTrue($isCronDue->invoke(null, $cron, $now), "Expected cron '{$cron}' to be due at {$now->format('Y-m-d H:i')}");
    }

    private function assertIsNotDue(string $cron, \DateTime $now): void
    {
        $ref = new \ReflectionClass(CronScheduler::class);
        $isCronDue = $ref->getMethod('isCronDue');
        $isCronDue->setAccessible(true);

        $this->assertFalse($isCronDue->invoke(null, $cron, $now), "Expected cron '{$cron}' to NOT be due at {$now->format('Y-m-d H:i')}");
    }

    public function test_wildcard_star_matches_every_value(): void
    {
        $now = new \DateTime('2025-01-15 14:37:00');
        $this->assertIsDue('* * * * *', $now);
    }

    public function test_exact_minute_and_hour_match(): void
    {
        $now = new \DateTime('2025-06-10 09:30:00');
        $this->assertIsDue('30 9 * * *', $now);
    }

    public function test_exact_minute_and_hour_no_match(): void
    {
        $now = new \DateTime('2025-06-10 09:31:00');
        $this->assertIsNotDue('30 9 * * *', $now);
    }

    public function test_step_pattern_matches_every_n_minutes(): void
    {
        $now = new \DateTime('2025-01-01 10:15:00');
        $this->assertIsDue('*/5 * * * *', $now); // 15 % 5 === 0
    }

    public function test_step_pattern_does_not_match_off_step(): void
    {
        $now = new \DateTime('2025-01-01 10:13:00');
        $this->assertIsNotDue('*/5 * * * *', $now); // 13 % 5 !== 0
    }

    public function test_range_pattern_matches_within_range(): void
    {
        $now = new \DateTime('2025-01-01 14:00:00');
        $this->assertIsDue('* 9-17 * * *', $now); // hour 14 in range 9-17
    }

    public function test_range_pattern_does_not_match_outside_range(): void
    {
        $now = new \DateTime('2025-01-01 08:00:00');
        $this->assertIsNotDue('* 9-17 * * *', $now); // hour 8 outside 9-17
    }

    public function test_list_pattern_matches_listed_values(): void
    {
        $now = new \DateTime('2025-01-01 12:00:00');
        $this->assertIsDue('* 8,12,18 * * *', $now); // hour 12 is in list
    }

    public function test_list_pattern_does_not_match_unlisted_value(): void
    {
        $now = new \DateTime('2025-01-01 11:00:00');
        $this->assertIsNotDue('* 8,12,18 * * *', $now);
    }

    public function test_day_of_week_field_matches(): void
    {
        $now = new \DateTime('2025-01-06 10:00:00'); // Monday = 1
        $this->assertIsDue('* * * * 1', $now);
    }

    public function test_invalid_cron_field_count_is_not_due(): void
    {
        $now = new \DateTime('2025-01-01 10:00:00');
        $this->assertIsNotDue('* * * *', $now); // only 4 fields
    }

    // ---------------------------------------------------------------
    // checkDue — job lifecycle
    // ---------------------------------------------------------------

    public function test_check_due_returns_due_job_prompt(): void
    {
        $job = $this->makeJob([
            'id'     => 'due_job',
            'cron'   => '* * * * *', // always due
            'prompt' => 'my scheduled task',
        ]);
        CronScheduler::addJob($job);

        $due = CronScheduler::checkDue();

        $this->assertArrayHasKey('due_job', $due);
        $this->assertSame('my scheduled task', $due['due_job']);
    }

    public function test_check_due_skips_inactive_jobs(): void
    {
        $job = $this->makeJob(['id' => 'inactive', 'status' => 'completed']);
        CronScheduler::addJob($job);

        $due = CronScheduler::checkDue();

        $this->assertArrayNotHasKey('inactive', $due);
    }

    public function test_check_due_does_not_fire_same_job_twice_within_one_minute(): void
    {
        $job = $this->makeJob([
            'id'         => 'once_per_min',
            'cron'       => '* * * * *',
            'last_fired' => date('c'), // just fired
        ]);
        CronScheduler::addJob($job);

        $due = CronScheduler::checkDue();

        $this->assertArrayNotHasKey('once_per_min', $due);
    }

    public function test_non_recurring_job_is_completed_after_firing(): void
    {
        $job = $this->makeJob([
            'id'        => 'one_shot',
            'cron'      => '* * * * *',
            'recurring' => false,
        ]);
        CronScheduler::addJob($job);

        CronScheduler::checkDue();

        $stored = CronScheduler::getJob('one_shot');
        $this->assertNotNull($stored);
        $this->assertSame('completed', $stored['status']);
    }

    public function test_recurring_job_remains_active_after_firing(): void
    {
        $job = $this->makeJob([
            'id'        => 'recurring_job',
            'cron'      => '* * * * *',
            'recurring' => true,
        ]);
        CronScheduler::addJob($job);

        CronScheduler::checkDue();

        $stored = CronScheduler::getJob('recurring_job');
        $this->assertNotNull($stored);
        $this->assertSame('active', $stored['status']);
    }

    public function test_fire_count_is_incremented(): void
    {
        $job = $this->makeJob(['id' => 'counter', 'cron' => '* * * * *']);
        CronScheduler::addJob($job);

        CronScheduler::checkDue();

        $stored = CronScheduler::getJob('counter');
        $this->assertSame(1, $stored['fire_count']);
    }

    // ---------------------------------------------------------------
    // loadDurableJobs — expiry logic
    // ---------------------------------------------------------------

    public function test_load_durable_jobs_skips_expired_entries(): void
    {
        $tmpDir = sys_get_temp_dir() . '/haocode_cron_test_' . getmypid();
        mkdir($tmpDir, 0755, true);
        $_SERVER['HOME'] = $tmpDir;

        $expiredCreatedAt = date('c', strtotime('-8 days'));
        $jobs = [
            'expired_job' => [
                'id'         => 'expired_job',
                'cron'       => '* * * * *',
                'prompt'     => 'expired',
                'status'     => 'active',
                'created_at' => $expiredCreatedAt,
                'last_fired' => null,
                'fire_count' => 0,
                'recurring'  => true,
                'durable'    => true,
            ],
        ];

        $dir = $tmpDir . '/.haocode';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/scheduled_tasks.json', json_encode($jobs));

        CronScheduler::loadDurableJobs();

        $this->assertNull(CronScheduler::getJob('expired_job'));

        // Clean up
        unlink($dir . '/scheduled_tasks.json');
        rmdir($dir);
        rmdir($tmpDir);
    }

    public function test_load_durable_jobs_loads_active_non_expired_entries(): void
    {
        $tmpDir = sys_get_temp_dir() . '/haocode_cron_test_' . getmypid();
        mkdir($tmpDir, 0755, true);
        $_SERVER['HOME'] = $tmpDir;

        $jobs = [
            'active_job' => [
                'id'         => 'active_job',
                'cron'       => '* * * * *',
                'prompt'     => 'still active',
                'status'     => 'active',
                'created_at' => date('c'),
                'last_fired' => null,
                'fire_count' => 0,
                'recurring'  => true,
                'durable'    => true,
            ],
        ];

        $dir = $tmpDir . '/.haocode';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/scheduled_tasks.json', json_encode($jobs));

        $loaded = CronScheduler::loadDurableJobs();

        $this->assertSame(1, $loaded);
        $this->assertNotNull(CronScheduler::getJob('active_job'));

        // Clean up
        unlink($dir . '/scheduled_tasks.json');
        rmdir($dir);
        rmdir($tmpDir);
    }

    // ─── durable one-shot persistence on fire ─────────────────────────────

    public function test_durable_one_shot_job_written_to_disk_as_completed_after_firing(): void
    {
        $tmpDir = sys_get_temp_dir() . '/cron_test_persist_' . getmypid();
        $dir = $tmpDir . '/.haocode';
        mkdir($dir, 0755, true);

        $diskPath = $dir . '/scheduled_tasks.json';
        $_SERVER['HOME'] = $tmpDir;

        $jobId = 'oneshot_durable';
        $job = $this->makeJob([
            'id'       => $jobId,
            'cron'     => '* * * * *',
            'recurring'=> false,
            'durable'  => true,
            'status'   => 'active',
        ]);

        // Seed the disk file (simulating a previously persisted durable job)
        file_put_contents($diskPath, json_encode([$jobId => $job]));

        CronScheduler::addJob($job);

        // Force-fire by setting last_fired to well in the past
        // checkDue() won't skip since last_fired is null (0)
        CronScheduler::checkDue();

        // The job should now be completed in memory
        $inMemory = CronScheduler::getJob($jobId);
        $this->assertSame('completed', $inMemory['status']);

        // The disk file must also reflect completed status
        $onDisk = json_decode(file_get_contents($diskPath), true);
        $this->assertSame('completed', $onDisk[$jobId]['status'],
            'Durable one-shot job must be written as completed to disk after firing, so process restarts do not re-fire it');

        // Cleanup
        unlink($diskPath);
        rmdir($dir);
        rmdir($tmpDir);
        unset($_SERVER['HOME']);
    }

    public function test_durable_recurring_job_last_fired_persisted_to_disk(): void
    {
        $tmpDir = sys_get_temp_dir() . '/cron_test_recurring_' . getmypid();
        $dir = $tmpDir . '/.haocode';
        mkdir($dir, 0755, true);

        $diskPath = $dir . '/scheduled_tasks.json';
        $_SERVER['HOME'] = $tmpDir;

        $jobId = 'recurring_durable';
        $job = $this->makeJob([
            'id'       => $jobId,
            'cron'     => '* * * * *',
            'recurring'=> true,
            'durable'  => true,
        ]);

        file_put_contents($diskPath, json_encode([$jobId => $job]));
        CronScheduler::addJob($job);

        CronScheduler::checkDue();

        // In-memory job has last_fired set
        $inMemory = CronScheduler::getJob($jobId);
        $this->assertNotNull($inMemory['last_fired']);

        // Disk must also have last_fired set so restart doesn't immediately re-fire
        $onDisk = json_decode(file_get_contents($diskPath), true);
        $this->assertNotNull($onDisk[$jobId]['last_fired'],
            'Durable recurring job must persist last_fired to disk to prevent double-firing on restart');

        // Cleanup
        unlink($diskPath);
        rmdir($dir);
        rmdir($tmpDir);
        unset($_SERVER['HOME']);
    }
}

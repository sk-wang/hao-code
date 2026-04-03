<?php

namespace Tests\Unit;

use App\Tools\Cron\CronCreateTool;
use App\Tools\Cron\CronDeleteTool;
use App\Tools\Cron\CronListTool;
use App\Tools\Cron\CronScheduler;
use App\Tools\ToolUseContext;
use PHPUnit\Framework\TestCase;

class CronToolsTest extends TestCase
{
    private ToolUseContext $context;

    protected function setUp(): void
    {
        // Clear CronScheduler static state
        foreach (array_keys(CronScheduler::getAllJobs()) as $id) {
            CronScheduler::removeJob($id);
        }

        $this->context = new ToolUseContext(
            workingDirectory: sys_get_temp_dir(),
            sessionId: 'test',
        );
    }

    // ─── CronCreateTool ───────────────────────────────────────────────────

    public function test_create_registers_an_active_job(): void
    {
        $tool = new CronCreateTool;

        $result = $tool->call([
            'cron' => '*/5 * * * *',
            'prompt' => 'check status',
        ], $this->context);

        $this->assertFalse($result->isError);

        // Extract job ID from output
        preg_match('/cron_[a-f0-9]+/', $result->output, $m);
        $this->assertNotEmpty($m, 'Expected a job ID in output');

        $job = CronScheduler::getJob($m[0]);
        $this->assertNotNull($job);
        $this->assertSame('active', $job['status']);
        $this->assertSame('check status', $job['prompt']);
    }

    public function test_create_returns_error_for_invalid_cron(): void
    {
        $tool = new CronCreateTool;

        $result = $tool->call([
            'cron' => '* * * *',  // only 4 fields
            'prompt' => 'bad cron',
        ], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid cron', $result->output);
    }

    public function test_create_defaults_to_recurring_true(): void
    {
        $tool = new CronCreateTool;
        $tool->call([
            'cron' => '* * * * *',
            'prompt' => 'recurring by default',
        ], $this->context);

        $jobs = CronScheduler::getAllJobs();
        $job = reset($jobs);
        $this->assertTrue($job['recurring']);
    }

    public function test_create_one_shot_job(): void
    {
        $tool = new CronCreateTool;
        $tool->call([
            'cron' => '0 9 * * *',
            'prompt' => 'morning task',
            'recurring' => false,
        ], $this->context);

        $jobs = CronScheduler::getAllJobs();
        $job = reset($jobs);
        $this->assertFalse($job['recurring']);
    }

    public function test_create_output_includes_cancel_instruction(): void
    {
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '*/10 * * * *',
            'prompt' => 'test',
        ], $this->context);

        $this->assertStringContainsString('CronDelete', $result->output);
    }

    public function test_create_describes_step_cadence(): void
    {
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '*/5 * * * *',
            'prompt' => 'test',
        ], $this->context);

        $this->assertStringContainsString('5 minutes', $result->output);
    }

    public function test_create_describes_daily_cadence(): void
    {
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '30 9 * * *',
            'prompt' => 'morning standup',
        ], $this->context);

        // Should contain time reference
        $this->assertStringContainsString('9:30', $result->output);
    }

    public function test_create_does_not_describe_monthly_cron_as_daily(): void
    {
        // "30 9 1 * *" fires on the 1st of each month at 9:30 — NOT "daily"
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '30 9 1 * *',
            'prompt' => 'monthly report',
        ], $this->context);

        $this->assertStringNotContainsString('daily', $result->output,
            'Monthly cron (dom=1) must not be labelled as "daily"');
        $this->assertStringContainsString('monthly', $result->output);
    }

    public function test_create_describes_weekly_cadence(): void
    {
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '0 8 * * 1',
            'prompt' => 'Monday standup',
        ], $this->context);

        $this->assertStringContainsString('weekly', $result->output);
        $this->assertStringContainsString('8:0', $result->output);
    }

    public function test_create_describes_hourly_step_cadence_not_as_daily(): void
    {
        // "0 */2 * * *" means "every 2 hours", NOT "daily at */2:0".
        // Before the fix, describeCadence() hit the "daily" branch because
        // $hour !== '*' was satisfied by '*/2', producing nonsense output.
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '0 */2 * * *',
            'prompt' => 'check every 2 hours',
        ], $this->context);

        $this->assertStringNotContainsString('daily', $result->output,
            '"0 */2 * * *" must not be labelled as "daily"');
        $this->assertStringContainsString('2 hours', $result->output,
            '"0 */2 * * *" should be described as "every 2 hours"');
    }

    public function test_create_describes_hourly_interval_for_every_1_hour(): void
    {
        $tool = new CronCreateTool;
        $result = $tool->call([
            'cron' => '0 */1 * * *',
            'prompt' => 'hourly task',
        ], $this->context);

        $this->assertStringNotContainsString('daily', $result->output);
        $this->assertStringContainsString('hour', $result->output);
    }

    // ─── CronDeleteTool ───────────────────────────────────────────────────

    public function test_delete_cancels_an_existing_job(): void
    {
        $job = [
            'id' => 'test_del_job',
            'cron' => '* * * * *',
            'prompt' => 'will be deleted',
            'recurring' => true,
            'status' => 'active',
            'created_at' => date('c'),
            'last_fired' => null,
            'fire_count' => 0,
            'durable' => false,
        ];
        CronScheduler::addJob($job);

        $tool = new CronDeleteTool;
        $result = $tool->call(['id' => 'test_del_job'], $this->context);

        $this->assertFalse($result->isError);
        $this->assertNull(CronScheduler::getJob('test_del_job'));
        $this->assertStringContainsString('Cancelled', $result->output);
    }

    public function test_delete_returns_error_for_unknown_job(): void
    {
        $tool = new CronDeleteTool;
        $result = $tool->call(['id' => 'nonexistent_job_id'], $this->context);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('not found', $result->output);
    }

    public function test_delete_output_includes_prompt(): void
    {
        CronScheduler::addJob([
            'id' => 'show_prompt_job',
            'cron' => '* * * * *',
            'prompt' => 'check the build status',
            'recurring' => true,
            'status' => 'active',
            'created_at' => date('c'),
            'last_fired' => null,
            'fire_count' => 0,
            'durable' => false,
        ]);

        $tool = new CronDeleteTool;
        $result = $tool->call(['id' => 'show_prompt_job'], $this->context);

        $this->assertStringContainsString('check the build status', $result->output);
    }

    // ─── CronListTool ─────────────────────────────────────────────────────

    public function test_list_returns_empty_message_when_no_jobs(): void
    {
        $tool = new CronListTool;
        $result = $tool->call([], $this->context);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('No scheduled', $result->output);
    }

    public function test_list_includes_all_jobs(): void
    {
        CronScheduler::addJob([
            'id' => 'list_job_1', 'cron' => '* * * * *', 'prompt' => 'task one',
            'recurring' => true, 'status' => 'active', 'created_at' => date('c'),
            'last_fired' => null, 'fire_count' => 0, 'durable' => false,
        ]);
        CronScheduler::addJob([
            'id' => 'list_job_2', 'cron' => '0 9 * * *', 'prompt' => 'task two',
            'recurring' => false, 'status' => 'active', 'created_at' => date('c'),
            'last_fired' => null, 'fire_count' => 0, 'durable' => false,
        ]);

        $tool = new CronListTool;
        $result = $tool->call([], $this->context);

        $this->assertStringContainsString('list_job_1', $result->output);
        $this->assertStringContainsString('list_job_2', $result->output);
        $this->assertStringContainsString('task one', $result->output);
        $this->assertStringContainsString('task two', $result->output);
    }

    public function test_list_shows_job_count(): void
    {
        CronScheduler::addJob([
            'id' => 'count_job', 'cron' => '* * * * *', 'prompt' => 'x',
            'recurring' => true, 'status' => 'active', 'created_at' => date('c'),
            'last_fired' => null, 'fire_count' => 2, 'durable' => false,
        ]);

        $result = (new CronListTool)->call([], $this->context);

        $this->assertStringContainsString('(1)', $result->output);
    }

    public function test_list_truncates_long_prompts(): void
    {
        $longPrompt = str_repeat('a', 100);
        CronScheduler::addJob([
            'id' => 'trunc_job', 'cron' => '* * * * *', 'prompt' => $longPrompt,
            'recurring' => true, 'status' => 'active', 'created_at' => date('c'),
            'last_fired' => null, 'fire_count' => 0, 'durable' => false,
        ]);

        $result = (new CronListTool)->call([], $this->context);

        $this->assertStringContainsString('...', $result->output);
    }

    public function test_list_is_read_only(): void
    {
        $this->assertTrue((new CronListTool)->isReadOnly([]));
    }
}

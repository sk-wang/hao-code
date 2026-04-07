<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Agent\TeamManager;
use Tests\TestCase;

class TeamManagerTest extends TestCase
{
    private string $tempDir;

    private TeamManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/haocode-team-test-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0755, true);
        $this->manager = new TeamManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function test_create_persists_team_manifest(): void
    {
        $team = $this->manager->create('my-team', [
            ['role' => 'architect', 'agent_type' => 'Plan', 'prompt' => 'You design systems.'],
            ['role' => 'coder', 'agent_type' => 'general-purpose', 'prompt' => 'You write code.'],
        ]);

        $this->assertSame('my-team', $team['name']);
        $this->assertCount(2, $team['members']);
        $this->assertSame('architect', $team['members'][0]['role']);
        $this->assertSame('my-team_architect', $team['members'][0]['agent_id']);
        $this->assertSame('Plan', $team['members'][0]['agent_type']);
        $this->assertSame('coder', $team['members'][1]['role']);
        $this->assertSame('my-team_coder', $team['members'][1]['agent_id']);
        $this->assertArrayHasKey('created_at', $team);
        $this->assertArrayHasKey('updated_at', $team);

        // Verify file on disk
        $this->assertFileExists($this->tempDir . '/my-team.team.json');
    }

    public function test_get_returns_persisted_team(): void
    {
        $this->manager->create('test-team', [
            ['role' => 'reviewer', 'agent_type' => 'code-reviewer', 'prompt' => 'Review code.'],
        ]);

        $team = $this->manager->get('test-team');

        $this->assertNotNull($team);
        $this->assertSame('test-team', $team['name']);
        $this->assertCount(1, $team['members']);
        $this->assertSame('reviewer', $team['members'][0]['role']);
    }

    public function test_get_returns_null_for_nonexistent_team(): void
    {
        $this->assertNull($this->manager->get('nonexistent'));
    }

    public function test_list_returns_all_teams_sorted_by_creation(): void
    {
        $this->manager->create('alpha', [
            ['role' => 'a', 'agent_type' => 'general-purpose', 'prompt' => 'A'],
        ]);

        // Force a different timestamp
        usleep(1100000); // 1.1 seconds

        $this->manager->create('beta', [
            ['role' => 'b', 'agent_type' => 'general-purpose', 'prompt' => 'B'],
        ]);

        $teams = $this->manager->list();

        $this->assertCount(2, $teams);
        // Sorted newest first
        $this->assertSame('beta', $teams[0]['name']);
        $this->assertSame('alpha', $teams[1]['name']);
    }

    public function test_list_returns_empty_array_when_no_teams(): void
    {
        $this->assertSame([], $this->manager->list());
    }

    public function test_delete_removes_team_file(): void
    {
        $this->manager->create('disposable', [
            ['role' => 'x', 'agent_type' => 'general-purpose', 'prompt' => 'X'],
        ]);

        $this->assertTrue($this->manager->delete('disposable'));
        $this->assertNull($this->manager->get('disposable'));
        $this->assertFileDoesNotExist($this->tempDir . '/disposable.team.json');
    }

    public function test_delete_returns_false_for_nonexistent_team(): void
    {
        $this->assertFalse($this->manager->delete('ghost'));
    }

    public function test_member_agent_id_generates_deterministic_ids(): void
    {
        $this->assertSame('team_architect', TeamManager::memberAgentId('team', 'architect'));
        $this->assertSame('my-team_code-reviewer', TeamManager::memberAgentId('my-team', 'Code Reviewer'));
        $this->assertSame('t_a-b-c', TeamManager::memberAgentId('t', 'A B C'));
    }

    public function test_member_agent_id_sanitizes_special_characters(): void
    {
        $this->assertSame('team_role-name', TeamManager::memberAgentId('team', '  Role Name!  '));
        $this->assertSame('team_test-123', TeamManager::memberAgentId('team', 'test@123'));
    }

    public function test_create_with_optional_model(): void
    {
        $team = $this->manager->create('modeled', [
            ['role' => 'fast', 'agent_type' => 'general-purpose', 'prompt' => 'Fast.', 'model' => 'haiku'],
            ['role' => 'smart', 'agent_type' => 'general-purpose', 'prompt' => 'Smart.', 'model' => null],
        ]);

        $this->assertSame('haiku', $team['members'][0]['model']);
        $this->assertNull($team['members'][1]['model']);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}

<?php

namespace Tests\Unit;

use App\Services\Mcp\McpServerConfigManager;
use Tests\TestCase;

class McpServerConfigManagerTest extends TestCase
{
    private string $tmpDir;
    private string $projectDir;
    private string $globalSettingsPath;
    private string $originalCwd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/haocode_mcp_'.uniqid();
        $this->projectDir = $this->tmpDir.'/project';
        $this->globalSettingsPath = $this->tmpDir.'/global/.haocode/settings.json';
        mkdir($this->projectDir, 0755, true);
        mkdir(dirname($this->globalSettingsPath), 0755, true);

        config(['haocode.global_settings_path' => $this->globalSettingsPath]);

        $this->originalCwd = getcwd();
        chdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        chdir($this->originalCwd);

        foreach (glob($this->tmpDir.'/*/.haocode/settings.json') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($this->globalSettingsPath);
        @rmdir(dirname($this->globalSettingsPath));
        @rmdir($this->projectDir.'/.haocode');
        @rmdir($this->projectDir);
        @rmdir($this->tmpDir.'/global');
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_add_server_persists_project_stdio_config(): void
    {
        $manager = new McpServerConfigManager;
        $manager->addServer('demo', [
            'transport' => 'stdio',
            'command' => 'npx',
            'args' => ['-y', '@demo/server'],
            'enabled' => true,
        ], 'project');

        $server = $manager->getServer('demo');

        $this->assertNotNull($server);
        $this->assertSame('project', $server['scope']);
        $this->assertSame('stdio', $server['transport']);
        $this->assertSame('npx', $server['command']);
        $this->assertSame(['-y', '@demo/server'], $server['args']);
    }

    public function test_project_definition_overrides_global_definition_with_same_name(): void
    {
        $manager = new McpServerConfigManager;
        $manager->addServer('shared', [
            'transport' => 'http',
            'url' => 'https://global.test/mcp',
            'enabled' => true,
        ], 'global');
        $manager->addServer('shared', [
            'transport' => 'stdio',
            'command' => 'node',
            'args' => ['server.js'],
            'enabled' => false,
        ], 'project');

        $server = $manager->getServer('shared');

        $this->assertNotNull($server);
        $this->assertSame('project', $server['scope']);
        $this->assertSame('stdio', $server['transport']);
        $this->assertFalse($server['enabled']);
    }

    public function test_set_enabled_and_remove_work_across_scopes(): void
    {
        $manager = new McpServerConfigManager;
        $manager->addServer('global-only', [
            'transport' => 'http',
            'url' => 'https://example.test/mcp',
            'enabled' => true,
        ], 'global');

        $updated = $manager->setEnabled('global-only', false, 'global');
        $server = $manager->getServer('global-only');
        $removed = $manager->removeServer('global-only', 'global');

        $this->assertSame(1, $updated);
        $this->assertNotNull($server);
        $this->assertFalse($server['enabled']);
        $this->assertSame(1, $removed);
        $this->assertNull($manager->getServer('global-only'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Sdk\Examples\SupportOpsAgent;
use App\Services\Api\StreamingClient;
use App\Services\Settings\SettingsManager;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

class SupportOpsAgentExampleTest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $workspaceDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-support-ops-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->workspaceDir = $this->tempRoot.'/workspace';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/laravel-storage';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->workspaceDir, 0755, true);
        mkdir($this->sessionDir, 0755, true);
        mkdir($this->storageDir, 0755, true);

        $this->setHomeDirectory($this->homeDir);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== false) {
            chdir($this->originalCwd);
        }

        $this->setHomeDirectory($this->originalHome);
        $this->removeDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_support_ops_agent_runs_full_sdk_workflow(): void
    {
        $this->bootWithMock([
            MockAnthropicSse::toolUseResponse('toolu_ticket_1', 'GetEscalationTicket', [
                'ticket_id' => 'INC-2047',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('duplicate charges', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_health_1', 'GetServiceHealth', [
                    'service' => 'payments-api',
                ]);
            },
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('error_rate', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_write_1', 'Write', [
                    'file_path' => 'incident-plan.md',
                    'content' => "# Incident Plan\n1. Freeze retries\n2. Drain duplicate queue\n3. Notify support leadership\n",
                ]);
            },
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('incident-plan.md', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Plan saved. Immediate action: freeze retries and drain the duplicate queue.'
                );
            },
            MockAnthropicSse::toolUseResponse('toolu_skill_1', 'Skill', [
                'skill' => 'triage-incident',
                'args' => 'INC-2047',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('GetEscalationTicket', $toolResult);
                $this->assertStringContainsString('RunbookNotes', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Streaming triage complete. The blast radius is limited to duplicate charges after deploy 2026.04.07.'
                );
            },
            MockAnthropicSse::toolUseResponse('toolu_notes_1', 'RunbookNotes', [
                'action' => 'append',
                'title' => 'Top hypotheses',
                'note' => '1. Retry loop duplicated payment capture; 2. Queue replay re-sent settled events.',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Stored note #1', $toolResult);
                $this->assertStringContainsString('Top hypotheses', $toolResult);

                return MockAnthropicSse::textResponse('Saved the top hypotheses to the runbook.');
            },
            MockAnthropicSse::toolUseResponse('toolu_window_1', 'GetDeploymentWindow', [
                'service' => 'payments-api',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('finance freeze', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_notes_2', 'RunbookNotes', [
                    'action' => 'append',
                    'title' => 'Recommended action',
                    'note' => 'Do not deploy during the active finance freeze. Roll back the retry worker config and refund duplicate captures.',
                ]);
            },
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Stored note #2', $toolResult);
                $this->assertStringContainsString('Recommended action', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Deployment is not safe right now. The recommended action has been added to the runbook.'
                );
            },
            MockAnthropicSse::toolUseResponse('toolu_notes_3', 'RunbookNotes', [
                'action' => 'list',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertNotNull($toolResult);
                $this->assertStringContainsString('Top hypotheses', $toolResult);
                $this->assertStringContainsString('Recommended action', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Stakeholder update: duplicate charges traced to retry configuration; no deploy during freeze; refunds in progress.'
                );
            },
            MockAnthropicSse::textResponse(
                'Executive summary: duplicate-charge incident contained; refunds are underway.'
            ),
            function (array $payload): MockResponse {
                $this->assertSame('What is the single next action?', MockAnthropicSse::lastUserText($payload));

                return MockAnthropicSse::textResponse(
                    'Single next action: disable the retry worker and start refunds.'
                );
            },
            function (array $payload): MockResponse {
                $this->assertStringContainsString('IMPORTANT: You MUST respond with ONLY a valid JSON object', MockAnthropicSse::lastUserText($payload) ?? '');

                return MockAnthropicSse::textResponse(
                    '{"severity":"sev2","owner":"payments-oncall","next_action":"Disable retry worker and refund duplicate captures","customer_message":"We have contained the duplicate-charge issue and are processing refunds.","deploy_safe":false}'
                );
            },
        ]);

        chdir($this->workspaceDir);

        $output = '';
        $agent = new SupportOpsAgent(
            workspaceDir: $this->workspaceDir,
            writer: function (string $chunk) use (&$output): void {
                $output .= $chunk;
            },
        );

        $result = $agent->run();

        $this->assertSame($this->workspaceDir, $result['workspace_dir']);
        $this->assertFileExists($this->workspaceDir.'/incident-plan.md');
        $this->assertStringContainsString('Freeze retries', file_get_contents($this->workspaceDir.'/incident-plan.md'));

        $this->assertStringContainsString('Plan saved.', $result['plan']->text);
        $this->assertStringContainsString('Stakeholder update', $result['stakeholder_update']->text);
        $this->assertStringContainsString('Executive summary', $result['executive_summary']->text);
        $this->assertStringContainsString('Single next action', $result['next_action']->text);
        $this->assertSame('sev2', $result['handoff']->severity);
        $this->assertSame('payments-oncall', $result['handoff']->owner);
        $this->assertFalse($result['handoff']->deploy_safe);
        $this->assertNotNull($result['conversation_session_id']);
        $this->assertNotEmpty($result['stream_events']);
        $this->assertContains('result', $result['stream_events']);
        $this->assertContains('tool_start', $result['conversation_stream_events']);
        $this->assertContains('result', $result['conversation_stream_events']);

        $this->assertStringContainsString('Support Ops Agent', $output);
        $this->assertStringContainsString('[callback] turn 1 started', $output);
        $this->assertStringContainsString('[stream]', $output);
        $this->assertStringContainsString('Handoff JSON', $output);
    }

    private function bootWithMock(array $responses): void
    {
        $requests = [];
        $this->refreshApplication();
        $this->app->useStoragePath($this->storageDir);

        $_SERVER['LARAVEL_STORAGE_PATH'] = $this->storageDir;
        putenv('LARAVEL_STORAGE_PATH='.$this->storageDir);

        config([
            'haocode.api_key' => 'test-key',
            'haocode.api_base_url' => 'https://mock.anthropic.test',
            'haocode.model' => 'claude-test',
            'haocode.max_tokens' => 4096,
            'haocode.stream_output' => false,
            'haocode.permission_mode' => 'bypass_permissions',
            'haocode.global_settings_path' => $this->homeDir.'/.haocode/settings.json',
            'haocode.session_path' => $this->sessionDir,
            'haocode.api_stream_idle_timeout' => 2,
            'haocode.api_stream_poll_timeout' => 0.01,
        ]);

        $this->app->singleton(StreamingClient::class, function ($app) use (&$requests, $responses): StreamingClient {
            return new StreamingClient(
                apiKey: 'test-key',
                model: 'claude-test',
                baseUrl: 'https://mock.anthropic.test',
                maxTokens: 4096,
                httpClient: MockAnthropicSse::client($responses, $requests),
                settingsManager: $app->make(SettingsManager::class),
                idleTimeoutSeconds: 2,
                streamPollTimeoutSeconds: 0.01,
            );
        });
    }

    private function setHomeDirectory(string $home): void
    {
        if ($home === '') {
            putenv('HOME');
            unset($_SERVER['HOME']);

            return;
        }

        putenv('HOME='.$home);
        $_SERVER['HOME'] = $home;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}

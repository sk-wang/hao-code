<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Agent\BackgroundAgentManager;
use App\Services\Agent\TeamManager;
use App\Services\Api\StreamingClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

/**
 * E2E tests for Team tools: TeamCreate, TeamList, TeamDelete,
 * and SendMessage broadcast via mock API.
 */
class TeamToolsE2ETest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $teamDir;

    private string $agentDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-team-e2e-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->projectDir = $this->tempRoot.'/project';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/laravel-storage';
        $this->teamDir = $this->tempRoot.'/teams';
        $this->agentDir = $this->tempRoot.'/agents';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->projectDir, 0755, true);
        mkdir($this->sessionDir, 0755, true);
        mkdir($this->storageDir, 0755, true);
        mkdir($this->teamDir, 0755, true);
        mkdir($this->agentDir, 0755, true);

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

    // ──────────────────────────────────────────────────────────────
    //  Test 1: TeamList shows "no teams" when empty
    // ──────────────────────────────────────────────────────────────

    public function test_team_list_shows_no_teams_when_empty(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'List all teams.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_tl1', 'TeamList', []),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('No teams', $toolResult);

                return MockAnthropicSse::textResponse('No teams exist yet.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(2, $run['requests']);
        $this->assertStringContainsString('No teams', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 2: TeamList shows pre-seeded team with member statuses
    // ──────────────────────────────────────────────────────────────

    public function test_team_list_shows_seeded_team_details(): void
    {
        $this->seedTeam('dev-team', [
            ['role' => 'architect', 'agent_type' => 'Plan', 'prompt' => 'Design.'],
            ['role' => 'coder', 'agent_type' => 'general-purpose', 'prompt' => 'Code.'],
        ], function (BackgroundAgentManager $bg) {
            $bg->create(id: 'dev-team_architect', prompt: 'Design.', agentType: 'Plan');
            $bg->markRunning('dev-team_architect');
            $bg->recordResult('dev-team_architect', 'Designed the architecture.');

            $bg->create(id: 'dev-team_coder', prompt: 'Code.', agentType: 'general-purpose');
            $bg->markCompleted('dev-team_coder', 'Feature implemented.');
        });

        $run = $this->runHaoCodeCommand([
            '--print' => 'Show dev-team status.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_tl2', 'TeamList', [
                'name' => 'dev-team',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('dev-team', $toolResult);
                $this->assertStringContainsString('architect', $toolResult);
                $this->assertStringContainsString('coder', $toolResult);

                return MockAnthropicSse::textResponse('Team has 2 members: architect (running), coder (completed).');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertStringContainsString('2 members', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 3: TeamDelete cleans up team and agent state
    // ──────────────────────────────────────────────────────────────

    public function test_team_delete_removes_team_and_agent_state(): void
    {
        $teamManager = new TeamManager($this->teamDir);
        $bgManager = new BackgroundAgentManager($this->agentDir);

        $this->seedTeam('cleanup-team', [
            ['role' => 'worker', 'agent_type' => 'general-purpose', 'prompt' => 'Work.'],
        ], function (BackgroundAgentManager $bg) {
            $bg->create(id: 'cleanup-team_worker', prompt: 'Work.', agentType: 'general-purpose');
            $bg->markCompleted('cleanup-team_worker', 'Done.');
        });

        $run = $this->runHaoCodeCommand([
            '--print' => 'Delete cleanup-team.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_td1', 'TeamDelete', [
                'name' => 'cleanup-team',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('deleted', $toolResult);

                return MockAnthropicSse::textResponse('Team deleted successfully.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);

        // Verify cleanup
        $this->assertNull($teamManager->get('cleanup-team'));
        $this->assertNull($bgManager->get('cleanup-team_worker'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 4: TeamDelete returns error for nonexistent team
    // ──────────────────────────────────────────────────────────────

    public function test_team_delete_errors_for_nonexistent_team(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Delete ghost-team.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_td2', 'TeamDelete', [
                'name' => 'ghost-team',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('not found', $toolResult);

                return MockAnthropicSse::textResponse('Team does not exist.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertStringContainsString('does not exist', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 5: SendMessage broadcast delivers to running members
    // ──────────────────────────────────────────────────────────────

    public function test_broadcast_delivers_to_running_members_only(): void
    {
        $bgManager = new BackgroundAgentManager($this->agentDir);

        $this->seedTeam('broadcast-team', [
            ['role' => 'alpha', 'agent_type' => 'general-purpose', 'prompt' => 'A.'],
            ['role' => 'beta', 'agent_type' => 'general-purpose', 'prompt' => 'B.'],
            ['role' => 'gamma', 'agent_type' => 'general-purpose', 'prompt' => 'C.'],
        ], function (BackgroundAgentManager $bg) {
            $bg->create(id: 'broadcast-team_alpha', prompt: 'A.', agentType: 'general-purpose');
            $bg->markRunning('broadcast-team_alpha');

            $bg->create(id: 'broadcast-team_beta', prompt: 'B.', agentType: 'general-purpose');
            $bg->markRunning('broadcast-team_beta');

            $bg->create(id: 'broadcast-team_gamma', prompt: 'C.', agentType: 'general-purpose');
            $bg->markCompleted('broadcast-team_gamma', 'Done.');
        });

        $run = $this->runHaoCodeCommand([
            '--print' => 'Broadcast status update.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_sm1', 'SendMessage', [
                'to' => 'team:broadcast-team',
                'message' => 'Report your progress now.',
                'summary' => 'status check',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('2/3 delivered', $toolResult);
                $this->assertStringContainsString('1 skipped', $toolResult);

                return MockAnthropicSse::textResponse('Broadcast sent to 2 of 3 members.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertStringContainsString('2 of 3', $run['output']);

        // Verify mailbox contents
        $alphaMsg = $bgManager->popNextMessage('broadcast-team_alpha');
        $this->assertNotNull($alphaMsg);
        $this->assertSame('Report your progress now.', $alphaMsg['message']);
        $this->assertSame('status check', $alphaMsg['summary']);

        $betaMsg = $bgManager->popNextMessage('broadcast-team_beta');
        $this->assertNotNull($betaMsg);

        // Gamma (completed) should have no messages
        $gammaMsg = $bgManager->popNextMessage('broadcast-team_gamma');
        $this->assertNull($gammaMsg);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 6: Broadcast errors for nonexistent team
    // ──────────────────────────────────────────────────────────────

    public function test_broadcast_errors_for_nonexistent_team(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Broadcast to phantom.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_sm2', 'SendMessage', [
                'to' => 'team:phantom',
                'message' => 'Hello?',
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('not found', $toolResult);

                return MockAnthropicSse::textResponse('Team not found.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 7: TeamCreate rejects duplicate team name
    // ──────────────────────────────────────────────────────────────

    public function test_team_create_rejects_duplicate_name(): void
    {
        $this->seedTeam('taken', [
            ['role' => 'x', 'agent_type' => 'general-purpose', 'prompt' => 'X.'],
        ]);

        $run = $this->runHaoCodeCommand([
            '--print' => 'Create team with taken name.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_tc1', 'TeamCreate', [
                'name' => 'taken',
                'task' => 'Build something new.',
                'members' => [
                    ['role' => 'worker', 'prompt' => 'Work hard.'],
                ],
            ]),
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('already exists', $toolResult);

                return MockAnthropicSse::textResponse('Team name is taken.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertStringContainsString('taken', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 8: US-Iran Conflict Research Report — full team lifecycle
    //
    //  Simulates a coordinator AI that:
    //  1. Creates a 4-member research team (historian, military-analyst,
    //     diplomat, editor)
    //  2. Checks team status via TeamList
    //  3. Each member "writes" their report section (Write tool)
    //  4. Broadcasts a compilation instruction to all members
    //  5. Editor reads all sections and compiles the final report
    //  6. Cleans up the team via TeamDelete
    //
    //  Since pcntl_fork is unsafe in PHPUnit, we pre-seed agent states
    //  to simulate members that have already been spawned.
    // ──────────────────────────────────────────────────────────────

    public function test_us_iran_conflict_research_report_full_lifecycle(): void
    {
        $teamName = 'iran-research';
        $bgManager = new BackgroundAgentManager($this->agentDir);
        $teamManager = new TeamManager($this->teamDir);

        // ── Phase 1: AI creates the team via TeamCreate ──────────
        // TeamCreate will fail to fork (no pcntl in test), so we
        // pre-seed the team and agent state, then test the
        // orchestration flow (list → write sections → broadcast →
        // compile → delete).

        $this->seedTeam($teamName, [
            ['role' => 'historian', 'agent_type' => 'general-purpose', 'prompt' => 'Research the historical context of US-Iran relations since 1953.'],
            ['role' => 'military-analyst', 'agent_type' => 'general-purpose', 'prompt' => 'Analyze current military postures, bases, and force projections.'],
            ['role' => 'diplomat', 'agent_type' => 'general-purpose', 'prompt' => 'Examine diplomatic channels, sanctions, and the JCPOA nuclear deal.'],
            ['role' => 'editor', 'agent_type' => 'general-purpose', 'prompt' => 'Compile all sections into a cohesive final report.'],
        ], function (BackgroundAgentManager $bg) use ($teamName) {
            foreach (['historian', 'military-analyst', 'diplomat', 'editor'] as $role) {
                $id = "{$teamName}_" . $role;
                $bg->create(id: $id, prompt: "Role: {$role}", agentType: 'general-purpose');
                $bg->markRunning($id);
            }
        });

        // Pre-populate "member outputs" to simulate completed research
        $bgManager->recordResult("{$teamName}_historian",
            'Section complete: 1953 CIA coup, hostage crisis, axis of evil, and ongoing tensions.');
        $bgManager->recordResult("{$teamName}_military-analyst",
            'Section complete: Fifth Fleet in Bahrain, Iranian missile program, Strait of Hormuz.');
        $bgManager->recordResult("{$teamName}_diplomat",
            'Section complete: JCPOA timeline, Trump withdrawal, EU mediation efforts.');
        $bgManager->recordResult("{$teamName}_editor",
            'Waiting for all sections to compile.');

        // ── Phase 2: AI checks team status ───────────────────────
        $run = $this->runHaoCodeCommand([
            '--print' => 'Write a US-Iran conflict research report using the team.',
        ], [
            // Turn 1: Check team status
            MockAnthropicSse::toolUseResponse('toolu_list', 'TeamList', [
                'name' => $teamName,
            ]),

            // Turn 2: See 4 running members, write historian's section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('historian', $toolResult);
                $this->assertStringContainsString('military-analyst', $toolResult);
                $this->assertStringContainsString('diplomat', $toolResult);
                $this->assertStringContainsString('editor', $toolResult);
                $this->assertStringContainsString('running', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                    'file_path' => 'report/01-historical-context.md',
                    'content' => "# Historical Context\n\n" .
                        "## 1953 Operation Ajax\nThe CIA-backed coup overthrew PM Mossadegh, installing Shah Pahlavi.\n\n" .
                        "## 1979 Islamic Revolution\nAyatollah Khomeini established the Islamic Republic. The US embassy hostage crisis lasted 444 days.\n\n" .
                        "## 2002 Axis of Evil\nPresident Bush labeled Iran part of the \"axis of evil,\" escalating tensions.\n",
                ]);
            },

            // Turn 3: Write military analyst's section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('01-historical-context.md', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w2', 'Write', [
                    'file_path' => 'report/02-military-posture.md',
                    'content' => "# Military Posture Analysis\n\n" .
                        "## US Fifth Fleet\nBased in Bahrain, the Fifth Fleet maintains carrier strike groups in the Persian Gulf.\n\n" .
                        "## Iranian Capabilities\nIran possesses ballistic missiles (Shahab-3, Emad) with range covering US bases.\n\n" .
                        "## Strait of Hormuz\n20% of global oil transits this chokepoint. Both sides conduct regular naval exercises.\n",
                ]);
            },

            // Turn 4: Write diplomat's section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('02-military-posture.md', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w3', 'Write', [
                    'file_path' => 'report/03-diplomatic-landscape.md',
                    'content' => "# Diplomatic Landscape\n\n" .
                        "## JCPOA (2015)\nThe Joint Comprehensive Plan of Action limited Iran's uranium enrichment in exchange for sanctions relief.\n\n" .
                        "## US Withdrawal (2018)\nThe Trump administration withdrew and reimposed \"maximum pressure\" sanctions.\n\n" .
                        "## Current Status\nIndirect negotiations continue via EU intermediaries. Iran enriches uranium to 60%.\n",
                ]);
            },

            // Turn 5: Broadcast compilation instruction to team
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('03-diplomatic-landscape.md', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_broadcast', 'SendMessage', [
                    'to' => 'team:iran-research',
                    'message' => 'All sections written. Editor: please compile the final report from report/*.md files.',
                    'summary' => 'compilation directive',
                ]);
            },

            // Turn 6: See broadcast result, read all sections via Glob
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('4/4 delivered', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_glob', 'Glob', [
                    'pattern' => 'report/*.md',
                ]);
            },

            // Turn 7: See 3 section files, read historical section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('01-historical-context.md', $toolResult);
                $this->assertStringContainsString('02-military-posture.md', $toolResult);
                $this->assertStringContainsString('03-diplomatic-landscape.md', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_read1', 'Read', [
                    'file_path' => $this->projectDir . '/report/01-historical-context.md',
                ]);
            },

            // Turn 8: Read military section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Operation Ajax', $toolResult);
                $this->assertStringContainsString('hostage crisis', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_read2', 'Read', [
                    'file_path' => $this->projectDir . '/report/02-military-posture.md',
                ]);
            },

            // Turn 9: Read diplomatic section
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Fifth Fleet', $toolResult);
                $this->assertStringContainsString('Strait of Hormuz', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_read3', 'Read', [
                    'file_path' => $this->projectDir . '/report/03-diplomatic-landscape.md',
                ]);
            },

            // Turn 10: Compile final report
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('JCPOA', $toolResult);
                $this->assertStringContainsString('maximum pressure', $toolResult);

                $finalReport = "# US-Iran Conflict: Comprehensive Research Report\n\n" .
                    "**Prepared by: iran-research team**\n" .
                    "**Date: 2026-04-07**\n\n" .
                    "---\n\n" .
                    "## Executive Summary\n\n" .
                    "US-Iran relations have been shaped by seven decades of intervention, revolution, " .
                    "nuclear ambition, and failed diplomacy. This report examines the conflict through " .
                    "three lenses: historical context, military posture, and diplomatic landscape.\n\n" .
                    "---\n\n" .
                    "## 1. Historical Context\n\n" .
                    "The 1953 CIA coup (Operation Ajax) and the 1979 Islamic Revolution established " .
                    "the foundational grievances. The hostage crisis severed diplomatic ties. Bush's " .
                    "\"axis of evil\" designation in 2002 further cemented adversarial framing.\n\n" .
                    "## 2. Military Posture\n\n" .
                    "The US Fifth Fleet in Bahrain provides regional deterrence. Iran's ballistic " .
                    "missile program (Shahab-3, Emad) and proxy network (Hezbollah, Houthis) create " .
                    "asymmetric counterbalance. The Strait of Hormuz remains the critical flashpoint.\n\n" .
                    "## 3. Diplomatic Landscape\n\n" .
                    "The JCPOA (2015) represented the high-water mark of diplomacy. The 2018 US " .
                    "withdrawal and \"maximum pressure\" campaign collapsed the framework. Iran now " .
                    "enriches uranium to 60%, approaching weapons-grade levels.\n\n" .
                    "## 4. Outlook\n\n" .
                    "De-escalation requires addressing three pillars simultaneously: nuclear limits, " .
                    "sanctions relief, and regional security architecture. Without a comprehensive " .
                    "approach, the cycle of provocation and response will continue.\n\n" .
                    "---\n\n" .
                    "*Report compiled from sections by historian, military-analyst, and diplomat team members.*\n";

                return MockAnthropicSse::toolUseResponse('toolu_final', 'Write', [
                    'file_path' => 'report/00-final-report.md',
                    'content' => $finalReport,
                ]);
            },

            // Turn 11: Delete the team after report is done
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('00-final-report.md', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_delete', 'TeamDelete', [
                    'name' => 'iran-research',
                ]);
            },

            // Turn 12: Summarize
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('deleted', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Research report complete. The iran-research team produced a 4-section report ' .
                    'covering historical context, military posture, diplomatic landscape, and outlook. ' .
                    'Team has been disbanded.'
                );
            },
        ]);

        // ── Assertions ──────────────────────────────────────────

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(12, $run['requests']);

        // Verify all report files exist on disk
        $this->assertFileExists($this->projectDir . '/report/00-final-report.md');
        $this->assertFileExists($this->projectDir . '/report/01-historical-context.md');
        $this->assertFileExists($this->projectDir . '/report/02-military-posture.md');
        $this->assertFileExists($this->projectDir . '/report/03-diplomatic-landscape.md');

        // Verify final report content
        $final = file_get_contents($this->projectDir . '/report/00-final-report.md');
        $this->assertStringContainsString('US-Iran Conflict', $final);
        $this->assertStringContainsString('Executive Summary', $final);
        $this->assertStringContainsString('Operation Ajax', $final);
        $this->assertStringContainsString('Fifth Fleet', $final);
        $this->assertStringContainsString('JCPOA', $final);
        $this->assertStringContainsString('60%', $final);
        $this->assertStringContainsString('Outlook', $final);
        $this->assertStringContainsString('iran-research team', $final);

        // Verify section content
        $history = file_get_contents($this->projectDir . '/report/01-historical-context.md');
        $this->assertStringContainsString('1953', $history);
        $this->assertStringContainsString('Mossadegh', $history);
        $this->assertStringContainsString('Khomeini', $history);

        $military = file_get_contents($this->projectDir . '/report/02-military-posture.md');
        $this->assertStringContainsString('Shahab-3', $military);
        $this->assertStringContainsString('Bahrain', $military);

        $diplomatic = file_get_contents($this->projectDir . '/report/03-diplomatic-landscape.md');
        $this->assertStringContainsString('2015', $diplomatic);
        $this->assertStringContainsString('Trump', $diplomatic);
        $this->assertStringContainsString('EU intermediaries', $diplomatic);

        // Verify broadcast was delivered (4 running members → 4 mailbox entries)
        // Members already had messages popped by the running agents, but we
        // verify the count was correct in the tool result assertion above.

        // Verify team was deleted
        $this->assertNull($teamManager->get($teamName));

        // Verify output summary
        $this->assertStringContainsString('Research report complete', $run['output']);
        $this->assertStringContainsString('disbanded', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 9: Dungeon Crawler Team — 4 specialists build a game
    //
    //  A game-dev team of 4 agents collaboratively builds a playable
    //  dungeon crawler in PHP:
    //
    //  world-builder   → rooms.json (map data)
    //  combat-designer → combat.php (damage formulas)
    //  quest-writer    → quests.json (quest definitions)
    //  engine-dev      → engine.php (main game loop)
    //
    //  Workflow: TeamList → Write modules → Broadcast "integrate" →
    //  Read combat.php → Edit to fix a balance bug → Bash run game →
    //  Grep find all functions → TeamDelete
    //
    //  14 API turns, exercises 8 distinct tools in one test.
    // ──────────────────────────────────────────────────────────────

    public function test_dungeon_crawler_team_builds_and_runs_game(): void
    {
        $teamName = 'dungeon-dev';
        $bgManager = new BackgroundAgentManager($this->agentDir);
        $teamManager = new TeamManager($this->teamDir);

        // Pre-seed team with 4 running members
        $this->seedTeam($teamName, [
            ['role' => 'world-builder', 'agent_type' => 'general-purpose', 'prompt' => 'Design dungeon rooms and map layout.'],
            ['role' => 'combat-designer', 'agent_type' => 'general-purpose', 'prompt' => 'Design combat formulas and enemy stats.'],
            ['role' => 'quest-writer', 'agent_type' => 'general-purpose', 'prompt' => 'Write quest definitions with rewards.'],
            ['role' => 'engine-dev', 'agent_type' => 'general-purpose', 'prompt' => 'Build the main game engine that ties everything together.'],
        ], function (BackgroundAgentManager $bg) use ($teamName) {
            foreach (['world-builder', 'combat-designer', 'quest-writer', 'engine-dev'] as $role) {
                $id = "{$teamName}_" . $role;
                $bg->create(id: $id, prompt: "Role: {$role}", agentType: 'general-purpose');
                $bg->markRunning($id);
            }
        });

        // ── JSON data produced by team members ───────────────────

        $roomsJson = json_encode([
            'entrance' => [
                'name' => 'Dungeon Entrance',
                'description' => 'Cold air flows from the darkness ahead.',
                'enemies' => [],
                'exits' => ['north' => 'goblin-hall'],
            ],
            'goblin-hall' => [
                'name' => 'Goblin Hall',
                'description' => 'Crude torches line the walls. Goblins lurk in the shadows.',
                'enemies' => ['goblin', 'goblin'],
                'exits' => ['south' => 'entrance', 'east' => 'treasure-room'],
            ],
            'treasure-room' => [
                'name' => 'Treasure Room',
                'description' => 'Gold coins glitter. A dragon skeleton guards the hoard.',
                'enemies' => ['dragon-skeleton'],
                'exits' => ['west' => 'goblin-hall'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $questsJson = json_encode([
            [
                'id' => 'slay-goblins',
                'name' => 'Clear the Goblin Hall',
                'target' => 'goblin',
                'count' => 2,
                'reward_gold' => 50,
                'reward_xp' => 100,
            ],
            [
                'id' => 'defeat-dragon',
                'name' => 'Defeat the Dragon Skeleton',
                'target' => 'dragon-skeleton',
                'count' => 1,
                'reward_gold' => 200,
                'reward_xp' => 500,
            ],
        ], JSON_PRETTY_PRINT);

        // combat.php with an intentional balance bug (goblin damage too high)
        $combatPhp = <<<'PHP'
<?php
function getEnemyStats($type) {
    $enemies = [
        'goblin'          => ['hp' => 5, 'attack' => 999, 'name' => 'Goblin'],
        'dragon-skeleton' => ['hp' => 80, 'attack' => 15,  'name' => 'Dragon Skeleton'],
    ];
    return $enemies[$type] ?? ['hp' => 10, 'attack' => 3, 'name' => 'Unknown'];
}

function calculateDamage($attack, $defense) {
    return max(1, $attack - $defense + rand(0, 3));
}

function isDefeated($hp) {
    return $hp <= 0;
}
PHP;

        // Engine — compact to fit Write tool's 2500-char limit
        $enginePhp = <<<'PHP'
<?php
require_once __DIR__.'/combat.php';
$R=json_decode(file_get_contents(__DIR__.'/rooms.json'),true);
$Q=json_decode(file_get_contents(__DIR__.'/quests.json'),true);
$p=['hp'=>100,'atk'=>12,'gold'=>0,'xp'=>0,'kills'=>[]];
echo "=== DUNGEON CRAWLER v1.0 ===\n";
echo "You enter: ".$R['entrance']['name']."\n";
$cur='goblin-hall';
echo "You move to: ".$R[$cur]['name']."\n";
foreach($R[$cur]['enemies'] as $et){
    $e=getEnemyStats($et);$d=calculateDamage($p['atk'],3);
    echo "Attack {$e['name']} for {$d} dmg.\n";
    $e['hp']-=$d;
    if(isDefeated($e['hp'])){echo "{$e['name']} defeated!\n";$p['kills'][]=$et;}
}
foreach($Q as $q){
    $c=count(array_filter($p['kills'],fn($k)=>$k===$q['target']));
    if($c>=$q['count']){$p['gold']+=$q['reward_gold'];$p['xp']+=$q['reward_xp'];
        echo "Quest complete: {$q['name']} (+{$q['reward_gold']}g +{$q['reward_xp']}xp)\n";}
}
echo "Gold:{$p['gold']} XP:{$p['xp']}\nGAME COMPLETE\n";
PHP;

        // ── Run the orchestration ────────────────────────────────

        $run = $this->runHaoCodeCommand([
            '--print' => 'Use the dungeon-dev team to build and test a dungeon crawler.',
        ], [
            // Turn 1: Check team status
            MockAnthropicSse::toolUseResponse('toolu_tl', 'TeamList', [
                'name' => $teamName,
            ]),

            // Turn 2: world-builder writes rooms.json
            function (array $payload) use ($roomsJson): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('world-builder', $toolResult);
                $this->assertStringContainsString('combat-designer', $toolResult);
                $this->assertStringContainsString('quest-writer', $toolResult);
                $this->assertStringContainsString('engine-dev', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w_rooms', 'Write', [
                    'file_path' => 'crawler/rooms.json',
                    'content' => $roomsJson,
                ]);
            },

            // Turn 3: quest-writer writes quests.json
            function (array $payload) use ($questsJson): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('rooms.json', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w_quests', 'Write', [
                    'file_path' => 'crawler/quests.json',
                    'content' => $questsJson,
                ]);
            },

            // Turn 4: combat-designer writes combat.php (with balance bug)
            function (array $payload) use ($combatPhp): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('quests.json', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w_combat', 'Write', [
                    'file_path' => 'crawler/combat.php',
                    'content' => $combatPhp,
                ]);
            },

            // Turn 5: engine-dev writes the main engine
            function (array $payload) use ($enginePhp): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('combat.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w_engine', 'Write', [
                    'file_path' => 'crawler/engine.php',
                    'content' => $enginePhp,
                ]);
            },

            // Turn 6: Broadcast integration instruction to team
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('engine.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_bc', 'SendMessage', [
                    'to' => 'team:dungeon-dev',
                    'message' => 'All modules written. Running integration test now.',
                    'summary' => 'integration',
                ]);
            },

            // Turn 7: Read combat.php to review the balance bug
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('4/4 delivered', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_read_combat', 'Read', [
                    'file_path' => $this->projectDir . '/crawler/combat.php',
                ]);
            },

            // Turn 8: Edit to fix goblin attack from 999 → 8
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('999', $toolResult);
                $this->assertStringContainsString('getEnemyStats', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_edit', 'Edit', [
                    'file_path' => $this->projectDir . '/crawler/combat.php',
                    'old_string' => "'attack' => 999",
                    'new_string' => "'attack' => 8",
                ]);
            },

            // Turn 9: Bash — run the game
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('combat.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_bash', 'Bash', [
                    'command' => 'php crawler/engine.php',
                    'description' => 'Run the dungeon crawler',
                ]);
            },

            // Turn 10: Verify game output, then Grep for all functions
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('DUNGEON CRAWLER v1.0', $toolResult);
                $this->assertStringContainsString('Dungeon Entrance', $toolResult);
                $this->assertStringContainsString('Goblin Hall', $toolResult);
                $this->assertStringContainsString('defeated!', $toolResult);
                $this->assertStringContainsString('Quest complete', $toolResult);
                $this->assertStringContainsString('Gold:', $toolResult);
                $this->assertStringContainsString('GAME COMPLETE', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_grep', 'Grep', [
                    'pattern' => 'function\\s+\\w+',
                    'path' => 'crawler',
                    'output_mode' => 'content',
                ]);
            },

            // Turn 11: See all functions, then Glob for all game files
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('getEnemyStats', $toolResult);
                $this->assertStringContainsString('calculateDamage', $toolResult);
                $this->assertStringContainsString('isDefeated', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_glob', 'Glob', [
                    'pattern' => 'crawler/*',
                ]);
            },

            // Turn 12: See file listing, delete the team
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('rooms.json', $toolResult);
                $this->assertStringContainsString('quests.json', $toolResult);
                $this->assertStringContainsString('combat.php', $toolResult);
                $this->assertStringContainsString('engine.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_del', 'TeamDelete', [
                    'name' => 'dungeon-dev',
                ]);
            },

            // Turn 13: Confirm deletion, write a summary file
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('deleted', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w_summary', 'Write', [
                    'file_path' => 'crawler/BUILD_LOG.md',
                    'content' => "# Dungeon Crawler Build Log\n\n" .
                        "## Team: dungeon-dev (4 members)\n\n" .
                        "| Role | Deliverable | Status |\n" .
                        "|------|-------------|--------|\n" .
                        "| world-builder | rooms.json (3 rooms) | Done |\n" .
                        "| combat-designer | combat.php (3 functions, bug fixed) | Done |\n" .
                        "| quest-writer | quests.json (2 quests) | Done |\n" .
                        "| engine-dev | engine.php (main loop) | Done |\n\n" .
                        "## Test Results\n" .
                        "- Game runs successfully\n" .
                        "- Goblin balance bug fixed (999 → 8 attack)\n" .
                        "- Quest completion verified (50 gold, 100 XP)\n" .
                        "- 3 rooms, 2 quests, 3 functions\n",
                ]);
            },

            // Turn 14: Final summary
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('BUILD_LOG.md', $toolResult);

                return MockAnthropicSse::textResponse(
                    'Dungeon crawler built and tested! The 4-member team delivered 3 rooms, ' .
                    '2 quests, a combat system (bug fixed), and a working game engine. ' .
                    'Team has been dissolved.'
                );
            },
        ]);

        // ── Assertions ──────────────────────────────────────────

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(14, $run['requests']);

        // Verify all game files on disk
        $this->assertFileExists($this->projectDir . '/crawler/rooms.json');
        $this->assertFileExists($this->projectDir . '/crawler/quests.json');
        $this->assertFileExists($this->projectDir . '/crawler/combat.php');
        $this->assertFileExists($this->projectDir . '/crawler/engine.php');
        $this->assertFileExists($this->projectDir . '/crawler/BUILD_LOG.md');

        // Verify rooms.json has 3 rooms
        $rooms = json_decode(file_get_contents($this->projectDir . '/crawler/rooms.json'), true);
        $this->assertCount(3, $rooms);
        $this->assertArrayHasKey('entrance', $rooms);
        $this->assertArrayHasKey('goblin-hall', $rooms);
        $this->assertArrayHasKey('treasure-room', $rooms);
        $this->assertSame('Goblin Hall', $rooms['goblin-hall']['name']);
        $this->assertCount(2, $rooms['goblin-hall']['enemies']);

        // Verify quests.json has 2 quests
        $quests = json_decode(file_get_contents($this->projectDir . '/crawler/quests.json'), true);
        $this->assertCount(2, $quests);
        $this->assertSame('slay-goblins', $quests[0]['id']);
        $this->assertSame('defeat-dragon', $quests[1]['id']);
        $this->assertSame(500, $quests[1]['reward_xp']);

        // Verify the balance bug was fixed
        $combat = file_get_contents($this->projectDir . '/crawler/combat.php');
        $this->assertStringContainsString("'attack' => 8", $combat);
        $this->assertStringNotContainsString('999', $combat);

        // Verify engine references all modules
        $engine = file_get_contents($this->projectDir . '/crawler/engine.php');
        $this->assertStringContainsString('combat.php', $engine);
        $this->assertStringContainsString('rooms.json', $engine);
        $this->assertStringContainsString('quests.json', $engine);

        // Verify build log
        $log = file_get_contents($this->projectDir . '/crawler/BUILD_LOG.md');
        $this->assertStringContainsString('dungeon-dev', $log);
        $this->assertStringContainsString('999', $log); // documents the bug
        $this->assertStringContainsString('bug fixed', $log);

        // Verify team was deleted
        $this->assertNull($teamManager->get($teamName));

        // Verify output
        $this->assertStringContainsString('Dungeon crawler', $run['output']);
        $this->assertStringContainsString('dissolved', $run['output']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Helpers
    // ══════════════════════════════════════════════════════════════

    private function seedTeam(string $name, array $members, ?callable $seedAgents = null): void
    {
        $teamManager = new TeamManager($this->teamDir);
        $teamManager->create($name, $members);

        if ($seedAgents !== null) {
            $bgManager = new BackgroundAgentManager($this->agentDir);
            $seedAgents($bgManager);
        }
    }

    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @return array{exit_code: int, output: string, requests: array<int, array>}
     */
    private function runHaoCodeCommand(array $parameters, array $responses): array
    {
        $requests = [];
        $this->bootFreshApplication($responses, $requests);

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $exitCode = $this->withWorkingDirectory($this->projectDir, fn (): int => $kernel->call('hao-code', $parameters));

        return [
            'exit_code' => $exitCode,
            'output' => $kernel->output(),
            'requests' => $requests,
        ];
    }

    private function bootFreshApplication(array $responses, array &$requests): void
    {
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

        $this->app->singleton(TeamManager::class, fn () => new TeamManager($this->teamDir));
        $this->app->singleton(BackgroundAgentManager::class, fn () => new BackgroundAgentManager($this->agentDir));

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

    private function withWorkingDirectory(string $directory, callable $callback): mixed
    {
        $previous = getcwd();
        chdir($directory);

        try {
            return $callback();
        } finally {
            if ($previous !== false) {
                chdir($previous);
            }
        }
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

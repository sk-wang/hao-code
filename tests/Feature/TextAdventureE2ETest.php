<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Api\StreamingClient;
use App\Services\Settings\SettingsManager;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpClient\Response\MockResponse;
use Tests\Support\MockAnthropicSse;
use Tests\TestCase;

/**
 * Complex multi-turn E2E tests that simulate an AI agent building
 * a text adventure game from scratch, exercising Write, Read, Edit,
 * Bash, and Glob tools across many API round-trips.
 */
class TextAdventureE2ETest extends TestCase
{
    private string $tempRoot;

    private string $homeDir;

    private string $projectDir;

    private string $sessionDir;

    private string $storageDir;

    private string $originalHome = '';

    private string|false $originalCwd = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = sys_get_temp_dir().'/haocode-adventure-e2e-'.bin2hex(random_bytes(4));
        $this->homeDir = $this->tempRoot.'/home';
        $this->projectDir = $this->tempRoot.'/project';
        $this->sessionDir = $this->homeDir.'/.haocode/storage/app/haocode/sessions';
        $this->storageDir = $this->tempRoot.'/laravel-storage';
        $this->originalHome = (string) ($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        $this->originalCwd = getcwd();

        mkdir($this->homeDir.'/.haocode', 0755, true);
        mkdir($this->projectDir, 0755, true);
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

    // ──────────────────────────────────────────────────────────────
    //  Test 1: Multi-tool build — Write game engine + data, run it
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_multi_tool_build_and_run(): void
    {
        $gamePhp = <<<'PHP'
<?php
$rooms = json_decode(file_get_contents(__DIR__.'/rooms.json'), true);
$current = 'entrance';
echo "You are in: ".$rooms[$current]['name']."\n";
echo "Exits: ".implode(', ', array_keys($rooms[$current]['exits']))."\n";
echo "GAME READY\n";
PHP;

        $roomsJson = json_encode([
            'entrance' => [
                'name' => 'The Grand Entrance',
                'description' => 'A vast stone hall with flickering torches.',
                'exits' => ['north' => 'library', 'east' => 'armory'],
            ],
            'library' => [
                'name' => 'The Ancient Library',
                'description' => 'Dusty shelves stretch to the ceiling.',
                'exits' => ['south' => 'entrance'],
            ],
            'armory' => [
                'name' => 'The Armory',
                'description' => 'Weapons line every wall.',
                'exits' => ['west' => 'entrance'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $run = $this->runHaoCodeCommand([
            '--print' => 'Build a text adventure game with rooms.',
        ], [
            // Turn 1: AI writes the game engine
            MockAnthropicSse::toolUseResponse('toolu_write_engine', 'Write', [
                'file_path' => 'adventure/game.php',
                'content' => $gamePhp,
            ]),
            // Turn 2: AI writes room data
            function (array $payload) use ($roomsJson): MockResponse {
                $this->assertTrue(MockAnthropicSse::hasToolResult($payload));
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Successfully created', $toolResult);
                $this->assertStringContainsString('adventure/game.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_write_rooms', 'Write', [
                    'file_path' => 'adventure/rooms.json',
                    'content' => $roomsJson,
                ]);
            },
            // Turn 3: AI runs the game to verify
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Successfully created', $toolResult);
                $this->assertStringContainsString('adventure/rooms.json', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_bash_run', 'Bash', [
                    'command' => 'cd adventure && php game.php',
                    'description' => 'Run the adventure game',
                ]);
            },
            // Turn 4: AI sees output and summarizes
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('GAME READY', $toolResult);
                $this->assertStringContainsString('The Grand Entrance', $toolResult);
                $this->assertStringContainsString('north, east', $toolResult);

                return MockAnthropicSse::textResponse('The text adventure game is built and running! It has 3 rooms: entrance, library, and armory.');
            },
        ]);

        // Verify files on disk
        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(4, $run['requests']);
        $this->assertFileExists($this->projectDir.'/adventure/game.php');
        $this->assertFileExists($this->projectDir.'/adventure/rooms.json');
        $this->assertStringContainsString('3 rooms', $run['output']);

        // Verify JSON content is valid
        $rooms = json_decode(file_get_contents($this->projectDir.'/adventure/rooms.json'), true);
        $this->assertCount(3, $rooms);
        $this->assertArrayHasKey('entrance', $rooms);
        $this->assertArrayHasKey('library', $rooms);
        $this->assertArrayHasKey('armory', $rooms);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 2: Write-then-Edit — Add inventory system via FileEdit
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_write_then_edit_adds_inventory(): void
    {
        $originalGame = <<<'PHP'
<?php
$health = 100;
$room = 'start';
echo "Health: $health\n";
echo "Room: $room\n";
echo "GAME_V1\n";
PHP;

        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a game then add inventory support.',
        ], [
            // Turn 1: Write original game
            MockAnthropicSse::toolUseResponse('toolu_write_v1', 'Write', [
                'file_path' => 'game/engine.php',
                'content' => $originalGame,
            ]),
            // Turn 2: Read the file back
            function (array $payload): MockResponse {
                $this->assertTrue(MockAnthropicSse::hasToolResult($payload));

                return MockAnthropicSse::toolUseResponse('toolu_read_1', 'Read', [
                    'file_path' => $this->projectDir.'/game/engine.php',
                ]);
            },
            // Turn 3: Edit to add inventory
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('GAME_V1', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_edit_1', 'Edit', [
                    'file_path' => $this->projectDir.'/game/engine.php',
                    'old_string' => "\$health = 100;\n\$room = 'start';",
                    'new_string' => "\$health = 100;\n\$inventory = ['torch', 'rusty_key'];\n\$room = 'start';",
                ]);
            },
            // Turn 4: Edit again — replace version marker
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('game/engine.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_edit_2', 'Edit', [
                    'file_path' => $this->projectDir.'/game/engine.php',
                    'old_string' => "echo \"GAME_V1\\n\";",
                    'new_string' => "echo \"Inventory: \".implode(\", \", \$inventory).\"\\n\";\necho \"GAME_V2\\n\";",
                ]);
            },
            // Turn 5: Run to verify
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_bash_v2', 'Bash', [
                    'command' => 'php game/engine.php',
                    'description' => 'Test game v2',
                ]);
            },
            // Turn 6: Summarize
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Inventory: torch, rusty_key', $toolResult);
                $this->assertStringContainsString('GAME_V2', $toolResult);

                return MockAnthropicSse::textResponse('Inventory system added. Player starts with a torch and rusty key.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);

        $content = file_get_contents($this->projectDir.'/game/engine.php');
        $this->assertStringContainsString('$inventory', $content);
        $this->assertStringContainsString('torch', $content);
        $this->assertStringContainsString('GAME_V2', $content);
        $this->assertStringContainsString('Inventory system added', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 3: Debug flow — write buggy code, run, see error, fix
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_debug_cycle_fixes_runtime_error(): void
    {
        // Intentionally buggy: referencing undefined $playerName
        $buggyGame = <<<'PHP'
<?php
$health = 100;
echo "Welcome, $playerName!\n";
echo "Health: $health\n";
PHP;

        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a game and debug any errors.',
        ], [
            // Turn 1: Write buggy game
            MockAnthropicSse::toolUseResponse('toolu_write_buggy', 'Write', [
                'file_path' => 'debug_game/main.php',
                'content' => $buggyGame,
            ]),
            // Turn 2: Run it — will produce a warning about undefined variable
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_bash_buggy', 'Bash', [
                    'command' => 'php -d error_reporting=E_ALL debug_game/main.php 2>&1',
                    'description' => 'Run buggy game to test',
                ]);
            },
            // Turn 3: See the warning, fix via Edit
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                // PHP 8.x emits "Undefined variable" warning
                $this->assertStringContainsString('playerName', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_edit_fix', 'Edit', [
                    'file_path' => $this->projectDir.'/debug_game/main.php',
                    'old_string' => "\$health = 100;\necho \"Welcome, \$playerName!\\n\";",
                    'new_string' => "\$health = 100;\n\$playerName = 'Adventurer';\necho \"Welcome, \$playerName!\\n\";",
                ]);
            },
            // Turn 4: Re-run to confirm fix
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_bash_fixed', 'Bash', [
                    'command' => 'php -d error_reporting=E_ALL debug_game/main.php 2>&1',
                    'description' => 'Re-run fixed game',
                ]);
            },
            // Turn 5: Success
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Welcome, Adventurer!', $toolResult);
                $this->assertStringContainsString('Health: 100', $toolResult);
                // Should no longer have the warning
                $this->assertStringNotContainsString('Undefined variable', $toolResult);

                return MockAnthropicSse::textResponse('Bug fixed! The undefined $playerName variable now defaults to "Adventurer".');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(5, $run['requests']);

        $fixed = file_get_contents($this->projectDir.'/debug_game/main.php');
        $this->assertStringContainsString("\$playerName = 'Adventurer'", $fixed);
        $this->assertStringContainsString('Bug fixed', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 4: Glob discovery — build multi-file game, list files
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_glob_discovers_all_game_files(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a multi-file adventure game and list all files.',
        ], [
            // Turn 1: Write main entry point
            MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                'file_path' => 'rpg/main.php',
                'content' => "<?php\nrequire_once 'combat.php';\nrequire_once 'items.php';\necho \"RPG Engine loaded.\\n\";\n",
            ]),
            // Turn 2: Write combat module
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w2', 'Write', [
                    'file_path' => 'rpg/combat.php',
                    'content' => "<?php\nfunction attack(\$dmg) { return max(0, \$dmg - rand(0,5)); }\n",
                ]);
            },
            // Turn 3: Write items module
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w3', 'Write', [
                    'file_path' => 'rpg/items.php',
                    'content' => "<?php\nfunction getItems() { return ['sword','shield','potion']; }\n",
                ]);
            },
            // Turn 4: Write config JSON
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w4', 'Write', [
                    'file_path' => 'rpg/config.json',
                    'content' => json_encode(['difficulty' => 'hard', 'max_level' => 50]),
                ]);
            },
            // Turn 5: Glob to discover all files
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_glob', 'Glob', [
                    'pattern' => 'rpg/**/*',
                ]);
            },
            // Turn 6: See file listing, summarize
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('main.php', $toolResult);
                $this->assertStringContainsString('combat.php', $toolResult);
                $this->assertStringContainsString('items.php', $toolResult);
                $this->assertStringContainsString('config.json', $toolResult);

                return MockAnthropicSse::textResponse('RPG project has 4 files: main.php, combat.php, items.php, and config.json.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);
        $this->assertFileExists($this->projectDir.'/rpg/main.php');
        $this->assertFileExists($this->projectDir.'/rpg/combat.php');
        $this->assertFileExists($this->projectDir.'/rpg/items.php');
        $this->assertFileExists($this->projectDir.'/rpg/config.json');
        $this->assertStringContainsString('4 files', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 5: Session continuity — build game across two sessions
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_continued_session_adds_new_dungeon(): void
    {
        // Session 1: Create base game
        $firstRun = $this->runHaoCodeCommand([
            '--print' => 'Start building a dungeon crawler.',
        ], [
            MockAnthropicSse::toolUseResponse('toolu_w_base', 'Write', [
                'file_path' => 'dungeon/world.json',
                'content' => json_encode([
                    'dungeons' => [
                        ['name' => 'Crypt of Shadows', 'level' => 1, 'rooms' => 5],
                    ],
                ], JSON_PRETTY_PRINT),
            ]),
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Base dungeon crawler created with the Crypt of Shadows.');
            },
        ]);

        $this->assertSame(0, $firstRun['exit_code']);
        $this->assertCount(1, $this->listSessionFiles());

        // Session 2: Continue and add a second dungeon
        $secondRun = $this->runHaoCodeCommand([
            '--continue' => true,
            '--print' => 'Add a second dungeon called Dragon Lair.',
        ], [
            function (array $payload): MockResponse {
                // Should carry over context from session 1
                // Session 1 had: user + assistant(tool_use) + user(tool_result) + assistant(text) = 4 msgs
                // Session 2 adds: user = 5 msgs total
                $this->assertSame(5, MockAnthropicSse::messageCount($payload));
                $this->assertSame('Add a second dungeon called Dragon Lair.', MockAnthropicSse::lastUserText($payload));

                return MockAnthropicSse::toolUseResponse('toolu_read_world', 'Read', [
                    'file_path' => $this->projectDir.'/dungeon/world.json',
                ]);
            },
            // Turn 2: Edit to add second dungeon
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Crypt of Shadows', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_write_world2', 'Write', [
                    'file_path' => 'dungeon/world.json',
                    'content' => json_encode([
                        'dungeons' => [
                            ['name' => 'Crypt of Shadows', 'level' => 1, 'rooms' => 5],
                            ['name' => 'Dragon Lair', 'level' => 10, 'rooms' => 12],
                        ],
                    ], JSON_PRETTY_PRINT),
                ]);
            },
            // Turn 3: Summarize
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Dragon Lair added as a level-10 dungeon with 12 rooms.');
            },
        ]);

        $this->assertSame(0, $secondRun['exit_code']);
        $this->assertStringContainsString('Dragon Lair', $secondRun['output']);

        // Verify the JSON on disk has both dungeons
        $world = json_decode(file_get_contents($this->projectDir.'/dungeon/world.json'), true);
        $this->assertCount(2, $world['dungeons']);
        $this->assertSame('Crypt of Shadows', $world['dungeons'][0]['name']);
        $this->assertSame('Dragon Lair', $world['dungeons'][1]['name']);
        $this->assertSame(12, $world['dungeons'][1]['rooms']);

        // Verify session continuity
        $sessionFiles = $this->listSessionFiles();
        $this->assertCount(1, $sessionFiles);
        $entries = $this->readSessionEntries($sessionFiles[0]);
        $assistantTurns = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['type'] ?? null) === 'assistant_turn',
        ));
        // First run: 2 API calls (Write tool + text) = 2 assistant turns
        // Second run: 3 API calls (Read tool + Write tool + text) = 3 assistant turns
        $this->assertGreaterThanOrEqual(2, count($assistantTurns));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 6: Grep search — find patterns across game source files
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_grep_finds_function_definitions(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Create game modules and search for all function definitions.',
        ], [
            // Turn 1: Write player module
            MockAnthropicSse::toolUseResponse('toolu_w_player', 'Write', [
                'file_path' => 'quest/player.php',
                'content' => "<?php\nfunction getPlayerHealth() { return 100; }\nfunction getPlayerName() { return 'Hero'; }\n",
            ]),
            // Turn 2: Write enemy module
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w_enemy', 'Write', [
                    'file_path' => 'quest/enemy.php',
                    'content' => "<?php\nfunction spawnEnemy(\$type) { return ['type' => \$type, 'hp' => 50]; }\nfunction enemyAttack(\$enemy) { return rand(5, 15); }\n",
                ]);
            },
            // Turn 3: Grep for all function definitions
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_grep', 'Grep', [
                    'pattern' => 'function\\s+\\w+',
                    'path' => 'quest',
                    'output_mode' => 'content',
                ]);
            },
            // Turn 4: Analyze results
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('getPlayerHealth', $toolResult);
                $this->assertStringContainsString('getPlayerName', $toolResult);
                $this->assertStringContainsString('spawnEnemy', $toolResult);
                $this->assertStringContainsString('enemyAttack', $toolResult);

                return MockAnthropicSse::textResponse('Found 4 functions across 2 files: getPlayerHealth, getPlayerName, spawnEnemy, enemyAttack.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(4, $run['requests']);
        $this->assertStringContainsString('4 functions', $run['output']);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 7: Full quest chain — multi-tool pipeline end to end
    // ──────────────────────────────────────────────────────────────

    public function test_adventure_game_full_quest_pipeline(): void
    {
        $questEngine = <<<'PHP'
<?php
$quests = json_decode(file_get_contents(__DIR__.'/quests.json'), true);
$completed = [];
foreach ($quests as $q) {
    if ($q['required_level'] <= 5) {
        $completed[] = $q['name'];
    }
}
echo "Completed quests: ".implode(', ', $completed)."\n";
echo "Total: ".count($completed)."/".count($quests)."\n";
PHP;

        $questsJson = json_encode([
            ['name' => 'Rat Cellar', 'required_level' => 1, 'reward' => 'wooden_sword'],
            ['name' => 'Goblin Cave', 'required_level' => 3, 'reward' => 'iron_shield'],
            ['name' => 'Dark Forest', 'required_level' => 5, 'reward' => 'health_potion'],
            ['name' => 'Dragon Peak', 'required_level' => 20, 'reward' => 'legendary_armor'],
            ['name' => 'Demon Gate', 'required_level' => 50, 'reward' => 'soul_gem'],
        ], JSON_PRETTY_PRINT);

        $run = $this->runHaoCodeCommand([
            '--print' => 'Build a quest system, run it, then add a new low-level quest.',
        ], [
            // Turn 1: Write quest engine
            MockAnthropicSse::toolUseResponse('toolu_wq1', 'Write', [
                'file_path' => 'quest_game/engine.php',
                'content' => $questEngine,
            ]),
            // Turn 2: Write quests data
            function (array $payload) use ($questsJson): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_wq2', 'Write', [
                    'file_path' => 'quest_game/quests.json',
                    'content' => $questsJson,
                ]);
            },
            // Turn 3: Run to see results
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_bash_quest', 'Bash', [
                    'command' => 'php quest_game/engine.php',
                    'description' => 'Run quest engine',
                ]);
            },
            // Turn 4: Check output, then edit quests.json to add a new quest
            function (array $payload) use ($questsJson): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Rat Cellar', $toolResult);
                $this->assertStringContainsString('Goblin Cave', $toolResult);
                $this->assertStringContainsString('Dark Forest', $toolResult);
                $this->assertStringContainsString('Total: 3/5', $toolResult);

                // Add a new level-2 quest via Edit
                return MockAnthropicSse::toolUseResponse('toolu_edit_quest', 'Edit', [
                    'file_path' => $this->projectDir.'/quest_game/quests.json',
                    'old_string' => '        "reward": "wooden_sword"'."\n    },",
                    'new_string' => '        "reward": "wooden_sword"'."\n    },\n    {\n        \"name\": \"Mushroom Marsh\",\n        \"required_level\": 2,\n        \"reward\": \"antidote\"\n    },",
                ]);
            },
            // Turn 5: Re-run to verify new quest is included
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_bash_quest2', 'Bash', [
                    'command' => 'php quest_game/engine.php',
                    'description' => 'Re-run quest engine after adding new quest',
                ]);
            },
            // Turn 6: Verify and summarize
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Mushroom Marsh', $toolResult);
                $this->assertStringContainsString('Total: 4/6', $toolResult);

                return MockAnthropicSse::textResponse('Quest pipeline complete. Added Mushroom Marsh — now 4 of 6 quests completable at level 5.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);

        // Verify final state on disk
        $quests = json_decode(file_get_contents($this->projectDir.'/quest_game/quests.json'), true);
        $this->assertCount(6, $quests);

        $names = array_column($quests, 'name');
        $this->assertContains('Mushroom Marsh', $names);
        $this->assertContains('Dragon Peak', $names);
        $this->assertStringContainsString('Quest pipeline complete', $run['output']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Infrastructure (mirrors HaoCodeOfflineE2ETest)
    // ══════════════════════════════════════════════════════════════

    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @return array{
     *   exit_code: int,
     *   output: string,
     *   requests: array<int, array{method: string, url: string, headers: array<string, mixed>, payload: array<string, mixed>}>
     * }
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

    /**
     * @param  array<int, MockResponse|callable(array, int, array): MockResponse>  $responses
     * @param  array<int, array{method: string, url: string, headers: array<string, mixed>, payload: array<string, mixed>}>  $requests
     */
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

    /**
     * @return array<int, string>
     */
    private function listSessionFiles(): array
    {
        $files = glob($this->sessionDir.'/*.jsonl') ?: [];
        sort($files);

        return $files;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readSessionEntries(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        $entries = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        return $entries;
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

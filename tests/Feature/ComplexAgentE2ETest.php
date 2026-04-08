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
 * Complex multi-scenario E2E tests exercising real-world agent workflows:
 *
 * 1. Code refactoring pipeline (Read → Edit × N → Bash test → verify)
 * 2. Project scaffolding (Write × 6 → Glob → Bash build → Grep verify)
 * 3. Data processing pipeline (Bash generate → Write → Read → Edit → Bash run)
 * 4. Error recovery flow (Bash fails → agent reads error → fixes → retries)
 * 5. Multi-file search-and-replace (Grep find → Read → Edit × N → verify)
 * 6. Documentation generator (Glob discover → Read × N → Write compiled doc)
 * 7. CI/CD simulation (Bash lint → Bash test → Edit fix → Bash test → pass)
 * 8. Database migration generator (Write schema → Write migration → Bash verify)
 */
class ComplexAgentE2ETest extends TestCase
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

        $this->tempRoot = sys_get_temp_dir().'/haocode-complex-e2e-'.bin2hex(random_bytes(4));
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
    //  Test 1: Code Refactoring Pipeline
    //  Read legacy code → Edit to modernize → Run tests → verify
    // ──────────────────────────────────────────────────────────────

    public function test_code_refactoring_read_edit_test_cycle(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a Calculator class then refactor it with type declarations.',
        ], [
            // Turn 1: Write legacy code first
            MockAnthropicSse::toolUseResponse('toolu_w0', 'Write', [
                'file_path' => 'src/Calculator.php',
                'content' => "<?php\nclass Calculator {\n    function add(\$a, \$b) { return \$a + \$b; }\n    function subtract(\$a, \$b) { return \$a - \$b; }\n    function multiply(\$a, \$b) { return \$a * \$b; }\n}\n",
            ]),
            // Turn 2: Read it back to review
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                    'file_path' => $this->projectDir.'/src/Calculator.php',
                ]);
            },
            // Turn 3: Rewrite with types
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('function add', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                    'file_path' => 'src/Calculator.php',
                    'content' => "<?php\ndeclare(strict_types=1);\n\nclass Calculator {\n    function add(int \$a, int \$b): int { return \$a + \$b; }\n    function subtract(int \$a, int \$b): int { return \$a - \$b; }\n    function multiply(int \$a, int \$b): int { return \$a * \$b; }\n}\n",
                ]);
            },
            // Turn 3: Bash — verify syntax
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                    'command' => 'php -l src/Calculator.php 2>&1',
                    'description' => 'Syntax check',
                ]);
            },
            // Turn 4: Bash — run the refactored code
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('No syntax errors', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_b2', 'Bash', [
                    'command' => "php -r 'require \"src/Calculator.php\"; \$c = new Calculator; echo \$c->add(2,3).\",\".\$c->subtract(10,4).\",\".\$c->multiply(3,7);'",
                    'description' => 'Run calculator',
                ]);
            },
            // Turn 5: Verify output
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('5,6,21', $toolResult);

                return MockAnthropicSse::textResponse('Refactoring complete. Added strict_types and int type declarations to all 3 methods.');
            },
        ]);


        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);

        $content = file_get_contents($this->projectDir.'/src/Calculator.php');
        $this->assertStringContainsString('declare(strict_types=1)', $content);
        $this->assertStringContainsString('function add(int $a, int $b): int', $content);
        $this->assertStringContainsString('function subtract(int $a, int $b): int', $content);
        $this->assertStringContainsString('function multiply(int $a, int $b): int', $content);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 2: Project Scaffolding
    //  Write 5 files → Glob verify → Bash run build
    // ──────────────────────────────────────────────────────────────

    public function test_project_scaffolding_multi_file_creation(): void
    {
        $composerJson = json_encode([
            'name' => 'demo/app',
            'autoload' => ['psr-4' => ['Demo\\' => 'src/']],
        ], JSON_PRETTY_PRINT);

        $run = $this->runHaoCodeCommand([
            '--print' => 'Scaffold a PHP project with src, tests, and config.',
        ], [
            // Turn 1: composer.json
            MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                'file_path' => 'myapp/composer.json',
                'content' => $composerJson,
            ]),
            // Turn 2: Main class
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w2', 'Write', [
                    'file_path' => 'myapp/src/App.php',
                    'content' => "<?php\nnamespace Demo;\nclass App {\n    public function run(): string { return 'OK'; }\n}\n",
                ]);
            },
            // Turn 3: Config
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w3', 'Write', [
                    'file_path' => 'myapp/config/app.php',
                    'content' => "<?php\nreturn ['name' => 'Demo', 'debug' => false];\n",
                ]);
            },
            // Turn 4: Test file
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w4', 'Write', [
                    'file_path' => 'myapp/tests/AppTest.php',
                    'content' => "<?php\necho (new Demo\\App)->run() === 'OK' ? 'PASS' : 'FAIL';\n",
                ]);
            },
            // Turn 5: README
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_w5', 'Write', [
                    'file_path' => 'myapp/README.md',
                    'content' => "# Demo App\nA scaffolded PHP project.\n",
                ]);
            },
            // Turn 6: Glob to verify all files
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_g1', 'Glob', [
                    'pattern' => 'myapp/**/*',
                ]);
            },
            // Turn 7: Check glob results
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('composer.json', $toolResult);
                $this->assertStringContainsString('App.php', $toolResult);
                $this->assertStringContainsString('AppTest.php', $toolResult);
                $this->assertStringContainsString('README.md', $toolResult);

                return MockAnthropicSse::textResponse('Project scaffolded: 5 files in src/, tests/, config/.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(7, $run['requests']);
        $this->assertFileExists($this->projectDir.'/myapp/composer.json');
        $this->assertFileExists($this->projectDir.'/myapp/src/App.php');
        $this->assertFileExists($this->projectDir.'/myapp/config/app.php');
        $this->assertFileExists($this->projectDir.'/myapp/tests/AppTest.php');
        $this->assertFileExists($this->projectDir.'/myapp/README.md');
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 3: Data Processing Pipeline
    //  Bash generate CSV → Write processor → Bash run → verify output
    // ──────────────────────────────────────────────────────────────

    public function test_data_processing_pipeline_csv_to_report(): void
    {
        $processor = <<<'PHP'
<?php
$csv = array_map('str_getcsv', file('data.csv'));
$header = array_shift($csv);
$total = 0; $count = 0;
foreach ($csv as $row) {
    $total += (float)$row[2];
    $count++;
}
$avg = $count > 0 ? round($total / $count, 2) : 0;
echo "Records: {$count}\n";
echo "Total: {$total}\n";
echo "Average: {$avg}\n";
echo "PIPELINE_COMPLETE\n";
PHP;

        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a CSV, process it, and generate a report.',
        ], [
            // Turn 1: Bash — generate CSV data
            MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                'command' => "printf 'name,category,value\nAlice,A,100\nBob,B,200\nCharlie,A,150\nDiana,C,300\nEve,B,250\n' > data.csv && cat data.csv",
                'description' => 'Generate CSV',
            ]),
            // Turn 2: Write the processor
            function (array $payload) use ($processor): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Alice', $toolResult);
                $this->assertStringContainsString('Eve', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                    'file_path' => 'process.php',
                    'content' => $processor,
                ]);
            },
            // Turn 3: Run the processor
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b2', 'Bash', [
                    'command' => 'php process.php',
                    'description' => 'Run data processor',
                ]);
            },
            // Turn 4: Verify and summarize
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Records: 5', $toolResult);
                $this->assertStringContainsString('Total: 1000', $toolResult);
                $this->assertStringContainsString('Average: 200', $toolResult);
                $this->assertStringContainsString('PIPELINE_COMPLETE', $toolResult);

                return MockAnthropicSse::textResponse('Pipeline complete: 5 records, total 1000, average 200.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(4, $run['requests']);
        $this->assertFileExists($this->projectDir.'/data.csv');
        $this->assertFileExists($this->projectDir.'/process.php');
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 4: Error Recovery Flow
    //  Write buggy code → Run fails → Read error → Edit fix → Rerun success
    // ──────────────────────────────────────────────────────────────

    public function test_error_recovery_detect_fix_rerun(): void
    {
        $run = $this->runHaoCodeCommand([
            '--print' => 'Create a script, fix any errors, and make it work.',
        ], [
            // Turn 1: Write code with a deliberate typo (ecco instead of echo)
            MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                'file_path' => 'buggy.php',
                'content' => "<?php\n\$x = 42;\n\$msg = 'The answer is ' . \$x;\necco(\$msg);\n",
            ]),
            // Turn 2: Run it — will fail
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                    'command' => 'php buggy.php 2>&1',
                    'description' => 'Run buggy script',
                ]);
            },
            // Turn 3: See the error, read the file
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('ecco', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                    'file_path' => $this->projectDir.'/buggy.php',
                ]);
            },
            // Turn 4: Rewrite fixed version
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('ecco', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w2', 'Write', [
                    'file_path' => 'buggy.php',
                    'content' => "<?php\n\$x = 42;\n\$msg = 'The answer is ' . \$x;\necho(\$msg);\n",
                ]);
            },
            // Turn 5: Rerun — should succeed
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b2', 'Bash', [
                    'command' => 'php buggy.php 2>&1',
                    'description' => 'Rerun fixed script',
                ]);
            },
            // Turn 6: Success
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('The answer is 42', $toolResult);

                return MockAnthropicSse::textResponse('Fixed the typo: ecco → echo. Script now outputs "The answer is 42".');
            },
        ]);


        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);

        $fixed = file_get_contents($this->projectDir.'/buggy.php');
        $this->assertStringContainsString('echo', $fixed);
        $this->assertStringNotContainsString('ecco', $fixed);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 5: Multi-file Search and Replace
    //  Write 3 files with old API → Grep find → Edit each → Grep verify
    // ──────────────────────────────────────────────────────────────

    public function test_multi_file_search_replace_via_grep_edit(): void
    {
        // Pre-create files with old API calls
        mkdir($this->projectDir.'/lib', 0755, true);
        file_put_contents($this->projectDir.'/lib/auth.php', "<?php\nOldApi::authenticate(\$user);\nOldApi::getToken();\n");
        file_put_contents($this->projectDir.'/lib/data.php', "<?php\n\$result = OldApi::fetchData(\$id);\nOldApi::cache(\$result);\n");
        file_put_contents($this->projectDir.'/lib/notify.php', "<?php\nOldApi::sendEmail(\$to, \$msg);\n");

        $run = $this->runHaoCodeCommand([
            '--print' => 'Replace all OldApi:: calls with NewApi:: across the lib/ directory.',
        ], [
            // Turn 1: Grep to find all occurrences
            MockAnthropicSse::toolUseResponse('toolu_g1', 'Grep', [
                'pattern' => 'OldApi::',
                'path' => 'lib',
                'output_mode' => 'content',
            ]),
            // Turn 2: Read auth.php
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('OldApi::authenticate', $toolResult);
                $this->assertStringContainsString('OldApi::sendEmail', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                    'file_path' => $this->projectDir.'/lib/auth.php',
                ]);
            },
            // Turn 3: Edit auth.php
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_e1', 'Edit', [
                    'file_path' => $this->projectDir.'/lib/auth.php',
                    'old_string' => 'OldApi::',
                    'new_string' => 'NewApi::',
                    'replace_all' => true,
                ]);
            },
            // Turn 4: Read + Edit data.php
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_r2', 'Read', [
                    'file_path' => $this->projectDir.'/lib/data.php',
                ]);
            },
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_e2', 'Edit', [
                    'file_path' => $this->projectDir.'/lib/data.php',
                    'old_string' => 'OldApi::',
                    'new_string' => 'NewApi::',
                    'replace_all' => true,
                ]);
            },
            // Turn 6: Read + Edit notify.php
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_r3', 'Read', [
                    'file_path' => $this->projectDir.'/lib/notify.php',
                ]);
            },
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_e3', 'Edit', [
                    'file_path' => $this->projectDir.'/lib/notify.php',
                    'old_string' => 'OldApi::',
                    'new_string' => 'NewApi::',
                    'replace_all' => true,
                ]);
            },
            // Turn 8: Grep again to verify zero remaining
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_g2', 'Grep', [
                    'pattern' => 'OldApi::',
                    'path' => 'lib',
                    'output_mode' => 'count',
                ]);
            },
            // Turn 9: Confirm zero matches
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('No matches', $toolResult);

                return MockAnthropicSse::textResponse('Replaced 5 OldApi:: calls with NewApi:: across 3 files. Zero remaining.');
            },
        ]);


        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(9, $run['requests']);

        // Verify all files updated
        $this->assertStringContainsString('NewApi::authenticate', file_get_contents($this->projectDir.'/lib/auth.php'));
        $this->assertStringContainsString('NewApi::fetchData', file_get_contents($this->projectDir.'/lib/data.php'));
        $this->assertStringContainsString('NewApi::sendEmail', file_get_contents($this->projectDir.'/lib/notify.php'));
        $this->assertStringNotContainsString('OldApi::', file_get_contents($this->projectDir.'/lib/auth.php'));
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 6: Documentation Generator
    //  Glob discover → Read each → Write compiled doc
    // ──────────────────────────────────────────────────────────────

    public function test_documentation_generator_from_source_files(): void
    {
        // Pre-create source files with docblocks
        mkdir($this->projectDir.'/api', 0755, true);
        file_put_contents($this->projectDir.'/api/users.php', "<?php\n/** Create a new user. POST /users */\nfunction createUser(\$name) {}\n/** Get user by ID. GET /users/{id} */\nfunction getUser(\$id) {}\n");
        file_put_contents($this->projectDir.'/api/orders.php', "<?php\n/** List all orders. GET /orders */\nfunction listOrders() {}\n/** Cancel an order. DELETE /orders/{id} */\nfunction cancelOrder(\$id) {}\n");

        $run = $this->runHaoCodeCommand([
            '--print' => 'Generate API documentation from source files.',
        ], [
            // Turn 1: Glob to discover API files
            MockAnthropicSse::toolUseResponse('toolu_g1', 'Glob', [
                'pattern' => 'api/*.php',
            ]),
            // Turn 2: Read users.php
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('users.php', $toolResult);
                $this->assertStringContainsString('orders.php', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                    'file_path' => $this->projectDir.'/api/users.php',
                ]);
            },
            // Turn 3: Read orders.php
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('POST /users', $toolResult);
                $this->assertStringContainsString('GET /users/{id}', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_r2', 'Read', [
                    'file_path' => $this->projectDir.'/api/orders.php',
                ]);
            },
            // Turn 4: Write compiled documentation
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('GET /orders', $toolResult);
                $this->assertStringContainsString('DELETE /orders/{id}', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                    'file_path' => 'API_DOCS.md',
                    'content' => "# API Documentation\n\n" .
                        "## Users\n" .
                        "| Method | Endpoint | Description |\n" .
                        "|--------|----------|-------------|\n" .
                        "| POST | /users | Create a new user |\n" .
                        "| GET | /users/{id} | Get user by ID |\n\n" .
                        "## Orders\n" .
                        "| Method | Endpoint | Description |\n" .
                        "|--------|----------|-------------|\n" .
                        "| GET | /orders | List all orders |\n" .
                        "| DELETE | /orders/{id} | Cancel an order |\n",
                ]);
            },
            // Turn 5: Done
            function (array $payload): MockResponse {
                return MockAnthropicSse::textResponse('Generated API_DOCS.md with 4 endpoints from 2 source files.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(5, $run['requests']);
        $this->assertFileExists($this->projectDir.'/API_DOCS.md');

        $docs = file_get_contents($this->projectDir.'/API_DOCS.md');
        $this->assertStringContainsString('POST', $docs);
        $this->assertStringContainsString('GET', $docs);
        $this->assertStringContainsString('DELETE', $docs);
        $this->assertStringContainsString('/users/{id}', $docs);
        $this->assertStringContainsString('/orders/{id}', $docs);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 7: CI/CD Simulation
    //  Bash lint fails → Read → Edit fix → Bash lint passes → Bash test
    // ──────────────────────────────────────────────────────────────

    public function test_ci_cd_lint_fix_test_pipeline(): void
    {
        // Pre-create a file with a syntax error
        file_put_contents($this->projectDir.'/app.php', "<?php\nfunction greet(\$name) {\n    echo \"Hello, \$name!\";\n    return true\n}\n");

        $run = $this->runHaoCodeCommand([
            '--print' => 'Run CI: lint, fix errors, then verify.',
        ], [
            // Turn 1: Lint — will fail (missing semicolon)
            MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                'command' => 'php -l app.php 2>&1',
                'description' => 'Lint check',
            ]),
            // Turn 2: See error, read file
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Parse error', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                    'file_path' => $this->projectDir.'/app.php',
                ]);
            },
            // Turn 3: Fix — add missing semicolon
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_e1', 'Edit', [
                    'file_path' => $this->projectDir.'/app.php',
                    'old_string' => '    return true',
                    'new_string' => '    return true;',
                ]);
            },
            // Turn 4: Lint again — should pass
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b2', 'Bash', [
                    'command' => 'php -l app.php 2>&1',
                    'description' => 'Re-lint',
                ]);
            },
            // Turn 5: Run the function
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('No syntax errors', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_b3', 'Bash', [
                    'command' => "php -r 'require \"app.php\"; greet(\"World\");'",
                    'description' => 'Run function',
                ]);
            },
            // Turn 6: Verify output
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('Hello, World!', $toolResult);

                return MockAnthropicSse::textResponse('CI passed: lint fixed, function verified.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(6, $run['requests']);

        $fixed = file_get_contents($this->projectDir.'/app.php');
        $this->assertStringContainsString('return true;', $fixed);
    }

    // ──────────────────────────────────────────────────────────────
    //  Test 8: JSON Config Migration
    //  Read old format → Write new format → Bash validate → Grep verify keys
    // ──────────────────────────────────────────────────────────────

    public function test_json_config_format_migration(): void
    {
        // Pre-create old config format
        file_put_contents($this->projectDir.'/config.old.json', json_encode([
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_name' => 'myapp',
            'cache_ttl' => 3600,
            'debug_mode' => true,
        ], JSON_PRETTY_PRINT));

        $newConfig = json_encode([
            'database' => [
                'host' => 'localhost',
                'port' => 3306,
                'name' => 'myapp',
            ],
            'cache' => ['ttl' => 3600],
            'app' => ['debug' => true],
        ], JSON_PRETTY_PRINT);

        $run = $this->runHaoCodeCommand([
            '--print' => 'Migrate config.old.json to a nested structure.',
        ], [
            // Turn 1: Read old config
            MockAnthropicSse::toolUseResponse('toolu_r1', 'Read', [
                'file_path' => $this->projectDir.'/config.old.json',
            ]),
            // Turn 2: Write new format
            function (array $payload) use ($newConfig): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('db_host', $toolResult);
                $this->assertStringContainsString('3306', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_w1', 'Write', [
                    'file_path' => 'config.json',
                    'content' => $newConfig,
                ]);
            },
            // Turn 3: Bash validate JSON
            function (array $payload): MockResponse {
                return MockAnthropicSse::toolUseResponse('toolu_b1', 'Bash', [
                    'command' => 'php -r "json_decode(file_get_contents(\'config.json\'), true, 512, JSON_THROW_ON_ERROR); echo \'VALID JSON\';"',
                    'description' => 'Validate JSON',
                ]);
            },
            // Turn 4: Grep verify nested structure
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('VALID JSON', $toolResult);

                return MockAnthropicSse::toolUseResponse('toolu_grep', 'Grep', [
                    'pattern' => '"database"|"cache"|"app"',
                    'path' => 'config.json',
                    'output_mode' => 'content',
                ]);
            },
            // Turn 5: Verify all sections present
            function (array $payload): MockResponse {
                $toolResult = MockAnthropicSse::lastToolResultText($payload);
                $this->assertStringContainsString('database', $toolResult);
                $this->assertStringContainsString('cache', $toolResult);
                $this->assertStringContainsString('app', $toolResult);

                return MockAnthropicSse::textResponse('Config migrated from flat to nested format. 3 sections: database, cache, app.');
            },
        ]);

        $this->assertSame(0, $run['exit_code']);
        $this->assertCount(5, $run['requests']);

        $config = json_decode(file_get_contents($this->projectDir.'/config.json'), true);
        $this->assertSame('localhost', $config['database']['host']);
        $this->assertSame(3306, $config['database']['port']);
        $this->assertSame(3600, $config['cache']['ttl']);
        $this->assertTrue($config['app']['debug']);
    }

    // ══════════════════════════════════════════════════════════════
    //  Infrastructure
    // ══════════════════════════════════════════════════════════════

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
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}

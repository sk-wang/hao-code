#!/usr/bin/env php
<?php

/**
 * Code Review Agent Demo
 *
 * A practical example using the HaoCode SDK to build an automated
 * code review agent with custom tools and skills.
 *
 * The agent:
 * 1. Uses a custom DatabaseQueryTool to check code standards from a DB
 * 2. Uses a custom GitDiffTool to get recent changes
 * 3. Uses a "code-review" skill with review criteria
 * 4. Streams results in real-time
 * 5. Writes a structured review report
 *
 * Usage:
 *   php examples/code-review-agent.php
 *
 * Prerequisites:
 *   - composer install
 *   - ANTHROPIC_API_KEY set in .env or ~/.haocode/settings.json
 */

// Bootstrap Laravel application
$packageRoot = dirname(__DIR__);
require_once $packageRoot . '/vendor/autoload.php';

$pathResolver = new \App\Support\Runtime\StoragePathResolver;
$storagePath = $pathResolver->resolve(packageRoot: $packageRoot, autoloadPath: $packageRoot . '/vendor/autoload.php');
if ($storagePath) {
    if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);
    putenv("LARAVEL_STORAGE_PATH={$storagePath}");
    $_ENV['LARAVEL_STORAGE_PATH'] = $storagePath;
    $_SERVER['LARAVEL_STORAGE_PATH'] = $storagePath;
}

$app = require $packageRoot . '/bootstrap/app.php';
if ($storagePath) $app->useStoragePath($storagePath);
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Sdk\HaoCode;
use App\Sdk\HaoCodeConfig;
use App\Sdk\SdkTool;
use App\Sdk\SdkSkill;

// ─── Custom Tools ────────────────────────────────────────────────

/**
 * Simulates querying a coding standards database.
 * In production, this would hit a real database.
 */
class CodingStandardsTool extends SdkTool
{
    public function name(): string
    {
        return 'GetCodingStandards';
    }

    public function description(): string
    {
        return 'Get coding standards and rules for a given language.';
    }

    public function parameters(): array
    {
        return [
            'language' => [
                'type' => 'string',
                'description' => 'Programming language (e.g., php, javascript)',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $standards = [
            'php' => [
                'PSR-12 coding style',
                'Strict types declaration required',
                'No unused imports',
                'Methods must have return type declarations',
                'Properties must have type declarations',
                'Max method length: 30 lines',
                'Max class length: 300 lines',
                'No static methods except factories',
            ],
            'javascript' => [
                'ESLint airbnb config',
                'Prefer const over let',
                'No var declarations',
                'Arrow functions for callbacks',
                'Async/await over raw promises',
            ],
        ];

        $lang = strtolower($input['language']);
        $rules = $standards[$lang] ?? ['No specific standards found for this language.'];

        return "Coding standards for {$lang}:\n" . implode("\n", array_map(
            fn ($r, $i) => ($i + 1) . ". {$r}",
            $rules,
            array_keys($rules),
        ));
    }
}

/**
 * Simulates getting a git diff summary.
 * In production, this would run actual git commands.
 */
class GitDiffSummaryTool extends SdkTool
{
    public function name(): string
    {
        return 'GetGitDiffSummary';
    }

    public function description(): string
    {
        return 'Get a summary of recent git changes for review.';
    }

    public function parameters(): array
    {
        return [
            'commits' => [
                'type' => 'integer',
                'description' => 'Number of recent commits to summarize',
            ],
        ];
    }

    public function handle(array $input): string
    {
        // Simulated diff summary
        return <<<DIFF
Recent changes (last 3 commits):

1. feat: add user authentication
   - app/Services/AuthService.php (+85 lines)
   - app/Http/Controllers/LoginController.php (+42 lines)
   - app/Http/Middleware/AuthMiddleware.php (+28 lines)

2. fix: resolve N+1 query in order listing
   - app/Repositories/OrderRepository.php (~12 lines)

3. refactor: extract email validation
   - app/Rules/EmailValidator.php (+35 lines)
   - app/Http/Requests/RegisterRequest.php (~8 lines)

Total: 6 files changed, +210 lines, ~20 lines modified
DIFF;
    }
}

// ─── Custom Skill ────────────────────────────────────────────────

$reviewSkill = new SdkSkill(
    name: 'code-review',
    description: 'Perform a structured code review with scoring',
    prompt: <<<'PROMPT'
Perform a structured code review. For each file changed:

1. **Security**: Check for injection, XSS, auth bypass, sensitive data exposure
2. **Performance**: Check for N+1 queries, unnecessary loops, missing indexes
3. **Style**: Check against the team's coding standards (use GetCodingStandards tool)
4. **Architecture**: Check for proper separation of concerns, SOLID principles

Output a review report with:
- Overall score (1-10)
- Per-file findings (severity: critical/warning/info)
- Suggested fixes
- Praise for good patterns

$ARGUMENTS
PROMPT,
);

// ─── Run the Agent ───────────────────────────────────────────────

echo "╔══════════════════════════════════════╗\n";
echo "║     Code Review Agent Demo           ║\n";
echo "╚══════════════════════════════════════╝\n\n";

// Example 1: One-shot query with custom tools
echo "── Example 1: One-shot query with tools ──\n\n";

$result = HaoCode::query(
    'Get the PHP coding standards for our team.',
    new HaoCodeConfig(
        tools: [new CodingStandardsTool()],
        maxTurns: 5,
        onToolStart: fn (string $name, array $input) => print("  ⚙ {$name}\n"),
        onToolComplete: fn (string $name, $result) => print("  ✓ done\n"),
    ),
);

echo "\n{$result}\n";
echo "Cost: \${$result->cost}\n\n";

// Example 2: Streaming with skill + tools
echo "── Example 2: Streaming code review ──\n\n";

foreach (HaoCode::stream(
    'Review the recent code changes using the code-review skill.',
    new HaoCodeConfig(
        tools: [new CodingStandardsTool(), new GitDiffSummaryTool()],
        skills: [$reviewSkill],
        maxTurns: 10,
    ),
) as $msg) {
    match ($msg->type) {
        'text'        => print($msg->text),
        'tool_start'  => print("\n  ⚙ {$msg->toolName}...\n"),
        'tool_result' => null,
        'result'      => print("\n\n── Cost: \${$msg->cost} | Tokens: {$msg->usage['input_tokens']}in/{$msg->usage['output_tokens']}out ──\n"),
        'error'       => print("\n❌ Error: {$msg->error}\n"),
        default       => null,
    };
}

// Example 3: Multi-turn conversation
echo "\n── Example 3: Multi-turn conversation ──\n\n";

$conv = HaoCode::conversation(new HaoCodeConfig(
    tools: [new CodingStandardsTool()],
    maxTurns: 5,
    appendSystemPrompt: 'You are a senior code reviewer. Be concise.',
));

$r1 = $conv->send('What are our PHP coding standards?');
echo "Turn 1: {$r1->text}\n\n";

$r2 = $conv->send('Which of these are most commonly violated?');
echo "Turn 2: {$r2->text}\n\n";

echo "Total cost: \${$conv->getCost()}\n";
echo "Turns used: {$conv->getTurnCount()}\n";

$conv->close();

// Example 4: Structured output
echo "\n── Example 4: Structured review score ──\n\n";

try {
    $score = HaoCode::structured(
        'Rate this PHP code on quality (1-10). The code has a SQL injection vulnerability: it concatenates user input directly into a SQL query instead of using parameter binding.',
        [
            'type' => 'object',
            'properties' => [
                'score'    => ['type' => 'integer', 'description' => 'Quality score 1-10'],
                'severity' => ['type' => 'string', 'enum' => ['critical', 'warning', 'info', 'good']],
                'issues'   => ['type' => 'array', 'items' => ['type' => 'string']],
                'fix'      => ['type' => 'string', 'description' => 'One-line suggested fix'],
            ],
            'required' => ['score', 'severity', 'issues'],
        ],
        new HaoCodeConfig(maxTurns: 3),
    );

    echo "Score: {$score->score}/10\n";
    echo "Severity: {$score->severity}\n";
    echo "Issues:\n";
    foreach ($score->issues ?? [] as $issue) {
        echo "  - {$issue}\n";
    }
    if ($score->fix) {
        echo "Fix: {$score->fix}\n";
    }
} catch (\Throwable $e) {
    echo "Structured output failed (model didn't return pure JSON): " . $e->getMessage() . "\n";
}

echo "\n✅ Demo complete.\n";

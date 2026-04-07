# Hao Code PHP SDK

Use hao-code as a PHP library to embed an AI coding agent in any Laravel application.

```bash
composer require sk-wang/hao-code
```

The SDK auto-registers its service provider. Set your API key in `.env`:

```env
ANTHROPIC_API_KEY=your-api-key
```

---

## Table of Contents

- [Quick Start](#quick-start)
- [HaoCode API Reference](#haocode-api-reference)
  - [query()](#query)
  - [stream()](#stream)
  - [conversation()](#conversation)
  - [resume() / continueLatest()](#resume--continuelatest)
  - [structured()](#structured)
- [HaoCodeConfig Reference](#haocodeconfig-reference)
- [QueryResult](#queryresult)
- [Custom Tools (SdkTool)](#custom-tools-sdktool)
- [Custom Skills (SdkSkill)](#custom-skills-sdkskill)
- [Streaming Messages](#streaming-messages)
- [Multi-turn Conversations](#multi-turn-conversations)
- [Session Resume & Continue](#session-resume--continue)
- [Structured Output](#structured-output)
- [Abort Controller](#abort-controller)
- [Cost Tracking](#cost-tracking)
- [Combining Tools + Skills](#combining-tools--skills)
- [Testing](#testing)

---

## Quick Start

```php
use App\Sdk\HaoCode;

// One line — ask the agent anything
$result = HaoCode::query('What files are in this directory?');
echo $result;
```

---

## HaoCode API Reference

### query()

Execute a one-shot query. Returns a [`QueryResult`](#queryresult) (implements `Stringable`).

```php
HaoCode::query(string $prompt, ?HaoCodeConfig $config = null): QueryResult
```

```php
$result = HaoCode::query('Explain the auth system');

echo $result;            // response text (Stringable)
echo $result->text;      // same as above, explicit
echo $result->cost;      // estimated cost in USD
echo $result->usage;     // ['input_tokens' => ..., 'output_tokens' => ...]
echo $result->sessionId; // session ID for later resume
```

### stream()

Execute a query and yield typed [`Message`](#streaming-messages) objects as they arrive.

```php
HaoCode::stream(string $prompt, ?HaoCodeConfig $config = null): Generator<Message>
```

```php
foreach (HaoCode::stream('Build a REST API') as $msg) {
    match ($msg->type) {
        'text'        => print($msg->text),
        'tool_start'  => print("⚙ {$msg->toolName}\n"),
        'tool_result' => print("  ✓ done\n"),
        'result'      => print("\nCost: \${$msg->cost}\n"),
        'error'       => print("Error: {$msg->error}\n"),
        default       => null,
    };
}
```

### conversation()

Create a multi-turn conversation with persistent context.

```php
HaoCode::conversation(?HaoCodeConfig $config = null): Conversation
```

```php
$conv = HaoCode::conversation();

$r1 = $conv->send('Create a User model');
echo $r1->text;

$r2 = $conv->send('Add email validation');  // remembers User model
echo $r2->text;

$conv->close();
```

### resume() / continueLatest()

Resume a previous session by ID, or continue the most recent one.

```php
HaoCode::resume(string $sessionId, ?HaoCodeConfig $config = null): Conversation
HaoCode::continueLatest(?string $cwd = null, ?HaoCodeConfig $config = null): Conversation
```

```php
// Resume by ID
$conv = HaoCode::resume('20260407_143022_a1b2c3d4');
$conv->send('Where were we?');

// Continue the latest session in current directory
$conv = HaoCode::continueLatest();
$conv->send('Continue the refactoring');
```

Also works inline via config:

```php
// Resume via config
$result = HaoCode::query('Continue', new HaoCodeConfig(sessionId: 'abc123'));

// Auto-continue latest
$result = HaoCode::query('What were we doing?', new HaoCodeConfig(continueSession: true));
```

### structured()

Extract structured (JSON) data from the agent's response.

```php
HaoCode::structured(string $prompt, array $jsonSchema, ?HaoCodeConfig $config = null): StructuredResult
```

```php
$result = HaoCode::structured('Classify this ticket: "My order is late"', [
    'type' => 'object',
    'properties' => [
        'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical']],
        'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high']],
        'summary'  => ['type' => 'string'],
    ],
    'required' => ['category', 'priority', 'summary'],
]);

echo $result->category;   // 'shipping'
echo $result['priority'];  // 'high' (ArrayAccess)
$result->toArray();        // ['category' => 'shipping', ...]
$result->toJson();         // '{"category":"shipping",...}'
```

---

## HaoCodeConfig Reference

All parameters are optional. Pass as named arguments:

```php
$config = new HaoCodeConfig(
    apiKey: 'your-key',
    model: 'claude-sonnet-4-20250514',
    // ...
);
```

### API Connection

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `apiKey` | `?string` | `null` | Anthropic API key. Falls back to `config('haocode.api_key')` |
| `model` | `?string` | `null` | Model ID. Falls back to config default |
| `baseUrl` | `?string` | `null` | API endpoint URL (for proxies, custom endpoints) |
| `maxTokens` | `?int` | `null` | Maximum output tokens per response |

When any of these are set, the SDK creates a standalone HTTP client (bypassing global settings).

### Agent Behavior

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `cwd` | `?string` | `null` | Working directory for tool execution. Defaults to `getcwd()` |
| `maxTurns` | `int` | `50` | Maximum agent turns (tool-use round trips) |
| `maxBudgetUsd` | `?float` | `null` | Cost limit in USD. Agent stops when exceeded |
| `permissionMode` | `string` | `'bypass_permissions'` | `'default'`, `'plan'`, `'accept_edits'`, `'bypass_permissions'` |

### Prompts

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `systemPrompt` | `?string` | `null` | Replace the default system prompt entirely |
| `appendSystemPrompt` | `?string` | `null` | Append text to the default system prompt |

### Tools & Skills

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `allowedTools` | `string[]` | `['*']` | Tools to allow. `['*']` = all |
| `disallowedTools` | `string[]` | `[]` | Tools to deny (takes precedence over allowed) |
| `tools` | `SdkTool[]` | `[]` | Custom tools to register |
| `skills` | `SdkSkill[]` | `[]` | Custom skills to register |

### Callbacks

| Parameter | Type | Description |
|-----------|------|-------------|
| `onText` | `?callable` | `fn(string $delta): void` — streaming text chunk |
| `onToolStart` | `?callable` | `fn(string $toolName, array $input): void` — tool began |
| `onToolComplete` | `?callable` | `fn(string $toolName, ToolResult $result): void` — tool finished |
| `onTurnStart` | `?callable` | `fn(int $turnNumber): void` — new agent turn |

### Advanced

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `abortController` | `?AbortController` | `null` | Cancellation handle |
| `sessionId` | `?string` | `null` | Resume a previous session in `query()`/`stream()` |
| `continueSession` | `bool` | `false` | Auto-continue latest session in `query()`/`stream()` |
| `thinkingEnabled` | `bool` | `false` | Enable extended thinking |
| `thinkingBudget` | `int` | `10000` | Thinking token budget |

### Factory Method

```php
// Minimal config shorthand
$config = HaoCodeConfig::make(apiKey: 'key', model: 'claude-haiku');
```

---

## QueryResult

Returned by `HaoCode::query()` and `Conversation::send()`. Implements `Stringable`.

```php
$result = HaoCode::query('Hello');

// As string (Stringable)
echo $result;                    // prints response text
echo "Answer: " . $result;      // string concatenation works

// Properties
$result->text;                   // string — response text
$result->usage;                  // array — ['input_tokens' => int, 'output_tokens' => int, ...]
$result->cost;                   // float — estimated cost in USD
$result->sessionId;              // ?string — session ID for resume
$result->turnsUsed;              // int — agent turns consumed

// Helpers
$result->inputTokens();          // int
$result->outputTokens();         // int
```

---

## Custom Tools (SdkTool)

Define domain-specific tools the agent can call. Implement 4 methods:

```php
use App\Sdk\SdkTool;

class LookupOrderTool extends SdkTool
{
    public function name(): string
    {
        return 'LookupOrder';
    }

    public function description(): string
    {
        return 'Look up an order by ID from the database.';
    }

    public function parameters(): array
    {
        return [
            'order_id' => [
                'type' => 'string',
                'description' => 'The order ID to look up',
                'required' => true,
            ],
        ];
    }

    public function handle(array $input): string
    {
        $order = Order::findOrFail($input['order_id']);
        return $order->toJson();
    }
}
```

Use it:

```php
$result = HaoCode::query('Find order #12345 and check its status', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

### Parameter Format

Each parameter is `name => options`:

```php
public function parameters(): array
{
    return [
        'city' => [
            'type' => 'string',           // string|integer|number|boolean|array|object
            'description' => 'City name', // shown to the model
            'required' => true,           // default: false
            'enum' => ['NYC', 'LA'],      // optional: restrict values
        ],
    ];
}
```

### Error Handling

Exceptions in `handle()` are caught and sent back to the model as error messages:

```php
public function handle(array $input): string
{
    throw new \RuntimeException('Database connection refused');
    // → model receives: "Database connection refused" as tool error
    // → model can retry or explain the failure to the user
}
```

### Stateful Tools

By default, `SdkTool` is read-only (may be fork-executed in parallel). For stateful tools, override `isReadOnly`:

```php
class ShoppingCart extends SdkTool
{
    private array $items = [];

    public function handle(array $input): string
    {
        $this->items[] = $input['item'];
        return 'Cart: ' . implode(', ', $this->items);
    }

    // Required for state to persist across calls!
    public function isReadOnly(array $input): bool
    {
        return false;
    }
}
```

---

## Custom Skills (SdkSkill)

Skills are named prompt templates the agent can invoke. Unlike tools (which execute PHP code), skills inject instructions that guide the agent's behavior.

```php
use App\Sdk\SdkSkill;

$skill = new SdkSkill(
    name: 'security-review',
    description: 'Review code for OWASP vulnerabilities',
    prompt: 'Review $ARGUMENTS for injection, XSS, auth bypass, and other OWASP Top 10 issues.',
    allowedTools: ['Read', 'Grep'],  // optional: restrict tools during skill
    model: 'opus',                    // optional: model override
);

$result = HaoCode::query('Review auth.php for security', new HaoCodeConfig(
    skills: [$skill],
));
```

### Skill vs Tool

| | SdkSkill | SdkTool |
|---|---|---|
| What it is | Named prompt template | Executable PHP code |
| How agent uses it | Invokes via `SkillTool`, gets expanded prompt | Calls `handle()` directly |
| `$ARGUMENTS` support | Yes | No |
| Appears in system prompt | Yes (Available skills list) | Yes (API tools list) |
| Can restrict tools | Yes (`allowedTools`) | No |
| Returns | Expanded prompt text | `handle()` return string |

---

## Streaming Messages

`HaoCode::stream()` yields `Message` objects with these types:

| Type | Fields | Description |
|------|--------|-------------|
| `text` | `$msg->text` | Streaming text delta |
| `tool_start` | `$msg->toolName`, `$msg->toolInput` | Tool execution began |
| `tool_result` | `$msg->toolName`, `$msg->toolOutput`, `$msg->toolIsError` | Tool completed |
| `result` | `$msg->text`, `$msg->usage`, `$msg->cost`, `$msg->sessionId` | Final result |
| `error` | `$msg->error` | An error occurred |

```php
foreach (HaoCode::stream('Refactor the auth module') as $msg) {
    if ($msg->type === 'text') {
        echo $msg->text;  // stream to browser / CLI
    }

    if ($msg->type === 'tool_start') {
        Log::info("Agent using tool: {$msg->toolName}");
    }

    if ($msg->isResult()) {
        DB::table('usage')->insert([
            'tokens' => $msg->usage['input_tokens'] + $msg->usage['output_tokens'],
            'cost'   => $msg->cost,
        ]);
    }

    if ($msg->isError()) {
        Log::error("Agent error: {$msg->error}");
    }
}
```

---

## Multi-turn Conversations

`Conversation` maintains persistent context across multiple `send()` calls:

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    tools: [new MyDatabaseTool()],
    maxTurns: 20,
));

// Turn 1 — agent creates a file
$r1 = $conv->send('Create a User model with name, email, and password');
echo $r1->text;

// Turn 2 — agent remembers the User model from turn 1
$r2 = $conv->send('Add email validation and password hashing');
echo $r2->text;

// Turn 3 — agent knows about both previous changes
$r3 = $conv->send('Write a PHPUnit test for the User model');
echo $r3->text;

// Metadata
echo $conv->getTurnCount();   // 3
echo $conv->getCost();        // cumulative cost
echo $conv->getSessionId();   // session ID

// Streaming within conversation
foreach ($conv->stream('Add a factory for the User model') as $msg) {
    if ($msg->type === 'text') echo $msg->text;
}

$conv->close();  // no more sends allowed
```

---

## Session Resume & Continue

Sessions are persisted as JSONL files. Resume from any process:

```php
// First process — create something
$result = HaoCode::query('Create a Laravel migration for orders table');
$sessionId = $result->sessionId;  // save this

// Later process — resume where we left off
$conv = HaoCode::resume($sessionId);
$conv->send('Add a foreign key to users table');

// Or just continue the latest session in the current directory
$conv = HaoCode::continueLatest();
$conv->send('What were we working on?');
```

---

## Structured Output

Extract typed data from AI responses:

```php
// Classify a support ticket
$ticket = HaoCode::structured(
    'Classify: "I was charged twice for my subscription"',
    [
        'type' => 'object',
        'properties' => [
            'category' => ['type' => 'string', 'enum' => ['billing', 'shipping', 'technical', 'account']],
            'priority' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
            'summary'  => ['type' => 'string'],
            'action'   => ['type' => 'string'],
        ],
        'required' => ['category', 'priority', 'summary'],
    ],
);

echo $ticket->category;     // 'billing'
echo $ticket->priority;     // 'high'
echo $ticket['summary'];    // 'Customer reports duplicate charge' (ArrayAccess)
$ticket->toArray();          // full array
$ticket->toJson();           // JSON string

// Access underlying QueryResult for cost/usage
echo $ticket->queryResult->cost;
```

---

## Abort Controller

Cancel long-running operations from external code:

```php
use App\Sdk\AbortController;

$abort = new AbortController();

// In a queued job:
$result = HaoCode::query('Refactor the entire codebase', new HaoCodeConfig(
    abortController: $abort,
));

// From a signal handler, timeout, or another thread:
$abort->abort();

// Register cleanup callbacks
$abort->onAbort(function () {
    Log::info('Agent operation was cancelled');
});
```

Works with conversations too:

```php
$conv = HaoCode::conversation(new HaoCodeConfig(
    abortController: $abort,
));
$conv->send('Long running task...');
// $abort->abort() will stop the agent mid-execution
```

---

## Cost Tracking

### Per-query cost

```php
$result = HaoCode::query('Analyze this codebase');
echo "Cost: \${$result->cost}";
echo "Input tokens: {$result->inputTokens()}";
echo "Output tokens: {$result->outputTokens()}";
```

### Budget limits

```php
$result = HaoCode::query('Do a big refactoring', new HaoCodeConfig(
    maxBudgetUsd: 5.00,  // stop if cost exceeds $5
));
// Agent auto-stops at 80% ($4.00 warning) and 100% ($5.00 hard stop)
```

### Conversation cumulative cost

```php
$conv = HaoCode::conversation();
$conv->send('Step 1');
$conv->send('Step 2');
echo "Total cost: \${$conv->getCost()}";
```

---

## Combining Tools + Skills

Tools and skills can be used together in a single query:

```php
$result = HaoCode::query('Run a full system health check', new HaoCodeConfig(
    // Custom tool — executes PHP code
    tools: [
        new DatabaseHealthTool(),
        new CacheHealthTool(),
    ],
    // Custom skill — injects a prompt template
    skills: [
        new SdkSkill(
            name: 'health-report',
            description: 'Generate a health report',
            prompt: 'Check all systems using the available health tools, then write a report to health-report.md.',
        ),
    ],
));
```

---

## Testing

The SDK is testable with mock HTTP responses. The test infrastructure uses `MockAnthropicSse` to simulate API responses without real API calls:

```php
use App\Sdk\HaoCode;
use App\Sdk\HaoCodeConfig;
use App\Services\Api\StreamingClient;
use Tests\Support\MockAnthropicSse;

// In your test:
$this->app->singleton(StreamingClient::class, function ($app) {
    $requests = [];
    return new StreamingClient(
        apiKey: 'test-key',
        model: 'claude-test',
        baseUrl: 'https://mock.test',
        maxTokens: 4096,
        httpClient: MockAnthropicSse::client([
            MockAnthropicSse::textResponse('Mocked response.'),
        ], $requests),
        settingsManager: null,
    );
});

$result = HaoCode::query('Test prompt');
$this->assertStringContainsString('Mocked response', $result->text);
```

See `tests/Feature/SdkE2ETest.php` for 34 comprehensive examples.

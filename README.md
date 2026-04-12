# Hao Code

A PHP Agent SDK and interactive CLI for Anthropic-compatible APIs.

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)

`SDK` · `Streaming` · `Sub-agents` · `Teams` · `REPL` · `Hooks` · `Skills` · `Session HUD`

---

## Why Another Agent SDK?

There are plenty of AI coding CLIs — but **PHP agent SDKs barely exist**. If you're building in PHP/Laravel and want to embed an AI agent that can actually *do things* (read files, run commands, search code, coordinate sub-agents), your options are thin.

Hao Code gives you a **fully-featured agent as a Composer package** — not a thin HTTP wrapper, but a real multi-turn agent with 30+ built-in tools, streaming, session persistence, custom tools/skills, cost tracking, and abort control.

```bash
composer require sk-wang/hao-code
```

```php
use App\Sdk\{HaoCode, HaoCodeConfig};

// One-shot
$result = HaoCode::query('Explain this codebase');
echo $result;        // response text
echo $result->cost;  // $0.03

// Streaming
foreach (HaoCode::stream('Build a REST API') as $msg) {
    if ($msg->type === 'text') echo $msg->text;
}

// Multi-turn
$conv = HaoCode::conversation();
$conv->send('Create a User model');
$conv->send('Add email validation');
$conv->close();

// Structured output
$data = HaoCode::structured('Classify this ticket', $jsonSchema);
echo $data->category;  // 'shipping'

// Custom tools — your PHP code, callable by the agent
$result = HaoCode::query('Find order #123', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

**[Full SDK Documentation →](docs/SDK.md)**

<details>
<summary><strong>SDK feature overview</strong></summary>

| Feature | API |
|---------|-----|
| One-shot query | `HaoCode::query()` |
| Streaming | `HaoCode::stream()` |
| Multi-turn conversation | `HaoCode::conversation()` |
| Session resume/continue | `HaoCode::resume()` / `HaoCode::continueLatest()` |
| Structured JSON output | `HaoCode::structured()` |
| Custom tools (PHP code) | `SdkTool` — 4 methods to implement |
| Custom skills (prompt templates) | `SdkSkill` — named prompts with `$ARGUMENTS` |
| Abort control | `AbortController` — cancel from outside |
| Cost tracking | `$result->cost`, `$result->usage`, `maxBudgetUsd` |
| Streaming callbacks | `onText`, `onToolStart`, `onToolComplete`, `onTurnStart` |
| Multi-provider | Anthropic, ZAI, or any OpenAI-compatible endpoint |

</details>

<details>
<summary><strong>Custom tool example — 30 lines of PHP</strong></summary>

```php
use App\Sdk\SdkTool;

class LookupOrderTool extends SdkTool
{
    public function name(): string { return 'LookupOrder'; }
    public function description(): string { return 'Look up an order by ID.'; }

    public function parameters(): array
    {
        return ['order_id' => ['type' => 'string', 'description' => 'Order ID', 'required' => true]];
    }

    public function handle(array $input): string
    {
        return Order::findOrFail($input['order_id'])->toJson();
    }
}

// Agent now has access to your database
$result = HaoCode::query('Check order #12345 status', new HaoCodeConfig(
    tools: [new LookupOrderTool()],
));
```

</details>

---

## Also a CLI

Install globally for an interactive coding agent in the terminal:

![Hao Code terminal screenshot](docs/images/hao-code-terminal.png)

```bash
composer global require sk-wang/hao-code
export PATH="$(composer global config bin-dir --absolute):$PATH"
```

Configure API key:

```bash
mkdir -p ~/.haocode
cat > ~/.haocode/settings.json <<'JSON'
{
  "api_key": "your-api-key-here"
}
JSON
```

Launch:

```bash
hao-code                                          # Interactive REPL
hao-code --print="Explain AgentLoop.php"          # Single-shot
hao-code --continue                               # Resume latest session
hao-code --resume=20260404_abcdef12               # Resume specific session
hao-code --resume=ID --fork-session --name="alt"  # Fork into new branch
```

### CLI flags

| Flag | Purpose |
| --- | --- |
| `-p, --print=` | Run once and exit |
| `-c, --continue` | Reopen latest session |
| `-r, --resume=` | Restore session by ID |
| `--fork-session` | Branch into new transcript |
| `--name=` | Set session display name |
| `--model=` | Override model |
| `--system-prompt=` | Replace system prompt |
| `--append-system-prompt=` | Append extra instructions |
| `--permission-mode=` | Override permission mode |

---

## Built-in Tools

The agent ships with 30+ tools available in both SDK and CLI:

| Group | Tools |
| --- | --- |
| **Shell & Files** | Bash, Read, Edit, Write, Glob, Grep |
| **Agents & Planning** | Agent, SendMessage, TodoWrite, EnterPlanMode, ExitPlanMode |
| **Teams** | TeamCreate, TeamList, TeamDelete |
| **Tasks & Automation** | TaskCreate/Get/List/Update/Stop, CronCreate/Delete/List, Sleep |
| **Code Intelligence** | LspTool, NotebookEdit, EnterWorktree, ExitWorktree |
| **Web & Interaction** | WebSearch, WebFetch, AskUserQuestion, ToolSearch, Skill, Config |

---

## Configuration

Settings are read from `~/.haocode/settings.json` (global) and `.haocode/settings.json` (project).

```json
{
  "api_key": "sk-ant-...",
  "model": "claude-sonnet-4-20250514",
  "permission_mode": "default",
  "stream_output": false
}
```

Multi-provider example:

```json
{
  "active_provider": "zai",
  "provider": {
    "anthropic": {
      "api_key": "sk-ant-...",
      "model": "claude-sonnet-4-20250514"
    },
    "zai": {
      "api_key": "your-zai-key",
      "api_base_url": "https://api.z.ai/api/anthropic",
      "model": "glm-5.1"
    }
  }
}
```

Auto-loaded project files: `HAOCODE.md`, `CLAUDE.md`, `.haocode/rules/*.md`, `.haocode/memory/MEMORY.md`

---

## Slash Commands (CLI)

<details>
<summary><strong>Session</strong> — /help /exit /clear /history /resume /branch /rewind /snapshot /transcript /search</summary>
</details>

<details>
<summary><strong>Context & Output</strong> — /status /statusline /stats /context /cost /model /provider /fast /theme /output-style</summary>
</details>

<details>
<summary><strong>Workspace</strong> — /files /diff /commit /review /memory /config /permissions /hooks /skills /mcp /init /doctor /version</summary>
</details>

<details>
<summary><strong>Planning</strong> — /plan /tasks /loop</summary>
</details>

---

## Permissions and Hooks

**Permission modes:** `default` (confirm dangerous ops) · `plan` (read-only) · `accept_edits` (auto-accept file edits) · `bypass_permissions`

```json
{
  "permissions": {
    "allow": ["Bash(git:*)", "Read(*:*)"],
    "deny": ["Bash(rm -rf *)"]
  },
  "hooks": {
    "PreToolUse": [{ "command": "echo 'About to run'", "matcher": "Bash" }],
    "PostToolUse": [{ "command": "notify-send 'Done'" }]
  }
}
```

Hook events: `SessionStart` · `Stop` · `PreToolUse` · `PostToolUse` · `PostToolUseFailure` · `PreCompact` · `PostCompact` · `Notification`

---

## Skills

Create custom skills in `.haocode/skills/` or `~/.haocode/skills/`:

```text
.haocode/skills/
├── commit/SKILL.md
├── review/SKILL.md
└── test/SKILL.md
```

Supports `$ARGUMENTS` substitution, session variables, `allowedTools`, model overrides, and inline shell interpolation. Use `/skills` to inspect.

---

## Teams

Create a group of specialized background agents that collaborate on a shared objective:

```
TeamCreate  →  spawn multiple agents with roles (e.g., architect, reviewer, coder)
TeamList    →  inspect team status and member activity
TeamDelete  →  stop all members and clean up
SendMessage →  "team:<name>" broadcasts to all running members
```

Each team member gets injected context about their teammates and the team objective. Members communicate via `SendMessage` using deterministic agent IDs (`{teamName}_{role}`).

```
# Example: AI creates a research team
TeamCreate(name: "research", task: "Write a conflict analysis", members: [
  {role: "historian", prompt: "Research historical context"},
  {role: "analyst",   prompt: "Analyze military posture"},
  {role: "editor",    prompt: "Compile the final report"}
])

# Broadcast to all members
SendMessage(to: "team:research", message: "All sections done, begin compilation")

# Check status
TeamList(name: "research")
```

---

## Testing

```bash
composer test
# or
php vendor/bin/phpunit
```

---

## Requirements

- PHP 8.2+, Composer
- `pcntl` recommended (signal handling, parallel tools)
- `ripgrep` recommended (fast grep)

---

**[MIT License](LICENSE)** · Built with Laravel Zero · Powered by Anthropic-compatible APIs

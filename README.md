# Hao Code
An interactive CLI coding agent built with Laravel for Claude-, Kimi-, and Anthropic-compatible endpoints.

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![License](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](LICENSE)

`REPL` · `Streaming` · `Sub-agents` · `Tasks` · `Hooks` · `Skills` · `Session HUD`

## Preview

![Hao Code terminal screenshot](docs/images/hao-code-terminal.png)

_Real terminal session running Hao Code in the terminal._

## Highlights

- Interactive REPL with history, multiline input, transcript browsing, and slash-command autocomplete.
- Status-first terminal output with optional coalesced streaming and a built-in HUD footer.
- 30+ tools for shell, files, search, LSP, web, cron, tasks, worktrees, and notebooks.
- Session restore, branching, background agents, permissions, hooks, and reusable skills.

---

## Jump To

[Stabilization Status](#stabilization-status) · [Quick Start](#quick-start) · [Global Install](#global-install) · [Launch Modes](#launch-modes) · [Built-in HUD](#built-in-hud) · [Slash Commands](#slash-commands) · [Built-in Tools](#built-in-tools) · [Configuration](#configuration) · [Architecture](#architecture) · [Skills System](#skills-system) · [Permissions and Hooks](#permissions-and-hooks) · [Testing](#testing)

---

## Stabilization Status

Updated April 6, 2026.

Current repair work is based on the findings documented in [docs/hao-code-bug-report-2026-04-05.md](docs/hao-code-bug-report-2026-04-05.md).

The current branch includes:

- stronger agent-loop guardrails for repeated invalid tool patterns and recovery messaging
- file-state caching plus stricter read-before-write/edit enforcement across file tools
- richer bash command classification for safer concurrency and better result handling
- MCP connection management, dynamic tool registration, and resource access plumbing
- persisted large tool results with richer terminal rendering for edits, bash output, reads, search, and agent runs
- broader regression coverage across agent loop, streaming, file tools, bash, MCP, tool-result storage, and terminal UI behavior

Still being validated:

- long-running service orchestration during self-hosted verification flows
- stricter completion checks for generated-app repair tasks
- PTY output cleanup during long prompts and recovery-heavy sessions

This is a progress checkpoint, not a final stabilization release.

---

## Why Hao Code

### Terminal-first coding workflow

- Interactive REPL with readline history, multiline input, reverse search, transcript browsing, and slash-command autocomplete.
- Final-answer-first output by default, with optional coalesced streaming when you want live text deltas.
- A built-in HUD footer that keeps project, git, context health, recent tools, background agents, and todo progress visible.

### Real agent behavior

- 30+ built-in tools covering shell, files, search, LSP, web, cron, tasks, worktrees, skills, and notebook editing.
- Read-only tools can execute in parallel with `pcntl_fork`.
- Background agents persist across turns and can be resumed, messaged, and inspected later.

### Practical safety and control

- Permission modes with allow and deny rules.
- Hook system for pre/post tool interception and mutation.
- Cost tracking with warning and hard-stop thresholds.
- Context compaction and transcript-based session restore/branching.

### Extensible by design

- Project and global config in `.haocode/settings.json` and `~/.haocode/settings.json`.
- Reusable skills from `.haocode/skills/` or `~/.haocode/skills/`.
- Compatible with Anthropic-style endpoints, including Kimi coding mode.

---

## Quick Start

### Global install

Install from Packagist after publishing:

```bash
composer global require sk-wang/hao-code
```

Install directly from GitHub before publishing:

```bash
composer global config repositories.hao-code vcs https://github.com/sk-wang/hao-code.git
composer global require sk-wang/hao-code:dev-main
```

Make sure Composer's global bin directory is on your `PATH`:

```bash
export PATH="$(composer global config bin-dir --absolute):$PATH"
```

Configure your API key in `~/.haocode/settings.json`:

```bash
mkdir -p ~/.haocode
cat > ~/.haocode/settings.json <<'JSON'
{
  "api_key": "sk-ant-your-key-here"
}
JSON
```

Launch Hao Code from any directory:

```bash
hao-code
```

When installed globally, runtime files are stored under `~/.haocode/` (`storage/` for logs, history, and sessions; `bootstrap/cache/` for Laravel cache files).

### Local development

```bash
git clone https://github.com/sk-wang/hao-code.git
cd hao-code
composer install
cp .env.example .env
php artisan key:generate
```

Add your API key:

```bash
echo "ANTHROPIC_API_KEY=sk-ant-your-key-here" >> .env
```

Launch the REPL:

```bash
php artisan hao-code
```

---

## Launch Modes

### Interactive

```bash
hao-code
```

Or from a source checkout:

```bash
php artisan hao-code
```

### Single-shot

```bash
hao-code --print="Explain what AgentLoop.php does"
```

Or:

```bash
php artisan hao-code --print="Explain what AgentLoop.php does"
```

### Resume the latest session

```bash
hao-code --continue
```

Or:

```bash
php artisan hao-code --continue
```

### Resume a specific session

```bash
hao-code --resume=20260404_abcdef12
```

Or:

```bash
php artisan hao-code --resume=20260404_abcdef12
```

### Resume and fork into a new branch

```bash
hao-code --resume=20260404_abcdef12 --fork-session --name="alt-approach"
```

Or:

```bash
php artisan hao-code --resume=20260404_abcdef12 --fork-session --name="alt-approach"
```

### Useful CLI flags

- `-p, --print=`: run once and exit.
- `-c, --continue`: reopen the latest session for the current working directory when possible.
- `-r, --resume=`: restore a saved session by ID.
- `--fork-session`: branch into a new transcript when resuming or continuing.
- `--name=`: set the session display name.
- `--system-prompt=`: replace the session system prompt.
- `--append-system-prompt=`: append extra session instructions.
- `--model=`: override the model for this launch.
- `--permission-mode=`: override the permission mode for this launch.

---

## Built-in HUD

The status footer is built into the REPL. It is not a separate plugin.

By default it shows:

- model and session title
- project path and git branch
- message count and permission mode
- context usage bar and estimated cost
- recent tool activity
- background agents and bash tasks
- todo progress from `TodoWrite` and task tools

Configure it with `/statusline`:

```text
/statusline
/statusline on
/statusline off
/statusline layout compact
/statusline layout expanded
/statusline paths 1
/statusline paths 2
/statusline tools off
/statusline agents off
/statusline todos off
/statusline reset
```

Current supported HUD options:

- `layout`: `expanded` or `compact`
- `path_levels`: `1`, `2`, or `3`
- `show_tools`: `on` or `off`
- `show_agents`: `on` or `off`
- `show_todos`: `on` or `off`

---

## Slash Commands

See [CLAUDE_CODE_PARITY.md](CLAUDE_CODE_PARITY.md) for the parity audit against the local `~/git/claude-code` reference.

<details>
<summary><strong>Session and Navigation</strong></summary>

- `/help`
- `/exit`
- `/clear`
- `/history`
- `/resume [id]`
- `/branch [title]`
- `/rewind`
- `/snapshot [name]`
- `/transcript`
- `/search`

</details>

<details>
<summary><strong>Context, Status, and Output</strong></summary>

- `/status`
- `/statusline [subcommand]`
- `/stats`
- `/context`
- `/cost`
- `/model [name]`
- `/provider [list|use|clear]`
- `/buddy [card|status|hatch|pet|feed|mute|unmute|release|rename|face|quip|mood]`
- `/fast`
- `/theme`
- `/output-style`

</details>

<details>
<summary><strong>Workspace and Project Operations</strong></summary>

- `/files`
- `/diff`
- `/commit [hint]`
- `/review [pr]`
- `/memory`
- `/config [key] [value]`
- `/permissions`
- `/hooks`
- `/skills`
- `/mcp`
- `/init`
- `/doctor`
- `/version`

</details>

<details>
<summary><strong>Planning, Tasks, and Automation</strong></summary>

- `/plan [request]`
- `/tasks`
- `/loop [interval] [cmd]`

</details>

---

## Built-in Tools

Hao Code ships with 30+ tools. The main groups are below.

### Shell and files

- `Bash`: shell execution, timeouts, background support, dangerous-pattern detection.
- `Read`: read files, images, PDFs, notebooks.
- `Edit`: precise string-replacement editing.
- `Write`: overwrite files with secret scanning.
- `Glob`: fast file matching.
- `Grep`: ripgrep-accelerated content search.

### Agents and planning

- `Agent`: spawn sub-agents for general, Explore, or Plan work.
- `SendMessage`: continue a background sub-agent later.
- `TodoWrite`: maintain structured todos for the current session.
- `EnterPlanMode`
- `ExitPlanMode`

### Tasks and automation

- `TaskCreate`
- `TaskGet`
- `TaskList`
- `TaskUpdate`
- `TaskStop`
- `CronCreate`
- `CronDelete`
- `CronList`
- `Sleep`

### Code intelligence and workspace control

- `LspTool`
- `NotebookEdit`
- `EnterWorktree`
- `ExitWorktree`

### Web and interaction

- `WebSearch`
- `WebFetch`
- `AskUserQuestion`
- `ToolSearch`
- `Skill`
- `Config`

---

## Configuration

Hao Code reads:

- global settings from `~/.haocode/settings.json`
- project settings from `.haocode/settings.json`
- environment variables from `.env` in a source checkout

Example global settings:

```json
{
  "api_key": "sk-ant-...",
  "model": "claude-sonnet-4-20250514",
  "permission_mode": "default",
  "stream_output": false
}
```

Example multi-provider settings:

```json
{
  "active_provider": "zai",
  "provider": {
    "anthropic": {
      "api_key": "sk-ant-...",
      "api_base_url": "https://api.anthropic.com",
      "model": "claude-sonnet-4-20250514"
    },
    "zai": {
      "api_key": "your-zai-key",
      "api_base_url": "https://api.z.ai/api/anthropic",
      "model": "glm-5.1",
      "max_tokens": 8192
    }
  }
}
```

### Primary config keys

| Key | Typical values | Purpose |
| --- | --- | --- |
| `model` | `claude-sonnet-4-20250514` | Select the model ID |
| `active_provider` | `anthropic`, `zai` | Select the active provider |
| `permission_mode` | `default`, `plan`, `accept_edits`, `bypass_permissions` | Control approval and editing behavior |
| `stream_output` | `true`, `false` | Toggle throttled live text output |

### Notes

- `model` also supports `provider/model` format, for example `zai/glm-5.1`.
- Use `/provider` in the REPL to inspect or switch providers for the current session.
- Legacy flat keys like `api_key` and `api_base_url` still work.
- `permission_mode` is still the public config surface; internally Hao Code derives approval/sandbox behavior from it.
- Terminal output is final-answer-first by default to reduce flicker; set `stream_output=true` or `HAOCODE_STREAM_OUTPUT=true` if you want throttled live text deltas.

### Common environment variables

```env
ANTHROPIC_API_KEY=sk-ant-...
HAOCODE_MODEL=claude-sonnet-4-20250514
HAOCODE_ACTIVE_PROVIDER=
HAOCODE_MAX_TOKENS=16384
HAOCODE_STREAM_OUTPUT=false
HAOCODE_STREAM_RENDER_INTERVAL_MS=120
HAOCODE_PERMISSION_MODE=default
HAOCODE_API_BASE_URL=https://api.anthropic.com
HAOCODE_THINKING=false
HAOCODE_THINKING_BUDGET=10000
HAOCODE_COST_WARN=5.00
HAOCODE_COST_STOP=50.00
HAOCODE_BACKGROUND_AGENT_IDLE_TIMEOUT=300
HAOCODE_BACKGROUND_AGENT_POLL_INTERVAL_MS=250
```

### Supported model IDs

- `claude-sonnet-4-20250514`
- `claude-opus-4-20250514`
- `claude-haiku-4-20250514`
- `claude-3-7-sonnet-20250219`
- `claude-3-5-sonnet-20241022`
- `claude-3-5-haiku-20241022`
- `kimi-for-coding`

### Project instruction files

Hao Code auto-loads these files when present:

- `HAOCODE.md`
- `CLAUDE.md`
- `.haocode/rules/*.md`
- `.haocode/memory/MEMORY.md`
- `.haocode/output_style.md`

---

## Skills System

Create custom skills in either location:

```text
.haocode/skills/
~/.haocode/skills/
```

Example layout:

```text
.haocode/skills/
├── commit/SKILL.md
├── review/SKILL.md
└── test/SKILL.md
```

Supported behavior includes:

- `$ARGUMENTS` substitution
- session variable injection such as `$CLAUDE_SESSION_ID`
- `allowedTools`
- model overrides
- inline shell interpolation such as `$(command)`

Use `/skills` to inspect what is available.

---

## Permissions and Hooks

### Permission modes

- `default`: dangerous operations ask for confirmation.
- `plan`: write operations are disabled.
- `accept_edits`: file edits are auto-accepted.
- `bypass_permissions`: skip permission checks.

### Example permission rules

```json
{
  "permissions": {
    "allow": ["Bash(git:*)", "Read(*:*)"],
    "deny": ["Bash(rm -rf *)"]
  }
}
```

### Example hooks

```json
{
  "hooks": {
    "PreToolUse": [
      { "command": "echo 'About to run a tool'", "matcher": "Bash" }
    ],
    "PostToolUse": [
      { "command": "notify-send 'Tool done'" }
    ]
  }
}
```

Hook events:

- `SessionStart`
- `Stop`
- `PreToolUse`
- `PostToolUse`
- `PostToolUseFailure`
- `PreCompact`
- `PostCompact`
- `Notification`

Hooks can block execution or return modified JSON input for the tool pipeline.

---

## Architecture

```text
app/
├── Console/Commands/
│   └── HaoCodeCommand.php              CLI entry, REPL, slash commands, startup flags
├── Contracts/
│   └── ToolInterface.php               Tool contract
├── Providers/
│   ├── AgentServiceProvider.php
│   └── ToolServiceProvider.php
├── Services/
│   ├── Agent/
│   │   ├── AgentLoop.php
│   │   ├── AgentLoopFactory.php
│   │   ├── BackgroundAgentManager.php
│   │   ├── ContextBuilder.php
│   │   ├── MessageHistory.php
│   │   ├── QueryEngine.php
│   │   ├── StreamingToolExecutor.php
│   │   └── ToolOrchestrator.php
│   ├── Api/
│   ├── Compact/
│   ├── Cost/
│   ├── Git/
│   ├── Hooks/
│   ├── Memory/
│   ├── Notification/
│   ├── Permissions/
│   ├── Session/
│   ├── Settings/
│   └── Task/
├── Support/Terminal/
│   ├── Autocomplete/
│   ├── MarkdownRenderer.php
│   ├── PromptHudState.php
│   ├── ReplFormatter.php
│   ├── StreamingMarkdownOutput.php
│   ├── TranscriptBuffer.php
│   ├── TranscriptRenderer.php
│   └── TurnStatusRenderer.php
└── Tools/
```

---

## Testing

Run the full suite:

```bash
composer test
```

Or use PHPUnit directly:

```bash
php vendor/bin/phpunit
```

For targeted work on the HUD and statusline:

```bash
php artisan test \
  tests/Unit/PromptHudStateTest.php \
  tests/Unit/ReplFormatterTest.php \
  tests/Unit/SettingsManagerTest.php
```

---

## Requirements

- PHP 8.2 or newer
- Composer
- `pcntl` recommended for signal handling and parallel read-only tools
- `ripgrep` recommended for fast grep operations

---

**[MIT License](LICENSE)** · Built with Laravel 12 · Powered by Anthropic-compatible APIs

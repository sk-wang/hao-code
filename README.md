<div align="center">

# Hao Code

**An Interactive AI-Powered CLI Coding Agent**

Built with Laravel 12 · Powered by Anthropic API · Runs in Your Terminal

[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![Tests](https://img.shields.io/badge/Tests-1070%20%7C%201764%20assertions-00D084?style=for-the-badge)]()
[![License](https://img.shields.io/badge/License-MIT-blue?style=for-the-badge)](LICENSE)

> Claude &middot; Kimi &middot; Any Anthropic-compatible endpoint — all in your terminal.

</div>

---

## Features

<table>
<tr>
<td width="50%">

### Core Engine

- **Interactive REPL** — readline history, multiline input, 30+ slash commands
- **30 Built-in Tools** — Bash, File I/O, Grep, Glob, Agent, Web, LSP, Cron, Tasks & more
- **Streaming Responses** — real-time AI output with live Markdown rendering
- **Forked Tool Execution** — read-only tools run in parallel via `pcntl_fork` during streaming
- **Extended Thinking** — Claude 3.7+ extended thinking mode with configurable budget

</td>
<td width="50%">

### Intelligence

- **Context Compression** — auto/manual LLM-powered structured summarization
- **Cost Tracking** — real-time token & spend monitoring with warn/stop thresholds
- **Session Management** — auto-saved JSONL sessions, resume, AI-generated titles
- **Memory System** — cross-session persistent memory with `MEMORY.md` indexing
- **Secret Scanner** — 30+ credential patterns detected on file writes

</td>
</tr>
<tr>
<td width="50%">

### Developer Experience

- **Permission System** — 4 modes, fine-grained allow/deny rules, dangerous-pattern detection
- **Hook System** — 8 event types, shell intercepts with JSON input modification
- **LSP Integration** — go-to-definition, find references, hover, document symbols
- **Background Tasks** — spawn, monitor, and stop long-running processes
- **Cron Scheduling** — recurring & one-shot timers with persistence across restarts

</td>
<td width="50%">

### Terminal & UX

- **Rich Terminal UI** — GFM-to-ANSI renderer, streaming Markdown, animated spinners
- **Skills System** — custom reusable skills with parameter substitution & model override
- **Git Worktree** — isolated parallel development with auto-cleanup
- **Output Styles** — customizable AI output format via `.haocode/output_style.md`
- **Notifications** — iTerm2, Kitty, Ghostty, bell alerts
- **Sleep Prevention** — auto-caffeinate during long tasks on macOS

</td>
</tr>
</table>

---

## Quick Start

```bash
# Clone & install
git clone https://github.com/your-username/hao-code.git
cd hao-code
composer install
cp .env.example .env
php artisan key:generate

# Configure your API key
echo "ANTHROPIC_API_KEY=sk-ant-your-key-here" >> .env

# Launch
php artisan hao-code
```

### Configuration

Edit `.env` or use `~/.haocode/settings.json` for global settings:

```env
ANTHROPIC_API_KEY=sk-ant-...              # Required
HAOCODE_MODEL=claude-sonnet-4-20250514    # Default model
HAOCODE_MAX_TOKENS=16384                  # Max output tokens
HAOCODE_PERMISSION_MODE=default           # default | plan | accept_edits | bypass_permissions
HAOCODE_API_BASE_URL=https://api.anthropic.com  # Compatible endpoints (e.g. Kimi)
HAOCODE_THINKING=false                    # Extended thinking (Claude 3.7+)
HAOCODE_THINKING_BUDGET=10000             # Thinking token budget
HAOCODE_COST_WARN=5.00                    # Cost warning threshold (USD)
HAOCODE_COST_STOP=50.00                   # Hard cost stop (USD)
```

### Supported Models

| Model | ID | Notes |
|:------|:---|:------|
| **Claude Sonnet 4** | `claude-sonnet-4-20250514` | Default |
| **Claude Opus 4** | `claude-opus-4-20250514` | Most capable |
| **Claude Haiku 4** | `claude-haiku-4-20250514` | Fastest |
| **Claude 3.7 Sonnet** | `claude-3-7-sonnet-20250219` | Extended thinking |
| **Claude 3.5 Sonnet** | `claude-3-5-sonnet-20241022` | |
| **Claude 3.5 Haiku** | `claude-3-5-haiku-20241022` | |
| **Kimi** | `kimi-for-coding` | HTTP/1.1 compatible |

---

## Usage

### Interactive REPL

```bash
php artisan hao-code
```

### Single-shot (non-interactive)

```bash
php artisan hao-code --prompt="Explain what AgentLoop.php does"
```

### CLI Options

| Flag | Description |
|:-----|:------------|
| `--prompt=` | Non-interactive single command |
| `--model=` | Override default model |
| `--permission-mode=` | Override permission mode |
| `--resume=` | Resume a saved session by ID |

### Slash Commands

<details>
<summary><strong>Click to expand all 30+ commands</strong></summary>

| Command | Description |
|:--------|:------------|
| `/help` | Show help information |
| `/exit` | Exit the REPL |
| `/clear` | Clear conversation history |
| `/history` | Show message count |
| `/compact` | Manually compress context |
| `/cost` | Show token usage and cost |
| `/model [name]` | View or switch model |
| `/fast` | Toggle fast mode (Haiku) |
| `/status` | Show session status |
| `/context` | Show context usage & warnings |
| `/resume [id]` | Resume a past session |
| `/memory` | Manage session memory |
| `/tasks` | List background tasks |
| `/diff` | View uncommitted changes (colored) |
| `/rewind` | Undo last conversation turn |
| `/doctor` | Run environment diagnostics |
| `/theme` | Switch terminal theme (dark/light/ansi) |
| `/skills` | List available skills |
| `/permissions` | View/manage permission rules |
| `/snapshot [name]` | Export session as Markdown |
| `/init` | Initialize `.haocode/settings.json` |
| `/version` | Show version info |
| `/output-style` | View or set output style |
| `/loop [interval] [cmd]` | Schedule recurring task |

</details>

---

## Built-in Tools (30)

<details>
<summary><strong>Click to expand tool reference</strong></summary>

| Tool | Description |
|:-----|:------------|
| `Bash` | Execute shell commands with timeout & background support |
| `Read` | Read files (images, PDF, Jupyter notebooks) |
| `Edit` | Precise string-replacement file editing |
| `Write` | Write/overwrite files (with secret scanning) |
| `Grep` | Regex content search (ripgrep-accelerated) |
| `Glob` | Glob pattern file matching |
| `Agent` | Spawn sub-agents (general / Explore / Plan types) |
| `WebSearch` | Web search (DuckDuckGo / Google) |
| `WebFetch` | Fetch URL content with AI extraction |
| `LspTool` | LSP operations (definitions, references, symbols) |
| `NotebookEdit` | Edit Jupyter notebook cells |
| `CronCreate` | Create recurring or one-shot timers |
| `CronDelete` | Delete a cron job |
| `CronList` | List all cron jobs |
| `TaskCreate` | Create a background task |
| `TaskGet` | Query task details |
| `TaskList` | List all tasks |
| `TaskUpdate` | Update task status |
| `TaskStop` | Stop a background task |
| `TodoWrite` | Manage todo lists |
| `Skill` | Invoke a custom skill |
| `EnterPlanMode` | Enter planning mode |
| `ExitPlanMode` | Exit planning mode & submit plan |
| `EnterWorktree` | Create & enter a Git worktree |
| `ExitWorktree` | Exit a Git worktree |
| `Config` | Modify config at runtime |
| `AskUserQuestion` | Ask user (single/multi-select) |
| `ToolSearch` | Search available tools |
| `Sleep` | Short delay (max 30s) |
| `SendMessage` | Send message to sub-agent |

</details>

---

## Architecture

```
app/
├── Console/Commands/
│   └── HaoCodeCommand.php              CLI entry — REPL, slash commands, non-interactive mode
├── Contracts/
│   └── ToolInterface.php               Tool contract
├── Services/
│   ├── Agent/                          Agent core
│   │   ├── AgentLoop.php               Main loop: user input → API → tool execution → response
│   │   ├── AgentLoopFactory.php        Isolated AgentLoop instances (sub-agents)
│   │   ├── QueryEngine.php             API query engine (wraps StreamingClient)
│   │   ├── StreamProcessor.php         SSE event processing (extended thinking)
│   │   ├── StreamingToolExecutor.php   Parallel read-only tool execution (pcntl_fork)
│   │   ├── ToolOrchestrator.php        Tool pipeline: permission → hook → validate → execute
│   │   ├── ContextBuilder.php          System prompt builder (memory, git, skills, output style)
│   │   └── MessageHistory.php          Message history with cache_control breakpoints
│   ├── Api/                            API client layer
│   │   ├── StreamingClient.php         SSE streaming client (retry, compatible endpoints, cache)
│   │   ├── StreamEvent.php             SSE event parser
│   │   └── ApiErrorException.php       API error exception
│   ├── Compact/ContextCompactor.php    LLM summarization + micro-compaction (9-section)
│   ├── Cost/CostTracker.php            Token counting, cost estimation, threshold control
│   ├── FileHistory/                    File snapshot management
│   ├── Git/GitContext.php              Git status, recent commits, branch info
│   ├── Hooks/                          Hook system (definition, executor, result)
│   ├── Lsp/                            LSP client & server process
│   ├── Memory/SessionMemory.php        Cross-session memory persistence
│   ├── Notification/Notifier.php       iTerm2 / Kitty / Ghostty / bell notifications
│   ├── OutputStyle/OutputStyleLoader.md Custom output format injection
│   ├── Permissions/                    Permission checker, decision, denial tracker, patterns
│   ├── Security/SecretScanner.php      30+ credential type regex detection
│   ├── Session/                        Session manager, AI title generator, away summary
│   ├── Settings/SettingsManager.php    Global + project settings merge
│   ├── System/PreventSleep.php         macOS caffeinate wrapper
│   └── Task/TaskManager.php            Background task queue (proc_open)
├── Support/Terminal/                   Terminal rendering
│   ├── MarkdownRenderer.php            GFM → ANSI conversion
│   ├── StreamingMarkdownOutput.php     Real-time incremental Markdown redraw
│   ├── ReplFormatter.php               Prompt, banner, tool card formatting
│   ├── TurnStatusRenderer.php          Turn status animations (spinner, timing, tokens)
│   ├── TranscriptBuffer.php            Conversation transcript buffering
│   ├── TranscriptRenderer.php          Transcript rendering
│   ├── TerminalOutput.php              Core terminal output
│   └── InputSanitizer.php              User input sanitization
├── Tools/                              30 built-in tools (21 directories + 5 base files)
└── Providers/                          Service providers (Agent, Tool, App)
```

---

## Skills System

Create custom skills in `.haocode/skills/` (project) or `~/.haocode/skills/` (global):

```
.haocode/skills/
├── commit/SKILL.md
├── review/SKILL.md
└── test/SKILL.md
```

Skills support:
- `$ARGUMENTS` parameter substitution
- Session variable injection (`$CLAUDE_SESSION_ID`, etc.)
- Tool restrictions (`allowedTools`)
- Model override (`model`)
- Inline shell commands (`$(command)`)

Use `/skills` to list all available skills, invoke with `/<skill-name>`.

---

## Permission Modes

| Mode | Behavior |
|:-----|:---------|
| `default` | Dangerous ops require confirmation, safe ops auto-allowed |
| `plan` | Planning mode — write operations disabled |
| `accept_edits` | Auto-accept file edits |
| `bypass_permissions` | Skip all checks (use with caution) |

Fine-grained rules in `.haocode/settings.json`:

```json
{
  "permissions": {
    "allow": ["Bash(git:*)", "Read(*:*)"],
    "deny":  ["Bash(rm -rf *)"]
  }
}
```

---

## Hook System

Configure shell hooks in `.haocode/settings.json`:

```json
{
  "hooks": {
    "PreToolUse":  [{ "command": "echo 'About to run a tool'", "matcher": "Bash" }],
    "PostToolUse": [{ "command": "notify-send 'Tool done'" }]
  }
}
```

**Events:** `SessionStart` · `Stop` · `PreToolUse` · `PostToolUse` · `PostToolUseFailure` · `PreCompact` · `PostCompact` · `Notification`

Hooks can return `deny`/`block`/`no` to block execution, or JSON `{"allow": true, "input": {...}}` to modify tool input.

---

## Project Config Files

Hao Code auto-loads these files (by priority):

| File | Purpose |
|:-----|:--------|
| `HAOCODE.md` | Project-level instructions |
| `CLAUDE.md` | Claude Code compatible instructions |
| `.haocode/rules/*.md` | Rule files |
| `.haocode/memory/MEMORY.md` | Persistent memory index |
| `.haocode/output_style.md` | Output style definition |

---

## Testing

```bash
composer test
# or
php vendor/bin/phpunit
```

**1070 tests &middot; 1764 assertions** — all passing.

---

## Requirements

- PHP >= 8.2
- Composer
- `pcntl` extension (recommended — signal handling & parallel tool execution)
- `ripgrep` (recommended — Grep tool acceleration)

---

<div align="center">

**[MIT License](LICENSE)** · Built with Laravel 12 · Powered by Anthropic

</div>

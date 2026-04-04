# Claude Code Parity Audit

Checked on April 4, 2026 against the local reference repo at `/Users/wanghao/git/claude-code`.

Reference files reviewed:
- `README.md`
- `src/commands.ts`
- `src/screens/REPL.tsx`
- `src/commands/files/files.ts`
- `src/commands/config/config.tsx`
- `src/commands/hooks/hooks.tsx`
- `src/commands/plan/plan.tsx`

## Implemented Or Close

Command parity that exists in `hao-code` today:
- `/help`, `/exit`, `/clear`, `/compact`, `/cost`, `/status`, `/context`
- `/transcript`, `/search`, `/resume`, `/diff`, `/memory`, `/tasks`
- `/permissions`, `/skills`, `/init`, `/version`, `/rewind`, `/fast`
- `/config` for runtime config inspection and mutation
- `/hooks` for viewing configured hooks
- `/files` for listing files currently in conversation context
- `/plan` for entering read-only planning mode and running planning prompts
- `/branch` for transcript-based conversation branching with automatic session switching
- `/stats` for session analytics across saved transcripts
- `/mcp` for MCP config inspection plus add/show/enable/disable/remove flows
- `/commit` for an agent-driven local git commit workflow
- `/review` for a local branch or PR review workflow
- `/statusline` for managing the REPL status-line footer, including layout/path-depth/toggle controls
- `/theme`, `/model`, `/output-style` as separate legacy commands

System-level parity that already exists or is reasonably close:
- Live slash-command dropdown autocomplete and `@path` suggestions in the REPL
- Session persistence, resume, transcript browsing, markdown export/snapshot
- Permission modes, allow/deny rules, hook execution, skills, output styles
- Background agents/tasks, worktree tools, memory, cost tracking, diagnostics
- Startup flags for print/continue/resume/fork/name/system-prompt flows

## Partial Parity

These features exist but are not one-to-one with the Claude Code UX yet:
- `/config`: terminal list/set flow instead of Claude Code’s settings panel
- `/hooks`: read-only viewer, no interactive hook editor
- `/files`: derived from `Read`/`Edit`/`Write` tool history instead of a dedicated file-state cache
- `/plan`: toggles real plan mode, but there is no separate plan file/editor workflow yet
- `/branch`: transcript fork + `/resume` workflow, not Claude’s fullscreen sidechain UI
- `/mcp`: config-file management only, no live server connection manager or elicitation flow
- `/commit` and `/review`: prompt-driven local workflows instead of the richer Claude Code UI surfaces
- `/statusline`: controls a richer Claude-HUD-style Hao Code footer, not Claude Code’s shell-integrated statusLine setup
- Root CLI flags: `-p/-c/-r/-n` and startup prompt overrides exist, but the larger headless/JSON/SDK surface from Claude Code is still missing
- Overall REPL: raw-terminal experience with live suggestions, not the full Ink fullscreen UI from Claude Code

## Missing Or Not Yet Equivalent

Major command gaps still visible compared with `src/commands.ts`:
- `/agents`
- `/plugin`
- `/pr_comments`
- `/desktop`
- `/mobile`
- `/vim`
- `/ide`
- Auth/share/onboarding commands such as `/login`, `/logout`, `/share`

Larger subsystem gaps:
- MCP server management and elicitation flows
- Plugin marketplace/loading model
- Desktop/mobile/IDE bridge surfaces
- Claude Code's shell-level statusLine setup and fullscreen session analytics surfaces
- Claude Code’s broader startup/headless CLI surface (`--output-format`, `--allowedTools`, `--mcp-config`, `--settings`, `--ide`, etc.)

## Recommended Next Targets

Highest-value parity work left after this pass:
1. `/agents`, `/plugin`, and `/pr_comments`
2. Desktop/mobile/IDE bridge surfaces
3. MCP live connection lifecycle and elicitation flows
4. Richer Claude-style fullscreen REPL/session analytics UX

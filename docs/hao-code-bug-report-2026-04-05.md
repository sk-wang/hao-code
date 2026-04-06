# Hao Code Bug Report

Observed on April 5, 2026.

## Scope

This report covers issues found in `Hao Code` itself while using it to complete a real terminal task in a separate sandbox directory:

- Agent repo: `/Users/wanghao/git/hao-code`
- Task sandbox: `/Users/wanghao/git/hao-code-notes-demo`

The note app created in the sandbox was only used as a realistic workload. The findings below are about `Hao Code` behavior, not about the sample app as a product.

## Test Scenario

I used `Hao Code` to:

1. create a small full-stack notes app from an empty directory
2. run and validate it
3. feed real bug findings back into the agent
4. ask the agent to repair the generated app and re-test it

This workflow exposed several reliability problems in planning, tool recovery, and long-running command orchestration.

## Findings

### P1 - `--print` mode can enter a non-recovering invalid-tool loop

- Symptom: after a repair prompt based on real test findings, the agent started emitting invalid tool targets such as `Read(:0)` and `Bash(:2)`.
- Follow-on behavior: it repeatedly re-ran generic directory listing commands instead of recovering, finishing, or failing cleanly.
- Impact: a normal "build -> test -> fix" workflow can hang indefinitely, waste tokens, and require manual intervention.
- Example evidence:
  - `Read: File does not exist: /Users/wanghao/git/hao-code-notes-demo/:0`
  - `Bash: Command exited with code 127`
  - repeated `List all files in project directory`

### P1 - file-tool failure recovery is weak after write-protection errors

- Symptom: during initial project creation, the agent created files such as `.gitignore` and `go.mod`, then immediately attempted to write them again without reading first.
- Tool response: `Write: This is an existing file. You MUST use the Read tool first...`
- Follow-on behavior: instead of cleanly replanning, the session stalled and needed a manual interrupt.
- Impact: common "create file, then refine file" flows are brittle and can deadlock a session.

### P1 - long-running process orchestration is unstable

- Symptom: the agent started the generated web server as part of validation, then later hit `Bash: Command timed out after 120s.`
- Follow-on behavior: it tried to restart on another port and continue validation.
- Impact: workflows that require "start service -> run checks -> stop service" are unreliable, especially in `--print` mode.
- Resulting risk: false negatives, repeated restarts, orphaned services, and confusing task state.

### P2 - completion judgment is too optimistic

- Symptom: the agent reported the sample project as completed even though manual verification still found real defects.
- Real defects found immediately after its "done" report:
  - API accepted `title=""`
  - API accepted whitespace-only `content`
- Impact: users can receive a confident completion summary while important acceptance criteria still fail.

### P2 - cost and loop control are too weak for small real tasks

- In one `--print` run for a very small demo task, the final usage summary reported:
  - `Input tokens: 238,611`
  - `Output tokens: 4,164`
  - `Est. cost: $0.8112402`
- Impact: repeated retries and directory rescans create a large cost multiplier for simple jobs.
- Expected improvement: detect loops earlier, reduce repeated broad scans, and stop after repeated invalid tool targets.

### P3 - PTY UX becomes noisy during long prompts and recovery attempts

- Symptom: long Chinese prompts were echoed character-by-character in the interactive terminal, mixed with HUD/status output and tool lines.
- Impact: it becomes difficult to tell whether the agent is thinking, stuck, retrying, or already broken.
- This is not as severe as the recovery bugs above, but it makes diagnosis harder once failure begins.

## Reproduction

### Repro 1: interactive creation stalls after file-tool constraint failure

```bash
mkdir -p /Users/wanghao/git/hao-code-notes-demo
cd /Users/wanghao/git/hao-code-notes-demo
php /Users/wanghao/git/hao-code/artisan hao-code --permission-mode=bypass_permissions --name="notes-builder"
```

Use a prompt equivalent to:

```text
在当前空目录里直接创建一个可运行的前后端笔记项目，数据库必须用 sqlite。要求：有真实前端页面，不是纯 API；后端负责 CRUD 持久化；至少支持新建、编辑、删除、列表、搜索；把项目跑起来并自己验证；如果发现问题继续迭代修复；尽量不要停下来问我。
```

Observed behavior:

- the agent creates a few files
- it then tries to write an already-created file again
- file tools reject the write because the file was not read first
- the session becomes unstable and typically needs `Ctrl+C`

### Repro 2: `--print` repair pass enters invalid-tool loop

```bash
cd /Users/wanghao/git/hao-code-notes-demo
php /Users/wanghao/git/hao-code/artisan hao-code --permission-mode=bypass_permissions --print="我已经手动测过当前目录的这个 Go + Gin + SQLite 笔记项目，发现这些真实问题，请你直接修复并再次自测：1）后端接口允许创建 title 为空字符串、content 只有空白的脏数据；2）搜索空结果文案误导；3）favicon 404；4）保存/删除后缺少轻量反馈。修完后再次验证并汇报。"
```

Observed behavior:

- invalid tool targets appear, including `Read(:0)` and `Bash(:2)`
- broad file listing commands repeat
- the run does not converge cleanly

### Repro 3: server orchestration timeout during self-validation

Ask the agent to:

1. start a web service
2. validate it with curl or browser steps
3. stop the service

Observed behavior from the run:

- `Bash: Command timed out after 120s.`
- restart attempts on another port
- validation becomes fragmented across multiple server lifecycles

## Concrete Evidence Collected

### Evidence A - invalid tool argument generation

The agent produced errors like:

```text
Read: File does not exist: /Users/wanghao/git/hao-code-notes-demo/:0
Bash: Command exited with code 127
```

These are not normal user-path values and strongly suggest a planning or argument-construction bug.

### Evidence B - long-running command timeout

The agent emitted:

```text
✗ Bash: Command timed out after 120s.
```

This happened during the phase where it was trying to self-host and verify the generated project.

### Evidence C - false completion

After the agent said the sample project was done, manual API checks still showed bad acceptance behavior:

```bash
curl -i -sS -X POST http://127.0.0.1:8081/api/notes \
  -H 'Content-Type: application/json' \
  -d '{"title":"","content":"blank title note","tags":""}'

curl -i -sS -X POST http://127.0.0.1:8081/api/notes \
  -H 'Content-Type: application/json' \
  -d '{"title":"spaces","content":"   ","tags":""}'
```

Both requests were initially accepted before a manual repair pass.

### Evidence D - disproportionate cost for a tiny task

One run summary reported:

```text
Input tokens: 238,611
Output tokens: 4,164
Est. cost: $0.8112402
```

That is high for a small CRUD demo and suggests redundant retries or loop behavior.

## Suggested Fix Areas

### 1. Add loop detection and circuit breaking in `--print`

Candidate safeguards:

- terminate after repeated invalid tool arguments
- terminate after repeated identical tool failures
- detect repeated "scan current directory" loops without net file changes
- return a structured failure summary instead of continuing forever

### 2. Improve replan behavior after file-tool guardrail failures

When write/edit is rejected because a file must be read first, the agent should:

1. read the file
2. restate the immediate subgoal
3. retry the edit once
4. fail clearly if the second attempt still cannot proceed

### 3. Separate service lifecycle management from general bash execution

The agent needs a safer pattern for:

- start server in background
- poll readiness
- run validation
- guarantee cleanup

This likely needs stronger first-class support than a plain bash command.

### 4. Tighten completion criteria

Before final completion, require at least:

- one successful create
- one successful update
- one successful delete
- one successful search
- one failed validation case for bad input when input validation is part of requirements

### 5. Reduce noisy terminal echo during long prompts

Consider:

- collapsing long prompt echo in raw TTY mode
- rendering a short "prompt accepted" summary instead of full character replay
- keeping tool failure summaries visually distinct from prompt text

## Current Recommendation

Priority order for engineering work:

1. fix invalid tool argument / infinite loop behavior in `--print`
2. improve file-tool recovery after write/edit guardrails
3. harden service start/stop orchestration for self-validation
4. tighten completion checks
5. clean up PTY UX

## Notes

- The agent repo working tree was already dirty before this report and was not reset.
- The temporary sandbox app remains at `/Users/wanghao/git/hao-code-notes-demo` as a reproducible workload.

# Hao Code

基于 Laravel 构建的交互式 CLI 编程助手，通过 Anthropic API 驱动，在终端中提供 AI 辅助编码体验。支持 Claude 和 Kimi 等兼容端点。

## 特性

- **交互式 REPL** — 支持历史记录、多行输入、斜杠命令
- **30 内置工具** — Bash、文件读写编辑、Grep、Glob、Agent、Web 搜索/抓取、LSP、Cron、Task、TodoWrite 等
- **流式响应** — 实时输出 AI 生成内容，工具执行过程可视化，Markdown 实时渲染
- **流式工具执行** — 只读工具在流式传输中通过 `pcntl_fork` 并行执行，提升响应速度
- **权限控制** — 工具执行前可交互确认，支持多种权限模式和规则配置
- **Hook 系统** — Shell 钩子，支持 SessionStart、Stop、PreToolUse、PostToolUse、PreCompact、PostCompact、Notification 等事件
- **会话管理** — 自动保存会话（JSONL），支持恢复历史会话、AI 自动生成会话标题、离开摘要
- **上下文压缩** — 自动/手动压缩上下文（LLM 结构化摘要 + 微压缩），延长有效交互轮次
- **成本追踪** — 实时统计 Token 用量（含 Prompt Cache）和预估费用，支持阈值告警和硬停
- **秘密扫描** — 写入文件时自动检测 30+ 凭据类型（API 密钥、私钥、Token 等），防止意外泄露
- **LSP 集成** — 代码智能（跳转定义、查找引用、Hover、文档符号等）
- **后台任务** — 支持后台执行长时间命令，随时查询/停止
- **Cron 调度** — 会话内定时任务和一次性提醒，支持持久化
- **Skills 扩展** — 通过 `.haocode/skills/` 自定义可复用技能，支持参数替换、工具限制和模型覆盖
- **记忆系统** — 跨会话持久记忆，自动加载项目级 HAOCODE.md/CLAUDE.md 配置
- **扩展思考** — 支持 Extended Thinking 模式（Claude 3.7+）
- **Git Worktree** — 隔离式并行开发支持，自动清理
- **输出风格** — 通过 `.haocode/output_style.md` 自定义 AI 输出格式
- **终端通知** — 支持 iTerm2、Kitty、Ghostty、bell 终端通知
- **防睡眠** — 长时间任务自动阻止 macOS 系统休眠

## 环境要求

- PHP >= 8.2
- Composer
- `pcntl` 扩展（推荐，用于信号处理和并行工具执行）
- `ripgrep`（推荐，用于 Grep 工具加速）

## 安装

```bash
git clone https://github.com/your-username/hao-code.git
cd hao-code
composer install
cp .env.example .env
php artisan key:generate
```

## 配置

编辑 `.env` 文件：

```env
ANTHROPIC_API_KEY=sk-ant-...            # 必填：API 密钥
HAOCODE_MODEL=claude-sonnet-4-20250514  # 可选：模型
HAOCODE_MAX_TOKENS=16384                # 可选：最大输出 Token
HAOCODE_PERMISSION_MODE=default         # 可选：权限模式
HAOCODE_API_BASE_URL=https://api.anthropic.com  # 可选：API 端点（兼容其他 Claude 接口）
HAOCODE_THINKING=false                  # 可选：启用扩展思考
HAOCODE_THINKING_BUDGET=10000          # 可选：思考 Token 预算
HAOCODE_COST_WARN=5.00                 # 可选：费用告警阈值（USD）
HAOCODE_COST_STOP=50.00                # 可选：费用硬停阈值（USD）
```

也可通过全局配置文件 `~/.haocode/settings.json` 管理（支持 permissions、hooks、model 等）。

### 可用模型

| 模型 ID | 说明 |
|---------|------|
| `claude-sonnet-4-20250514` | Claude Sonnet 4（默认） |
| `claude-opus-4-20250514` | Claude Opus 4 |
| `claude-haiku-4-20250514` | Claude Haiku 4 |
| `claude-3-7-sonnet-20250219` | Claude 3.7 Sonnet（支持扩展思考） |
| `claude-3-5-sonnet-20241022` | Claude 3.5 Sonnet |
| `claude-3-5-haiku-20241022` | Claude 3.5 Haiku |
| `kimi-for-coding` | Kimi 编程助手（兼容端点，HTTP/1.1） |

### 项目配置文件

Hao Code 自动加载以下配置文件（按优先级）：

- `HAOCODE.md` — 项目级指令
- `CLAUDE.md` — 兼容 Claude Code 指令
- `.haocode/rules/*.md` — 规则文件
- `.haocode/memory/MEMORY.md` — 持久记忆索引
- `.haocode/output_style.md` — 输出风格定义

## 使用

### 启动交互式 REPL

```bash
php artisan hao-code
```

### 单次执行（非交互）

```bash
php artisan hao-code --prompt="解释 app/Services/Agent/AgentLoop.php 的作用"
```

### 命令行选项

| 选项 | 说明 |
|------|------|
| `--prompt=` | 非交互模式，执行单条指令 |
| `--model=` | 覆盖默认模型 |
| `--permission-mode=` | 覆盖权限模式 |
| `--resume=` | 恢复指定会话 ID |

### REPL 斜杠命令

| 命令 | 说明 |
|------|------|
| `/help` | 显示帮助信息 |
| `/exit` | 退出 REPL |
| `/clear` | 清空对话历史 |
| `/history` | 显示消息数量 |
| `/compact` | 手动压缩上下文 |
| `/cost` | 显示 Token 用量和费用 |
| `/model [name]` | 查看/切换模型 |
| `/fast` | 切换快速模式（Haiku 模型） |
| `/status` | 显示会话状态 |
| `/context` | 显示上下文使用情况和警告 |
| `/resume [id]` | 恢复历史会话 |
| `/memory` | 管理会话记忆 |
| `/tasks` | 列出后台任务 |
| `/diff` | 查看未提交的变更（含彩色 diff） |
| `/rewind` | 撤销上一轮对话 |
| `/doctor` | 运行环境诊断 |
| `/theme` | 切换终端主题（dark/light/ansi） |
| `/skills` | 列出可用技能 |
| `/permissions` | 查看/管理权限规则 |
| `/snapshot [name]` | 导出会话为 Markdown 快照 |
| `/init` | 初始化 `.haocode/settings.json` |
| `/version` | 显示版本信息 |
| `/output-style` | 查看或设置输出风格 |
| `/loop [interval] [prompt]` | 设置定期重复任务 |

## 内置工具（30 个）

| 工具 | 说明 |
|------|------|
| `Bash` | 执行 Shell 命令，支持超时和后台运行 |
| `Read` | 读取文件（支持图片、PDF、Jupyter Notebook） |
| `Edit` | 精确字符串替换编辑文件 |
| `Write` | 写入/覆盖文件（含秘密扫描） |
| `Grep` | 正则内容搜索（优先使用 ripgrep） |
| `Glob` | 按 glob 模式匹配文件 |
| `Agent` | 启动子 Agent（通用/Explore/Plan 等类型） |
| `WebSearch` | Web 搜索（DuckDuckGo/Google） |
| `WebFetch` | 抓取 URL 内容并用 AI 提炼 |
| `LspTool` | LSP 操作（定义跳转、引用查找、符号等） |
| `NotebookEdit` | 编辑 Jupyter Notebook 单元格 |
| `CronCreate` | 创建定时/一次性任务 |
| `CronDelete` | 删除定时任务 |
| `CronList` | 列出所有定时任务 |
| `TaskCreate` | 创建后台任务 |
| `TaskGet` | 查询任务详情 |
| `TaskList` | 列出所有任务 |
| `TaskUpdate` | 更新任务状态 |
| `TaskStop` | 停止后台任务（原 TaskOutput） |
| `TodoWrite` | 管理待办事项列表 |
| `Skill` | 调用自定义技能 |
| `EnterPlanMode` | 进入计划模式 |
| `ExitPlanMode` | 退出计划模式并提交计划 |
| `EnterWorktree` | 创建并进入 Git Worktree |
| `ExitWorktree` | 退出 Git Worktree |
| `Config` | 运行时修改配置（模型、权限模式等） |
| `AskUserQuestion` | 向用户提问（单选/多选） |
| `ToolSearch` | 搜索可用工具 |
| `Sleep` | 短暂延时（最大 30 秒） |
| `SendMessage` | 向子 Agent 发送消息 |

## 项目结构

```
app/
├── Console/Commands/
│   └── HaoCodeCommand.php          # CLI 入口（REPL + 斜杠命令 + 非交互模式）
├── Services/
│   ├── Agent/                      # Agent 核心
│   │   ├── AgentLoop.php           # 主循环：用户输入 → API → 工具执行 → 响应
│   │   ├── AgentLoopFactory.php    # 创建隔离的 AgentLoop 实例（子 Agent）
│   │   ├── QueryEngine.php         # API 查询引擎（包装 StreamingClient）
│   │   ├── StreamProcessor.php     # 流式 SSE 事件处理（含 extended thinking）
│   │   ├── StreamingToolExecutor.php  # 流式过程中并行执行只读工具（pcntl_fork）
│   │   ├── ToolOrchestrator.php    # 工具执行管道（权限 → Hook → 校验 → 执行）
│   │   ├── ContextBuilder.php      # 系统提示构建（记忆、Git、Skills、输出风格）
│   │   └── MessageHistory.php      # 消息历史管理（含 cache_control 断点）
│   ├── Api/                        # API 客户端
│   │   ├── StreamingClient.php     # SSE 流式客户端（重试、兼容端点、缓存）
│   │   ├── StreamEvent.php         # SSE 事件解析
│   │   └── ApiErrorException.php   # API 错误异常
│   ├── Compact/                    # 上下文压缩
│   │   └── ContextCompactor.php    # LLM 摘要 + 微压缩，9 段结构化摘要
│   ├── Cost/                       # 成本追踪
│   │   └── CostTracker.php         # Token 计数、费用估算、阈值控制
│   ├── FileHistory/                # 文件快照管理
│   │   └── FileHistoryManager.php  # 快照记录、diff 生成、文件还原
│   ├── Git/                        # Git 上下文
│   │   └── GitContext.php          # Git 状态、近期提交、分支信息
│   ├── Hooks/                      # Hook 系统
│   │   └── HookExecutor.php        # Shell 钩子执行（env 注入、JSON 修改输入）
│   ├── Lsp/                        # LSP 客户端
│   ├── Memory/                     # 跨会话记忆
│   │   └── SessionMemory.php       # 记忆文件读写
│   ├── Notification/               # 终端通知
│   │   └── Notifier.php            # iTerm2/Kitty/Ghostty/bell 通知
│   ├── OutputStyle/                # 输出风格
│   │   └── OutputStyleLoader.php   # 加载 output_style.md 注入系统提示
│   ├── Permissions/                # 权限控制
│   │   ├── PermissionChecker.php   # 规则匹配、危险模式检测
│   │   ├── PermissionDecision.php  # allow/deny/ask 决策值对象
│   │   └── DenialTracker.php       # 连续拒绝计数（防循环）
│   ├── Security/                   # 安全
│   │   └── SecretScanner.php       # 30+ 凭据类型正则检测
│   ├── Session/                    # 会话管理
│   │   ├── SessionManager.php      # JSONL 会话存储与检索
│   │   ├── SessionTitleService.php # AI 自动生成会话标题（Haiku）
│   │   └── AwaySummaryService.php  # 离开摘要（"你不在时发生了..."）
│   ├── Settings/                   # 设置
│   │   └── SettingsManager.php     # 全局 + 项目 settings.json 合并
│   ├── System/                     # 系统
│   │   └── PreventSleep.php        # macOS 防休眠（caffeinate）
│   └── Task/                       # 后台任务
│       └── TaskManager.php         # 任务队列、proc_open 执行
├── Support/Terminal/               # 终端输出
│   ├── MarkdownRenderer.php        # GFM Markdown → ANSI 终端渲染
│   ├── StreamingMarkdownOutput.php # 流式 Markdown 实时增量重绘
│   ├── ReplFormatter.php           # 提示符、横幅、工具卡片格式化
│   ├── TurnStatusRenderer.php      # 轮次状态动画（旋转器、耗时、Token）
│   └── InputSanitizer.php          # 用户输入清理
├── Tools/                          # 30 内置工具（见上方工具列表）
└── Providers/
    └── AgentServiceProvider.php    # 依赖注入绑定
```

## Skills 扩展

在项目目录 `.haocode/skills/` 或用户目录 `~/.haocode/skills/` 下创建技能：

```
.haocode/skills/
├── commit/SKILL.md    # git commit 辅助
├── review/SKILL.md    # 代码审查
└── test/SKILL.md      # 测试生成
```

技能支持：
- `$ARGUMENTS` — 参数替换
- 会话变量注入（`$CLAUDE_SESSION_ID` 等）
- 工具限制（`allowedTools`）
- 模型覆盖（`model`）
- 内联 Shell 命令（`$(command)`）

内置技能：`commit`、`review`、`test`。用户可通过 `/skills` 查看全部可用技能，使用 `/<skill-name>` 调用。

## 权限模式

| 模式 | 说明 |
|------|------|
| `default` | 危险操作需交互确认，安全操作自动允许 |
| `plan` | 计划模式，禁止写操作 |
| `accept_edits` | 自动接受文件编辑 |
| `bypass_permissions` | 跳过所有权限检查（自动化场景慎用） |

支持在 `.haocode/settings.json` 中配置细粒度的工具允许/拒绝规则：

```json
{
  "permissions": {
    "allow": ["Bash(git:*)", "Read(*:*)"],
    "deny":  ["Bash(rm -rf *)"]
  }
}
```

## Hook 系统

在 `.haocode/settings.json` 中配置 Shell 钩子，对工具执行进行拦截或增强：

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

支持事件：`SessionStart`、`Stop`、`PreToolUse`、`PostToolUse`、`PostToolUseFailure`、`PreCompact`、`PostCompact`、`Notification`。

钩子可通过标准输出返回 `deny`/`block`/`no` 来阻止工具执行，或返回 JSON `{"allow": true, "input": {...}}` 来修改工具输入。

## 测试

```bash
composer test
# 或
php vendor/bin/phpunit
```

当前测试覆盖：**1037 个测试，1695 个断言**，覆盖所有核心服务和工具。

## License

MIT

# Hao Code

基于 Laravel 构建的交互式 CLI 编程助手，通过 Anthropic API 驱动，在终端中提供 AI 辅助编码体验。

## 特性

- **交互式 REPL** — 支持历史记录、多行输入、斜杠命令
- **25+ 内置工具** — Bash、文件读写编辑、Grep、Glob、Agent、Web 搜索等
- **流式响应** — 实时输出 AI 生成内容，工具执行过程可视化
- **权限控制** — 工具执行前可交互确认，支持多种权限模式
- **会话管理** — 自动保存会话，支持恢复历史会话
- **上下文压缩** — 对话过长时自动压缩上下文，延长有效交互轮次
- **成本追踪** — 实时统计 Token 用量和预估费用
- **LSP 集成** — 代码智能（跳转定义、查找引用等）
- **后台任务** — 支持后台执行长时间命令
- **Cron 调度** — 定时任务和一次性提醒
- **Skills 扩展** — 通过 `.haocode/skills/` 目录自定义技能

## 环境要求

- PHP >= 8.2
- Composer
- pcntl 扩展（推荐，用于信号处理）
- ripgrep（推荐，用于代码搜索）

## 安装

```bash
git clone <repository-url> hao-code
cd hao-code
composer install
cp .env.example .env
php artisan key:generate
```

## 配置

编辑 `.env` 文件：

```env
ANTHROPIC_API_KEY=sk-ant-...        # 必填：Anthropic API 密钥
HAOCODE_MODEL=claude-sonnet-4-20250514  # 可选：模型选择
HAOCODE_MAX_TOKENS=16384              # 可选：最大输出 Token
HAOCODE_PERMISSION_MODE=default        # 可选：权限模式
```

也可通过全局配置文件 `~/.haocode/settings.json` 管理。

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

### REPL 斜杠命令

| 命令 | 说明 |
|------|------|
| `/help` | 显示帮助信息 |
| `/exit` | 退出 REPL |
| `/clear` | 清空对话历史 |
| `/compact` | 压缩上下文 |
| `/cost` | 显示 Token 用量和费用 |
| `/model [name]` | 查看/切换模型 |
| `/status` | 显示会话状态 |
| `/context` | 显示上下文使用情况 |
| `/resume [id]` | 恢复历史会话 |
| `/memory` | 管理会话记忆 |
| `/tasks` | 列出后台任务 |
| `/diff` | 查看未提交的变更 |
| `/rewind` | 撤销上一轮对话 |
| `/doctor` | 运行诊断检查 |
| `/theme` | 切换终端主题 |
| `/skills` | 列出可用技能 |

## 项目结构

```
app/
├── Console/Commands/
│   └── HaoCodeCommand.php       # CLI 入口命令（REPL + 斜杠命令）
├── Contracts/
│   └── ToolInterface.php         # 工具接口定义
├── Services/
│   ├── Agent/                    # Agent 核心循环
│   │   ├── AgentLoop.php         # 主循环：接收输入 → 调用 API → 执行工具
│   │   ├── QueryEngine.php       # API 查询引擎
│   │   ├── StreamProcessor.php   # 流式响应处理
│   │   ├── ToolOrchestrator.php  # 工具编排与执行
│   │   ├── ContextBuilder.php    # 上下文构建
│   │   └── MessageHistory.php    # 消息历史管理
│   ├── Api/                      # Anthropic API 客户端
│   │   ├── StreamingClient.php   # 流式 HTTP 客户端
│   │   └── StreamEvent.php       # SSE 事件解析
│   ├── Compact/                  # 上下文压缩
│   ├── Cost/                     # 成本追踪
│   ├── FileHistory/              # 文件变更记录
│   ├── Hooks/                    # Hook 系统
│   ├── Lsp/                      # LSP 客户端
│   ├── Memory/                   # 会话记忆
│   ├── Permissions/              # 权限检查
│   ├── Session/                  # 会话管理
│   ├── Settings/                 # 设置管理
│   └── Task/                     # 后台任务管理
├── Tools/                        # 25+ 内置工具
│   ├── BaseTool.php              # 工具基类
│   ├── ToolRegistry.php          # 工具注册表
│   ├── Bash/                     # Shell 命令执行
│   ├── FileRead/                 # 文件读取
│   ├── FileEdit/                 # 文件编辑
│   ├── FileWrite/                # 文件写入
│   ├── Grep/                     # 内容搜索
│   ├── Glob/                     # 文件匹配
│   ├── Agent/                    # 子 Agent
│   ├── WebSearch/                # Web 搜索
│   ├── WebFetch/                 # URL 内容抓取
│   ├── Lsp/                      # LSP 操作
│   ├── Cron/                     # 定时任务
│   ├── Task/                     # 后台任务
│   ├── Notebook/                 # Notebook 编辑
│   ├── TodoWrite/                # 待办管理
│   ├── Skill/                    # 技能系统
│   └── ...                       # 更多工具
└── Providers/                    # Laravel 服务提供者
```

## Skills 扩展

在项目目录 `.haocode/skills/` 或用户目录 `~/.haocode/skills/` 下创建技能：

```
.haocode/skills/
├── commit/SKILL.md
├── review/SKILL.md
└── test/SKILL.md
```

内置技能：`commit`、`review`、`test`。

## 权限模式

| 模式 | 说明 |
|------|------|
| `default` | 危险操作需交互确认 |
| `plan` | 计划模式，只读操作 |
| `accept_edits` | 自动接受文件编辑 |
| `bypass_permissions` | 跳过所有权限检查（慎用） |

## 测试

```bash
composer test
```

## License

MIT

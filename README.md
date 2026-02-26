# Evolver.php

🧬 Capability Evolver 的 PHP 实现版本，支持 MCP 服务，部署简单，数据私有化。

A pure PHP 8.3+ 1:1 port of [EvoMap/evolver](https://github.com/EvoMap/evolver) as a stdio MCP server with local SQLite storage (WAL + mmap).

---

## 核心特性

- **MCP stdio 服务器** — JSON-RPC 2.0 协议通过 stdin/stdout 通信，零平台依赖
- **GEP 协议** — 标准化的演化流程，包含 5 个必需对象（Mutation → PersonalityState → EvolutionEvent → Gene → Capsule）
- **信号驱动** — 从日志/上下文提取信号，去除重复，检测修复循环
- **Gene/Capsule 双轨制** — Genes = 可复用策略模板，Capsules = 成功结果快照
- **SQLite 存储** — WAL 模式 + mmap 优化，完全本地化/私有化
- **内置 Genes** — 首次运行自动初始化 5 个默认 genes（repair、optimize、innovate、SQLite、security）
- **安全模型** — 爆炸半径限制（60 文件/20000 行）、验证命令白名单、禁止路径保护
- **自动初始化** — 自动创建目录、数据库健康检查、自动迁移

## 环境要求

- PHP 8.3+
- `sqlite3` PHP 扩展（默认已启用）
- `pdo_sqlite` PHP 扩展（默认已启用）

## 安装

```bash
git clone https://github.com/linkerlin/Evolver.php.git
cd Evolver.php
composer install
php evolver.php --validate
```

## MCP 客户端配置

### Claude Desktop

编辑 `~/Library/Application Support/Claude/claude_desktop_config.json`（macOS）或 `%APPDATA%\Claude\claude_desktop_config.json`（Windows）：

```json
{
  "mcpServers": {
    "evolver": {
      "command": "php",
      "args": ["/path/to/Evolver.php/evolver.php"],
      "env": {
        "EVOLVER_DB_PATH": "/path/to/your/evolver.db",
        "EVOLVE_ALLOW_SELF_MODIFY": "review"
      }
    }
  }
}
```

### Kimi Code CLI（推荐）

编辑 `~/.kimi/mcp.json`：

```json
{
  "mcpServers": {
    "evolver": {
      "command": "php",
      "args": ["/path/to/Evolver.php/evolver.php"],
      "env": {
        "EVOLVER_DB_PATH": "~/.evolver/evolver.db",
        "EVOLVE_ALLOW_SELF_MODIFY": "review"
      }
    }
  }
}
```

> **注意**：Kimi Code CLI 完整支持 MCP，是 Evolver.php 的推荐客户端。

### Gemini CLI

编辑 `~/.gemini/config.json`：

```json
{
  "mcpServers": {
    "evolver": {
      "command": "php",
      "args": ["/path/to/Evolver.php/evolver.php"],
      "env": {
        "EVOLVER_DB_PATH": "~/.evolver/evolver.db",
        "EVOLVE_ALLOW_SELF_MODIFY": "review"
      }
    }
  }
}
```

> **警告**：Gemini CLI 的 MCP 实现存在已知兼容性问题。建议使用 Kimi Code CLI。

### 默认数据库路径

如果未指定 `EVOLVER_DB_PATH`，默认值为 `~/.evolver/evolver.db`。

首次运行时会自动创建目录和数据库文件。

## 可用 MCP 工具

| 工具 | 说明 |
|------|------|
| `evolver_run` | 运行演化周期：提取信号、选择 Gene/Capsule、生成 GEP 提示 |
| `evolver_solidify` | 固化结果：验证、记录 EvolutionEvent、更新 Gene、存储 Capsule |
| `evolver_extract_signals` | 从日志内容提取演化信号 |
| `evolver_list_genes` | 列出可用 Genes（支持按分类筛选）|
| `evolver_list_capsules` | 列出可用 Capsules |
| `evolver_list_events` | 查看最近演化事件 |
| `evolver_upsert_gene` | 创建或更新 Gene |
| `evolver_delete_gene` | 删除 Gene |
| `evolver_stats` | 获取存储统计信息 |
| `evolver_safety_status` | 获取当前安全状态 |
| `evolver_cleanup` | 执行清理操作 |
| `evolver_sync_to_hub` | 同步资产到 EvoMap Hub |

## 使用示例

### 运行演化周期

```json
{
  "name": "evolver_run",
  "arguments": {
    "context": "[ERROR] TypeError in module: null pointer dereference",
    "strategy": "balanced"
  }
}
```

### 应用更改后固化结果

```json
{
  "name": "evolver_solidify",
  "arguments": {
    "intent": "repair",
    "summary": "Fixed null pointer by adding null check in module",
    "signals": ["log_error"],
    "blastRadius": {"files": 1, "lines": 5}
  }
}
```

### 从日志提取信号

```json
{
  "name": "evolver_extract_signals",
  "arguments": {
    "logContent": "2024-01-01 [ERROR] Connection timeout after 30s"
  }
}
```

## 策略预设

| 策略 | 说明 |
|------|------|
| `balanced` | 默认 — 按比例处理错误和机会 |
| `innovate` | 最大化新功能和能力扩展 |
| `harden` | 聚焦稳定性、安全性和鲁棒性 |
| `repair-only` | 紧急模式 — 仅修复错误，无创新 |

## 安全模式

通过 `EVOLVE_ALLOW_SELF_MODIFY` 环境变量设置：

| 模式 | 说明 |
|------|------|
| `never` | 完全禁用自修改，仅诊断 |
| `review` | 所有修改需要人工确认（推荐）|
| `always` | 完全自动化（谨慎使用）|

## GEP 协议输出

当 `evolver_run` 生成 GEP 提示时，LLM 必须按顺序输出恰好 5 个 JSON 对象（原始格式，无 markdown）：

1. **Mutation** — 变更触发器，包含风险等级和理由
2. **PersonalityState** — 演化心态（rigor、creativity、verbosity、risk_tolerance、obedience）
3. **EvolutionEvent** — 可审计记录，包含父链、信号、爆炸半径
4. **Gene** — 可复用策略模板（创建新的或更新现有的）
5. **Capsule** — 成功快照，供未来复用

## 架构

```
┌─────────────────────────────────────────┐
│  MCP stdio (evolver.php)                 │
│  - JSON-RPC 2.0 over stdin/stdout        │
├─────────────────────────────────────────┤
│  McpServer.php                           │
│  - Tool dispatch, protocol handling      │
├─────────────────────────────────────────┤
│  Core Engines                            │
│  - SignalExtractor (signal detection)    │
│  - GeneSelector (gene/capsule matching)  │
│  - PromptBuilder (GEP prompt assembly)   │
│  - SolidifyEngine (validation + record)  │
├─────────────────────────────────────────┤
│  Storage Layer                           │
│  - GepAssetStore (genes/capsules/events) │
│  - Database (SQLite WAL + mmap)          │
│  - Auto-migration & health check         │
└─────────────────────────────────────────┘
```

## 运行测试

```bash
composer test
```

## 环境变量

| 变量 | 默认值 | 说明 |
|------|--------|------|
| `EVOLVER_DB_PATH` | `~/.evolver/evolver.db` | SQLite 数据库文件路径 |
| `EVOLVE_ALLOW_SELF_MODIFY` | `always` | 安全模式：never/review/always |
| `A2A_HUB_URL` | - | EvoMap Hub 同步地址 |
| `A2A_NODE_SECRET` | - | 节点认证密钥 |

## 安全模型

- **验证命令白名单**：仅允许 `php`、`composer`、`phpunit`、`phpcs`、`phpstan` 命令
- **禁止 shell 操作符**：`;`、`&&`、`||`、`|`、`>`、`<`、`$()` 都会被拒绝
- **爆炸半径限制**：每次演化的硬限制为 60 个文件和 20,000 行
- **Gene 约束强制执行**：每个 gene 指定自己的 `max_files` 和 `forbidden_paths`
- **源文件保护**：核心引擎文件受保护，防止自修改

## 许可证

MIT

# AGENTS.md - Evolver.php 代码库指南

本文档为在 Evolver.php 代码库中工作的 AI 智能体提供必要的信息。

## 项目概述

Evolver.php 是 Capability Evolver 引擎的 PHP 8.3+ 实现，通过 MCP（Model Context Protocol）stdio 服务器方式暴露，并采用本地 SQLite 存储。它通过信号驱动的 GEP 协议实现能力演化，包含 Genes（可复用策略模板）和 Capsules（成功结果快照）。

**核心特性**：
- 纯 PHP 移植版 EvoMap/evolver
- JSON-RPC 2.0 协议通过 stdin/stdout 通信
- SQLite 数据库采用 WAL 模式和 mmap 优化
- 内置安全模型，包含爆炸半径限制和验证命令白名单
- 默认 Genes 从 `data/default_genes.json` 种子数据初始化

## 核心命令

### 开发
- `composer install` – 安装依赖
- `composer test` – 运行 PHPUnit 测试并输出 testdox
- `php evolver.php --validate` – 验证安装和数据库健康状态
- `php evolver.php --db /path/to.db` – 使用自定义数据库路径
- `php evolver.php` – 启动 MCP stdio 服务器（默认）

### 测试
- 测试文件位于 `tests/`
- 使用内存 SQLite 数据库（`:memory:`）实现隔离测试
- 测试套件包含数据库 schema、GepAssetStore、SignalExtractor、GeneSelector、PromptBuilder、SolidifyEngine 和安全特性
- 运行 `composer test` 或直接 `phpunit --testdox`

### 代码质量
- composer.json 中未配置静态分析和 lint 工具
- 代码遵循 PSR-4 自动加载和严格类型

## 代码组织

### 目录结构
```
Evolver.php/
├── src/                 # 核心源代码 (PSR-4 命名空间 Evolver\)
│   ├── Ops/            # 操作工具 (DiskCleaner, LifecycleManager, SignalDeduplicator)
│   └── *.php           # 主要类文件
├── tests/              # PHPUnit 测试 (命名空间 Evolver\Tests\)
├── data/               # 默认 genes JSON 文件
├── vendor/             # Composer 依赖 (已忽略)
├── .rlm-gepa/          # RLM-GEPA 智能体记忆和上下文存储 (非核心部分)
├── evolver.php         # 主入口点 (MCP stdio 服务器)
├── composer.json       # 项目配置和自动加载
├── phpunit.xml         # PHPUnit 配置
└── README.md           # 项目文档
```

### 命名空间和自动加载
- **主命名空间**: `Evolver\`
- **测试命名空间**: `Evolver\Tests\`
- **PSR-4 自动加载** 在 composer.json 中配置:
  - `Evolver\\` → `src/`
  - `Evolver\\Tests\\` → `tests/`

### 类约定
- 所有 PHP 文件以 `declare(strict_types=1);` 开头
- 适当使用 `final` 类修饰符
- 私有方法用于内部逻辑，公开方法用于 API
- 所有参数和返回值使用类型提示
- 公开方法和复杂逻辑使用文档注释
- 配置值使用常量（如 `SCHEMA_VERSION`）

## 命名和样式规范

- **类名**: `PascalCase`（如 `Database`、`SignalExtractor`）
- **方法名**: `camelCase`（如 `ensureDirectoryExists`、`fetchAll`）
- **变量名**: `camelCase`（如 `$geneSelector`、`$gzpAssetStore`）
- **常量**: `SCREAMING_SNAKE_CASE`（如 `SCHEMA_VERSION`）
- **私有属性**: 以 `$` 开头，使用 camelCase（如 `private \SQLite3 $db;`）

## 测试方法

- 继承 `PHPUnit\Framework\TestCase`
- `setUp()` 方法初始化通用依赖（Database、GepAssetStore 等）
- 使用内存 SQLite 数据库（`:memory:`）实现隔离
- 测试方法命名为 `test*`，返回类型为 `void`
- 断言: `$this->assert*`（如 `assertContains`、`assertNotEmpty`）
- 未观察到模拟框架；依赖真实依赖项

## 重要注意事项

### Schema 版本不一致
- `ContentHash::SCHEMA_VERSION = '1.6.0'`
- `PromptBuilder::SCHEMA_VERSION = '1.5.0'`
- 修改 schema 相关代码时需注意潜在的版本不匹配问题。

### 安全限制（硬编码）
- 爆炸半径硬限制：**60 个文件**，**20,000 行**每次演化
- 验证命令白名单：`['php', 'composer', 'phpunit', 'phpcs', 'phpstan']`
- 禁止的 shell 操作符：`;`, `&&`, `||`, `|`, `>`, `<`, `` ` ``, `$()`
- Gene 特定约束：每个 Gene 定义自己的 `max_files` 和 `forbidden_paths`

### 自修改安全模式
通过环境变量 `EVOLVE_ALLOW_SELF_MODIFY` 控制：
- `never`: 禁用自修改，仅诊断
- `review`: 需要人工确认修改（推荐）
- `always`: 完全自动化（谨慎使用）

### 数据库配置
- 默认数据库路径：`~/.evolver/evolver.db`（可通过 `EVOLVER_DB_PATH` 覆盖）
- SQLite WAL 模式和 mmap 优化已启用
- 自动 schema 迁移和健康检查
- 测试使用内存数据库（`:memory:`）

### MCP 服务器协议
- MCP 服务器期望通过 stdin/stdout 接收 JSON-RPC 2.0 消息
- 工具定义在 `src/McpServer.php`（如 `evolver_run`、`evolver_solidify`）
- 当 `evolver_run` 生成 GEP 提示时，LLM 必须按顺序输出恰好 5 个 JSON 对象：
  1. `Mutation`
  2. `PersonalityState`
  3. `EvolutionEvent`
  4. `Gene`
  5. `Capsule`
- 缺少任何对象都会导致协议失败。

### RLM-GEPA 智能体集成
- `.rlm-gepa/` 目录存储 RLM-GEPA 递归语言模型智能体的记忆、上下文和输出
- 这与核心 Evolver 引擎分离，用于智能体特定实验

## 环境变量

| 变量 | 默认值 | 描述 |
|------|--------|------|
| `EVOLVER_DB_PATH` | `~/.evolver/evolver.db` | SQLite 数据库文件路径 |
| `EVOLVE_ALLOW_SELF_MODIFY` | `always` | 安全模式：`never`、`review` 或 `always` |
| `A2A_HUB_URL` | - | EvoMap Hub 同步地址（可选）|
| `A2A_NODE_SECRET` | - | 认证节点密钥（可选）|

## 安全模型

- **命令验证**：演化期间仅允许白名单中的命令
- **Shell 操作符阻止**：防止注入 shell 操作符
- **爆炸半径限制**：每次演化的文件和行数硬限制
- **源文件保护**：核心引擎文件受保护，防止自修改
- **Gene 约束**：每个 Gene 强制执行自己的限制和禁止路径

## 智能体常见任务

### 添加新 Gene
1. 在 `data/default_genes.json` 中添加条目（或使用 `evolver_upsert_gene` 工具）
2. 确保 `signals_match` 模式对应检测到的信号
3. 定义 `max_files` 和 `forbidden_paths` 约束

### 修改数据库 Schema
1. 更新 `Database::SCHEMA_VERSION` 常量
2. 在 `Database::runMigrations()` 中添加迁移逻辑
3. 如需要，更新 `ContentHash::SCHEMA_VERSION`
4. 运行测试验证迁移

### 创建新 MCP 工具
1. 在 `McpServer::__construct()` 的 `$this->tools` 数组中添加工具定义
2. 实现相应方法（如 `evolver_new_tool`）
3. 添加验证和安全检查
4. 更新 README.md 和 AGENTS.md 文档

### 修改后运行测试
修改后始终运行 `composer test` 确保没有回归：
- 检查数据库 schema 一致性
- 验证环境变量（使用内存数据库）
- 确保没有硬编码路径影响测试隔离

## 参考资料

- [README.md](./README.md) – 详细的项目文档、MCP 配置和示例
- [composer.json](./composer.json) – 依赖和脚本
- [phpunit.xml](./phpunit.xml) – 测试配置
- [data/default_genes.json](./data/default_genes.json) – 默认 gene 定义

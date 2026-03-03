# 🧬 Evolver.php 从入门到进化 🧬

## 一篇面向开发者的科普文章，介绍这个基于 PHP 的 AI 能力演化引擎

---

## 🌿 序言：当代码学会进化

在软件工程的世界里，我们习惯了这样一种信念：代码是静态的，它只会执行我们精确指令的操作。一旦部署，程序就像被冻结的标本，直到下一次人工更新。但想象一下，如果软件能够像生物一样积累经验、从错误中学习、将成功的策略遗传给下一代呢？

这听起来像是科幻小说中的场景，但 Evolver.php 正在将这个愿景变为现实。它不仅仅是一个工具库，更是一个赋予 AI Agent 记忆的基因编辑器。通过将生物学中的进化论思想引入软件系统，Evolver.php 让 PHP 应用拥有了前所未有的能力——自我进化。

> 💡 **核心金句**："Evolver.php 不是工具，而是赋予 AI 记忆的基因编辑器。"

---

## 🎬 第一章：AI 的记忆诅咒

### 📽️ 当 Phil Connors 遇见 AI Agent

1993 年，一部名为《土拨鼠之日》(Groundhog Day) 的电影上映了。故事的主角 Phil Connors 是一个愤世嫉俗的天气预报员，他被困在了 2 月 2 日这一天。每天早上醒来，他发现自己又回到了同一个早晨，面对同样的人群，经历同样的事件。无论他今天学到了什么、改变了什么，第二天一切都会归零重置。

这个荒诞的设定，恰恰是当前 AI Agent 的真实写照。

想象一下这样的场景：你的 AI 助手今天花了十分钟分析了一个棘手的数据库连接超时问题，终于找到了根本原因并修复了它。第二天，当完全相同的问题再次出现时，它却像失忆了一样，从头开始分析、排查、修复。今天积累的经验，明天荡然无存。

这不是 Bug，这是架构设计的根本性缺失。

### 🧠 LLM 的"金鱼记忆"

大型语言模型（LLM）无疑是当今最令人兴奋的技术突破之一。它们博览群书、能言善辩，似乎无所不知。然而，它们有一个致命的弱点：**无状态性（Statelessness）**。

每一次对话，LLM 都是从一张白纸开始。虽然现代模型拥有越来越大的上下文窗口（Claude 可以处理约 200K tokens），但这只是一个临时的"工作记忆"。一旦会话结束，所有内容都会被清空。下一个用户、下一个问题，一切又要从头来过。

> 📖 **深度解析：无状态性**
> 在计算机科学中，无状态意味着系统不保留关于过去交互的任何信息。每次请求都被视为独立的、与之前任何请求无关的新请求。这种设计简化了系统架构，但也意味着无法积累经验。

根据 McKinsey 2024 年的 AI 报告，企业 AI Agent 平均每天要处理 100 多个相似的问题。更令人担忧的是，重复处理相同问题所消耗的计算资源，约占 AI 运行总成本的 30%。这不仅浪费金钱，更浪费了本可以用于创新的时间和精力。

### 🔓 打破诅咒的钥匙

Evolver.php 的出现，正是为了打破这个诅咒。它的核心思想很简单：

**让 AI 拥有可遗传的记忆。**

具体来说，Evolver.php 通过以下机制实现这一目标：

首先，它会从日志、错误信息、用户反馈中提取"信号"——这些信号代表着需要进化干预的机会或威胁。然后，它会匹配预定义的"Gene"——即可复用的策略模板，就像生物体从基因库中调用相应的蛋白质编码一样。当策略成功执行后，系统会将这次成功的经验封装成"Capsule"——一个可被未来复用的快照，就像表观遗传标记记录了环境对基因表达的影响。

最终，AI Agent 不再是每天重新开始的 Phil Connors，而是一个能够积累经验、传承智慧的进化系统。

> 💡 **核心金句**："没有记忆的 AI，就像永远活在这一天的土拨鼠。"

---

## 🔌 第二章：MCP 协议入门

### 🏗️ AI 工具的"巴别塔"问题

在软件开发领域，我们早已习惯了标准化的力量。HTTP 协议让不同的 Web 服务器和浏览器能够无缝通信；SQL 标准让不同的数据库可以被相似的查询语言操作。但在 AI 工具的世界里，很长一段时间都处于"巴别塔"状态——每个工具都有自己的接口、自己的数据格式、自己的通信方式。

如果你想让 Claude 调用一个外部工具，可能需要写一个专用的插件；如果你想让它访问你的本地文件系统，可能需要配置复杂的权限系统；如果你想让它连接你的数据库，那更是一场安全噩梦。

这种碎片化严重阻碍了 AI 生态的发展。直到 Model Context Protocol（MCP）的出现。

### 📡 MCP：AI 世界的通用语

MCP（Model Context Protocol）是由 Anthropic 推出的开放标准，旨在解决 AI 工具之间的互操作性问题。它的设计哲学可以用一句话概括：

**简单到极致，强大到无限。**

MCP 基于 JSON-RPC 2.0 规范，通过标准的 stdin/stdout 流进行通信。这意味着，只要你的程序能够读写标准输入输出，它就可以成为一个 MCP 服务器，被任何支持 MCP 的 AI 客户端调用。

```json
// 一个典型的 MCP 配置示例
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

这段配置告诉 AI 客户端：当你需要使用 Evolver 的能力时，启动一个 PHP 进程，通过 stdin 发送请求，从 stdout 接收响应。就这么简单。

### 🧠 Evolver.php 的角色定位

在 MCP 的生态中，Evolver.php 扮演着一个特殊的角色——它是 AI Agent 的"长期记忆皮层"。

如果将 AI 模型比作大脑，那么短期记忆（上下文窗口）就像是工作记忆，只能容纳当前正在处理的信息。而 Evolver.php 则通过 SQLite 数据库提供了长期存储，让 AI 能够"记住"过去的经验、成功的策略、失败的教训。

更重要的是，Evolver.php 不是一个被动的存储系统。它主动分析信号、匹配策略、生成进化建议。它不仅让 AI 记住，更让 AI 能够从记忆中学习、进化。

### 🌐 支持的客户端

目前，Evolver.php 可以与以下 MCP 客户端无缝集成：

**Claude Desktop**：Anthropic 官方的桌面应用，支持通过配置文件添加 MCP 服务器。

**Kimi Code CLI**：国内领先的 AI 编程助手，完整支持 MCP 协议，是 Evolver.php 的推荐客户端。

**Gemini CLI**：Google 的命令行 AI 工具，虽然存在一些兼容性问题，但基本功能可用。

> 💡 **核心金句**："MCP 是 AI 世界的通用语，让工具之间不再有巴别塔。"

---

## 🧬 第三章：GEP 协议详解

### 📋 五个 JSON 对象的交响曲

如果说 MCP 解决了"如何通信"的问题，那么 GEP（Gene-Encoded Protocol）解决的就是"如何进化"的问题。

GEP 是 Evolver.php 的核心协议，它定义了一套模仿生物遗传过程的输出规范。当 AI 需要进行一次"进化"时，它必须按照严格的顺序输出五个 JSON 对象。这五个对象就像五个乐章，共同谱写出一首完整的进化交响曲。

让我们逐一认识它们：

### 🎯 第一乐章：Mutation（变异）

Mutation 是进化的起点，它标识了触发这次进化的"变异"——可以是一个错误、一个机会、或一个用户请求。

```json
{
  "type": "Mutation",
  "id": "mut_20260303_001",
  "category": "repair",
  "trigger_signals": ["log_error", "sqlite_connection_timeout"],
  "target": "database_module",
  "expected_effect": "Fix connection timeout by adding retry logic",
  "risk_level": "low",
  "rationale": "Connection timeouts are causing 15% of requests to fail"
}
```

这个对象回答了三个关键问题：**发生了什么？**（trigger_signals）**我们要做什么？**（expected_effect）**为什么这样做？**（rationale）

### 🎭 第二乐章：PersonalityState（人格状态）

进化不是机械的修复，它需要"心态"的配合。PersonalityState 定义了 AI 在这次进化中的工作风格。

```json
{
  "type": "PersonalityState",
  "rigor": 0.8,
  "creativity": 0.3,
  "verbosity": 0.5,
  "risk_tolerance": 0.2,
  "obedience": 0.9
}
```

当 `rigor`（严谨度）高而 `creativity`（创造力）低时，AI 会采取保守的修复策略。当 `risk_tolerance`（风险容忍度）低而 `obedience`（服从度）高时，AI 会严格遵守安全约束。这套机制确保进化过程既灵活又可控。

### 📜 第三乐章：EvolutionEvent（进化事件）

EvolutionEvent 是这次进化的"出生证明"，它记录了所有关键信息，供未来审计和追溯。

```json
{
  "type": "EvolutionEvent",
  "schema_version": "1.6.0",
  "id": "evt_20260303_001",
  "parent": "evt_20260302_042",
  "intent": "repair",
  "signals": ["log_error", "sqlite_connection_timeout"],
  "genes_used": ["gene_gep_repair_from_errors"],
  "mutation_id": "mut_20260303_001",
  "personality_state": { ... },
  "blast_radius": { "files": 2, "lines": 45 },
  "outcome": { "status": "success", "score": 0.92 }
}
```

注意 `parent` 字段——它指向父进化事件，形成了一条完整的进化链。通过追溯这条链，我们可以理解 AI 是如何一步步从初始状态进化到当前状态的。

### 🧬 第四乐章：Gene（基因）

Gene 是这次进化使用的核心策略，它是可复用的知识单元。就像生物体的基因编码了蛋白质的合成指令，Evolver 的 Gene 编码了问题解决的策略步骤。

```json
{
  "type": "Gene",
  "schema_version": "1.6.0",
  "id": "gene_gep_repair_from_errors",
  "category": "repair",
  "signals_match": ["error", "exception", "failed", "unstable", "log_error"],
  "preconditions": ["signals contains error-related indicators"],
  "strategy": [
    "Extract structured signals from logs and user instructions",
    "Select an existing Gene by signals match",
    "Estimate blast radius before editing",
    "Apply smallest reversible patch",
    "Validate using declared validation steps; rollback on failure",
    "Solidify knowledge: append EvolutionEvent, update Gene/Capsule store"
  ],
  "constraints": {
    "max_files": 20,
    "forbidden_paths": [".git", "vendor", "node_modules"]
  },
  "validation": ["php -l src/*.php", "php evolver.php --validate"]
}
```

这个 Gene 定义了修复错误的完整流程：从信号提取到策略选择，从影响评估到最小化修改，从验证执行到知识固化。它是一个经过验证的、可复用的策略模板。

### 💊 第五乐章：Capsule（胶囊）

Capsule 是这次进化成功的"快照"，它记录了具体的执行细节和结果，供未来相似场景复用。

```json
{
  "type": "Capsule",
  "schema_version": "1.6.0",
  "id": "capsule_20260303_001",
  "trigger": ["log_error", "sqlite_connection_timeout"],
  "gene": "gene_gep_repair_from_errors",
  "summary": "Added retry logic with exponential backoff to SQLite connection handler",
  "confidence": 0.92,
  "blast_radius": { "files": 2, "lines": 45 }
}
```

如果说 Gene 是抽象的策略，那么 Capsule 就是策略在特定场景下的具体实现。它就像疫苗中的"减毒活病毒"——保留了有效性，但经过了安全处理。

### 🔄 五者的关系

这五个对象形成了一个完整的闭环：

Mutation 触发进化 → PersonalityState 设定心态 → EvolutionEvent 记录过程 → Gene 提供策略 → Capsule 保存结果

下次遇到相似的场景时，系统可以直接从 Capsule 中提取经验，而不需要从头开始推理。这就是"可遗传记忆"的实现机制。

> 💡 **核心金句**："五个 JSON 对象，编码了 AI 进化的完整生命周期。"

---

## 🏛️ 第四章：Evolver.php 架构

### 🧩 四大核心组件

Evolver.php 的架构设计遵循"单一职责"原则，将复杂的进化流程分解为四个独立但协作的组件。让我们像解剖一个细胞一样，逐一观察这些"细胞器"。

#### 🔍 SignalExtractor（信号提取器）

SignalExtractor 是系统的"感觉器官"，它负责从各种输入源中识别和提取进化信号。

```php
// src/SignalExtractor.php 核心逻辑
final class SignalExtractor
{
    public const OPPORTUNITY_SIGNALS = [
        'user_feature_request',
        'user_improvement_suggestion',
        'perf_bottleneck',
        'capability_gap',
        'stable_success_plateau',
        'external_opportunity',
        // ... 更多信号类型
    ];

    public function extract(array $input): array
    {
        $signals = [];
        $corpus = implode("\n", array_filter([
            $input['context'] ?? '',
            $input['recentSessionTranscript'] ?? '',
            $input['todayLog'] ?? '',
        ]));
        
        // 检测错误信号
        if (preg_match('/\[error\]|error:|exception:/i', $corpus)) {
            $signals[] = 'log_error';
        }
        
        // 检测修复循环
        if ($this->detectRepairLoop($input['recentEvents'] ?? [])) {
            $signals[] = 'repair_loop_detected';
        }
        
        return $signals;
    }
}
```

信号分为两大类：**防御性信号**（如错误、异常、资源缺失）和**机会性信号**（如用户需求、性能瓶颈、能力缺口）。系统会根据信号类型选择不同的进化策略。

#### 🎯 GeneSelector（基因选择器）

GeneSelector 是系统的"决策中枢"，它负责从基因库中匹配合适的 Gene 或 Capsule。

```php
// src/GeneSelector.php 核心逻辑
final class GeneSelector
{
    public function selectBestGene(
        array $genes,
        array $capsules,
        array $signals,
        string $strategy
    ): array {
        // 评分所有 Gene
        $scored = [];
        foreach ($genes as $gene) {
            $score = $this->scoreGene($gene, $signals);
            if ($score > 0) {
                $scored[$gene['id']] = $score;
            }
        }
        
        // 根据策略调整选择
        if ($strategy === 'repair-only') {
            // 只选择 repair 类型的 Gene
            $scored = array_filter($scored, fn($id) => 
                $this->getGeneCategory($id) === 'repair');
        }
        
        // 返回最高分的 Gene
        arsort($scored);
        return $this->getGeneById(array_key_first($scored));
    }
}
```

选择过程考虑多个因素：信号匹配度、策略预设、历史成功率等。系统还支持"遗传漂移"（Genetic Drift）机制，允许一定程度的随机探索，避免陷入局部最优。

#### 📝 PromptBuilder（提示构建器）

PromptBuilder 是系统的"翻译官"，它将结构化的数据转换为 LLM 可理解的 GEP 提示。

```php
// src/PromptBuilder.php 核心逻辑
final class PromptBuilder
{
    public function buildGepPrompt(array $input): string
    {
        $context = $input['context'] ?? '';
        $signals = $input['signals'] ?? [];
        $selectedGene = $input['selectedGene'] ?? null;
        
        $prompt = self::SCHEMA_DEFINITIONS . "\n\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━\n";
        $prompt .= "II. Current Evolution Context\n";
        $prompt .= "━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $prompt .= "Detected Signals: " . implode(", ", $signals) . "\n";
        $prompt .= "Selected Gene: " . ($selectedGene['id'] ?? 'none') . "\n";
        $prompt .= "Context (truncated):\n" . substr($context, 0, 20000) . "\n";
        
        return $prompt;
    }
}
```

构建的提示包含完整的 GEP Schema 定义、当前信号、选中的 Gene、以及相关的上下文。LLM 根据这个提示输出五个 JSON 对象。

#### ✅ SolidifyEngine（固化引擎）

SolidifyEngine 是系统的"归档员"，它负责验证和持久化进化结果。

```php
// src/SolidifyEngine.php 核心逻辑
final class SolidifyEngine
{
    public function solidify(array $params): array
    {
        // 验证爆炸半径
        $blastRadius = $params['blastRadius'] ?? [];
        if ($blastRadius['files'] > 60 || $blastRadius['lines'] > 20000) {
            return ['success' => false, 'error' => 'Blast radius exceeded'];
        }
        
        // 验证命令白名单
        foreach ($params['validationCommands'] ?? [] as $cmd) {
            if (!$this->isCommandAllowed($cmd)) {
                return ['success' => false, 'error' => 'Forbidden command'];
            }
        }
        
        // 记录 EvolutionEvent
        $event = $this->createEvolutionEvent($params);
        $this->store->appendEvent($event);
        
        // 更新 Gene（如果有新知识）
        if ($params['gene']) {
            $this->store->upsertGene($params['gene']);
        }
        
        // 存储 Capsule（如果成功）
        if ($params['outcome']['status'] === 'success' && $params['capsule']) {
            $this->store->appendCapsule($params['capsule']);
        }
        
        return ['success' => true];
    }
}
```

固化过程包含严格的安全检查：爆炸半径限制、命令白名单验证、禁止路径检查等。只有通过所有检查的结果才会被持久化。

### 🔄 数据流全景图

```
┌─────────────────────────────────────────────────────────────┐
│                        外部输入                               │
│  (日志 / 错误信息 / 用户指令 / 会话记录)                      │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    🔍 SignalExtractor                        │
│  提取信号：log_error, repair_loop_detected, capability_gap  │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    🎯 GeneSelector                           │
│  匹配 Gene/Capsule，根据策略调整选择                         │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    📝 PromptBuilder                          │
│  构建 GEP 提示，包含 Schema + Context + Gene                │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                      🤖 LLM                                  │
│  输出 5 个 JSON 对象：Mutation → Personality → Event →      │
│  Gene → Capsule                                             │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                    ✅ SolidifyEngine                         │
│  验证安全约束 → 记录 Event → 更新 Gene → 存储 Capsule       │
└─────────────────────────┬───────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                      🗄️ SQLite                              │
│  持久化存储：genes, capsules, events, asset_call_log        │
└─────────────────────────────────────────────────────────────┘
```

> 💡 **核心金句**："四大组件协同工作，像细胞器一样各司其职。"

---

## 🧬 第五章：Gene 与 Capsule

### 🧬 DNA 与表观遗传的数字类比

在生物学中，**基因（Gene）**是遗传信息的基本单位，它编码了蛋白质的合成指令。而**表观遗传标记（Epigenetic Marks）**则是环境因素对基因表达的调控，它决定了哪些基因被激活、哪些被沉默。

Evolver.php 巧妙地借鉴了这个概念：

**Gene** 是可复用的策略模板，就像 DNA 中的基因序列。它定义了"如何解决某类问题"的通用方法。

**Capsule** 是成功的经验快照，就像表观遗传标记。它记录了"在特定情境下，某个 Gene 是如何成功应用的"。

### 📚 五大内置 Gene

Evolver.php 在首次运行时会自动初始化五个默认 Gene，覆盖了最常见的进化场景：

#### 🔧 gene_gep_repair_from_errors

这是最常用的 Gene，用于处理各种错误和异常。

```json
{
  "id": "gene_gep_repair_from_errors",
  "category": "repair",
  "signals_match": ["error", "exception", "failed", "unstable", "log_error"],
  "strategy": [
    "Extract structured signals from logs",
    "Select existing Gene by signals match",
    "Estimate blast radius before editing",
    "Apply smallest reversible patch",
    "Validate; rollback on failure",
    "Solidify knowledge"
  ],
  "constraints": { "max_files": 20 }
}
```

#### ⚡ gene_gep_optimize_prompt_and_assets

用于优化系统自身的提示词和资产。

```json
{
  "id": "gene_gep_optimize_prompt_and_assets",
  "category": "optimize",
  "signals_match": ["protocol", "gep", "prompt", "audit", "optimize"],
  "strategy": [
    "Extract signals and determine selection rationale",
    "Prefer reusing existing Gene/Capsule",
    "Refactor prompt assembly",
    "Reduce noise and ambiguity",
    "Validate and solidify"
  ]
}
```

#### 🚀 gene_gep_innovate_from_opportunity

用于处理创新机会，如用户功能请求或能力缺口。

```json
{
  "id": "gene_gep_innovate_from_opportunity",
  "category": "innovate",
  "signals_match": [
    "user_feature_request",
    "capability_gap",
    "external_opportunity"
  ],
  "preconditions": [
    "at least one opportunity signal is present",
    "no active log_error signals (stability first)"
  ]
}
```

#### 🗄️ gene_gep_repair_sqlite

专门处理 SQLite 数据库相关的问题。

```json
{
  "id": "gene_gep_repair_sqlite",
  "category": "repair",
  "signals_match": ["sqlite", "database", "db_error", "pdo", "sql"],
  "strategy": [
    "Check SQLite file permissions and path",
    "Verify WAL mode and mmap_size settings",
    "Test database connectivity",
    "Repair schema if tables are missing"
  ]
}
```

#### 🔒 gene_gep_harden_security

用于系统安全加固。

```json
{
  "id": "gene_gep_harden_security",
  "category": "optimize",
  "signals_match": ["security", "injection", "xss", "validation", "harden"],
  "strategy": [
    "Identify all user-controlled inputs",
    "Apply strict input validation",
    "Use parameterized queries for SQL",
    "Enforce command whitelist"
  ]
}
```

### 💊 Capsule 的工作机制

当一个 Gene 成功解决了一个问题后，系统会创建一个 Capsule 来记录这次成功经验。

假设我们使用 `gene_gep_repair_from_errors` 修复了一个 SQLite 连接超时问题，系统会生成如下 Capsule：

```json
{
  "type": "Capsule",
  "id": "capsule_20260303_sqlite_timeout",
  "trigger": ["log_error", "sqlite_connection_timeout"],
  "gene": "gene_gep_repair_from_errors",
  "summary": "Added retry logic with exponential backoff to SQLite connection",
  "confidence": 0.92,
  "blast_radius": { "files": 2, "lines": 45 }
}
```

下次遇到相似的"SQLite 连接超时"问题时，系统会优先查找匹配的 Capsule。如果找到高置信度的 Capsule，可以直接复用其中的经验，而不需要重新推理。

### 🧪 知识检查点

**问题**：Gene 和 Capsule 的主要区别是什么？

**答案**：Gene 是通用的策略模板（"如何解决问题"），Capsule 是特定场景下的成功快照（"这个问题是如何被解决的"）。就像 DNA 提供了蛋白质合成的通用指令，而表观遗传标记决定了在特定组织/环境中哪些基因被激活。

> 💡 **核心金句**："Gene 是 DNA，Capsule 是表观遗传标记。"

---

## 🚀 第六章：实战演练

### 📦 第一步：安装与验证

让我们开始一段实战之旅，亲手体验 Evolver.php 的进化能力。

首先，克隆项目并安装依赖：

```bash
git clone https://github.com/linkerlin/Evolver.php.git
cd Evolver.php
composer install
```

然后，验证安装是否成功：

```bash
php evolver.php --validate
```

如果一切正常，你会看到类似以下的输出：

```
✓ Database initialized
✓ Schema version: 1.6.0
✓ Default genes loaded: 5
✓ SQLite WAL mode enabled
✓ All checks passed
```

### ⚙️ 第二步：配置 MCP 客户端

以 Kimi Code CLI 为例，编辑 `~/.kimi/mcp.json`：

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

这里有两个重要的环境变量：

**EVOLVER_DB_PATH**：指定 SQLite 数据库的存储路径。默认为 `~/.evolver/evolver.db`。

**EVOLVE_ALLOW_SELF_MODIFY**：控制自修改的安全模式。有三个选项：
- `never`：完全禁用自修改，仅诊断
- `review`：所有修改需要人工确认（推荐）
- `always`：完全自动化（谨慎使用）

### 🔄 第三步：运行演化周期

现在，让我们通过 MCP 工具运行一次演化周期。假设我们在日志中发现了一个错误：

```json
{
  "name": "evolver_run",
  "arguments": {
    "context": "[ERROR] TypeError in module: null pointer dereference at line 42",
    "strategy": "balanced"
  }
}
```

Evolver.php 会返回一个 GEP 提示，指导 LLM 输出五个 JSON 对象。

### ✅ 第四步：固化结果

当 LLM 完成修复并输出五个 JSON 对象后，调用 `evolver_solidify` 来固化结果：

```json
{
  "name": "evolver_solidify",
  "arguments": {
    "intent": "repair",
    "summary": "Fixed null pointer by adding null check in module",
    "signals": ["log_error", "typeerror"],
    "blastRadius": {"files": 1, "lines": 5}
  }
}
```

### 🛡️ 第五步：理解安全模型

Evolver.php 内置了多层安全保护：

**爆炸半径限制**：每次演化最多修改 60 个文件、20000 行代码。

**命令白名单**：只允许执行 `php`、`composer`、`phpunit`、`phpcs`、`phpstan` 命令。

**Shell 操作符阻止**：禁止使用 `;`、`&&`、`||`、`|`、`>`、`<`、`$()` 等操作符。

**禁止路径保护**：`.git`、`vendor`、`node_modules` 等目录不可修改。

**源文件保护**：核心引擎文件（如 `McpServer.php`、`SafetyController.php`）受保护，防止自修改。

### 📊 查看演化历史

使用 `evolver_list_events` 查看最近的演化事件：

```json
{
  "name": "evolver_list_events",
  "arguments": { "limit": 10 }
}
```

返回结果会显示每次进化的时间、意图、使用的 Gene、以及结果状态。

### 🧪 知识检查点

**任务**：完成一次完整的演化流程，从错误日志到结果固化。

**步骤**：
1. 准备包含错误的上下文
2. 调用 `evolver_run` 获取 GEP 提示
3. 让 LLM 输出五个 JSON 对象
4. 应用修改（人工或自动）
5. 调用 `evolver_solidify` 固化结果
6. 使用 `evolver_list_events` 验证记录

> 💡 **核心金句**："五分钟，让你的 AI Agent 拥有可遗传的记忆。"

---

## 🌅 结语：进化的下一步

当我们回顾软件发展的历史，会发现一个清晰的趋势：从静态到动态，从被动到主动，从无记忆到有记忆。

第一代软件是静态的指令集合，只能执行预设的操作。第二代软件引入了配置和参数，能够根据输入调整行为。第三代软件——也就是我们正在见证的——开始拥有"学习"和"进化"的能力。

Evolver.php 是这个趋势的一个缩影。它不是要创造一个完美无缺的系统，而是要创造一个**能够自我改进的系统**。就像生物进化不追求"最优"，而是追求"适应"——只要比昨天更好一点，就是进化的胜利。

当软件开始进化，我们正在见证一个新物种的诞生。它们不再是我们手中的工具，而是能够与我们共同成长的伙伴。

而这个故事的下一章，将由你来书写。

---

## 📚 参考资料

**项目链接**：
- [Evolver.php GitHub](https://github.com/linkerlin/Evolver.php)
- [EvoMap/evolver (原版 Node.js)](https://github.com/EvoMap/evolver)

**协议文档**：
- [Model Context Protocol (MCP)](https://modelcontextprotocol.io)
- [JSON-RPC 2.0 Specification](https://www.jsonrpc.org/specification)

**相关技术**：
- [SQLite WAL Mode](https://www.sqlite.org/wal.html)
- [PHP 8.3 Release Notes](https://www.php.net/releases/8.3/)

---

*本文由 AI 协助创作，遵循 CC BY-SA 4.0 协议*

> 🧬 "在代码的世界里，进化不是选择，而是必然。"

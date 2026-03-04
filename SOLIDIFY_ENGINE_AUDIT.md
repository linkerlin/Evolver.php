# SolidifyEngine 审计报告

> 对比分析：evolver (Node.js) vs Evolver.php
> 日期：2026-03-04

## 概述

| 指标 | evolver | Evolver.php | 差距 |
|------|---------|-------------|------|
| 总行数 | 1,569 | 1,036 | -533 行 (-34%) |
| 功能数量 | ~25 个主要函数 | ~18 个主要方法 | -7 个 |

## 功能对比矩阵

### 核心功能

| 功能 | evolver | Evolver.php | 状态 | 优先级 |
|------|---------|-------------|------|--------|
| **solidify** 主流程 | ✅ | ✅ | 🟢 对齐 | - |
| **blast radius 计算** | ✅ | ⚠️ 简化 | 🟡 需增强 | 高 |
| **git 变更追踪** | ✅ | ✅ | 🟢 对齐 | - |
| **约束检查** | ✅ | ✅ | 🟢 对齐 | - |
| **验证执行** | ✅ | ✅ | 🟢 对齐 | - |
| **回滚机制** | ✅ | ✅ | 🟢 对齐 | - |
| **金丝雀检查** | ✅ | ✅ | 🟢 对齐 | - |
| **Epigenetic 标记** | ✅ | ✅ | 🟢 对齐 | - |
| **Gene 自动创建** | ✅ | ✅ | 🟢 对齐 | - |

### 缺失功能（evolver 有，Evolver.php 无）

| 功能 | evolver 位置 | 描述 | 优先级 |
|------|-------------|------|--------|
| **LLM Review** | `llmReview.js` | LLM 辅助代码审查 | 🟡 中 |
| **Narrative Memory** | `narrativeMemory.js` | 叙事性记忆记录 | 🟡 中 |
| **OpenClaw Constraint Policy** | `readOpenclawConstraintPolicy()` | OpenClaw 约束策略读取 | 🟢 低 |
| **Advanced Blast Radius** | `computeBlastRadius()` | 高级爆炸半径计算 | 🔴 高 |
| **New Skill Detection** | `newSkillDirs` 处理 | 新技能目录检测 | 🟡 中 |
| **Constraint Policy Parsing** | `isConstraintCountedPath()` | 约束策略解析 | 🟡 中 |

## 详细分析

### 1. Blast Radius 计算差距 🔴

**evolver 实现 (1569行版本):**
```javascript
function computeBlastRadius({ repoRoot, baselineUntracked }) {
  // 详细计算变更文件
  // 统计行数变化
  // 分析目录分布
  // 生成详细报告
}
```

**Evolver.php 现状:**
- 有基础 blast radius 估算
- 缺少详细的 numstat 分析
- 缺少目录分布分析

**建议:** 增强 blast radius 计算功能

### 2. LLM Review 功能 🟡

**evolver 实现:**
- `isLlmReviewEnabled()` - 检查 LLM review 是否启用
- `runLlmReview()` - 执行 LLM 代码审查

**Evolver.php 现状:**
- 完全缺失

**建议:** 可选添加，非核心功能

### 3. Narrative Memory 功能 🟡

**evolver 实现:**
- `recordNarrative()` - 记录叙事性记忆

**Evolver.php 现状:**
- 完全缺失

**建议:** 可选添加，MemoryGraph 已提供类似功能

### 4. OpenClaw 特定功能 🟢

**evolver 实现:**
- `readOpenclawConstraintPolicy()` - 读取 OpenClaw 约束
- `newSkillDirs` 处理 - 新技能目录特殊处理

**Evolver.php 现状:**
- 部分提及 `openclaw.json` 作为受保护文件
- 无特定处理逻辑

**建议:** 可选添加，MCP 架构不依赖 OpenClaw

## 建议实施

### 高优先级（本周完成）

#### 1. 增强 Blast Radius 计算

**新增功能：**
```php
// SolidifyEngine.php

/**
 * Compute detailed blast radius statistics.
 */
public function computeBlastRadius(string $repoRoot, array $baselineUntracked = []): array
{
    $changedFiles = $this->gitListChangedFiles($repoRoot);
    
    // Parse numstat for line counts
    $numstat = $this->runGitNumstat($repoRoot);
    $lineStats = $this->parseNumstatRows($numstat);
    
    // Analyze directory breakdown
    $breakdown = $this->analyzeBlastRadiusBreakdown($changedFiles, 10);
    
    return [
        'files' => count($changedFiles),
        'linesAdded' => $lineStats['added'] ?? 0,
        'linesDeleted' => $lineStats['deleted'] ?? 0,
        'breakdown' => $breakdown,
        'topDirectories' => array_slice($breakdown, 0, 5),
    ];
}

private function runGitNumstat(string $repoRoot): string
{
    $result = $this->runGitCommand('diff --numstat', $repoRoot);
    return $result['output'] ?? '';
}

private function parseNumstatRows(string $text): array
{
    $added = 0;
    $deleted = 0;
    
    foreach (explode("\n", $text) as $line) {
        $parts = preg_split('/\s+/', trim($line), 3);
        if (count($parts) >= 2 && is_numeric($parts[0]) && is_numeric($parts[1])) {
            $added += (int)$parts[0];
            $deleted += (int)$parts[1];
        }
    }
    
    return ['added' => $added, 'deleted' => $deleted];
}
```

**预计工时：** 2-3 小时

---

### 中优先级（按需实施）

#### 2. LLM Review 功能

```php
// 新增 src/LlmReviewer.php

class LlmReviewer
{
    public function isEnabled(): bool
    {
        return getenv('EVOLVE_LLM_REVIEW') === 'true';
    }
    
    public function review(array $params): array
    {
        // 调用 LLM 进行代码审查
        // 返回审查结果和建议
    }
}
```

**预计工时：** 3-4 小时

#### 3. New Skill Detection

```php
// SolidifyEngine.php

public function detectNewSkills(string $repoRoot): array
{
    $skillsDir = $repoRoot . '/skills';
    if (!is_dir($skillsDir)) {
        return [];
    }
    
    $newSkills = [];
    foreach (glob($skillsDir . '/*', GLOB_ONLYDIR) as $dir) {
        $skillName = basename($dir);
        if ($this->isNewSkill($skillName)) {
            $newSkills[] = [
                'name' => $skillName,
                'path' => $dir,
                'files' => $this->countSkillFiles($dir),
            ];
        }
    }
    
    return $newSkills;
}
```

**预计工时：** 1-2 小时

---

### 低优先级（可选）

#### 4. OpenClaw Constraint Policy

考虑到 Evolver.php 使用 MCP 架构而非 OpenClaw，此功能优先级较低。

---

## 结论

### 现状评估

**SolidifyEngine 功能完整性：约 85%**

- 核心固化流程：✅ 完整
- 安全约束检查：✅ 完整
- 回滚机制：✅ 完整
- Blast radius：⚠️ 基础（需增强）
- 高级功能：❌ 缺失（LLM review, narrative memory）

### 建议行动

1. **立即执行** - 增强 blast radius 计算（高优先级）
2. **本周完成** - 添加新技能检测
3. **按需执行** - LLM review 和 narrative memory（非核心）

### 行数差异解释

533 行差异主要来自：
- Blast radius 详细计算 (~150 行)
- LLM review 功能 (~200 行)
- Narrative memory (~100 行)
- OpenClaw 特定逻辑 (~83 行)

这些功能在 Evolver.php 的 MCP 架构中并非全部必需。

---

**审计完成时间：** 2026-03-04
**建议下次审计：** 功能增强后

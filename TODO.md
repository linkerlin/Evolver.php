# TODO.md - Evolver.php 补全计划

> 基于《改进计划.md》与代码对比分析
> 更新时间：2026-03-03 (已完成 + Bug修复)

---

## 📊 进度概览

| 状态 | 任务 | 说明 |
|------|------|------|
| ✅ 已完成 | 全部任务 | 19项任务全部完成 |

**代码行数**: ~3,138 → ~18,500 (+490%)
**测试文件**: 1 → 23 (+2200%)
**测试方法**: 436 个

---

## ✅ 已完成任务

### 🔴 关键任务

#### 1. AssetCallLog 系统 ✅
- [x] 创建 `src/AssetCallLog.php`
- [x] 实现 `log(string $action, array $asset, array $context): void`
- [x] 实现 `getHistory(?string $assetId = null, int $limit): array`
- [x] 实现 `getFrequentlyUsed(int $limit = 10, ?string $assetType): array`
- [x] 实现 `summarize(): array`
- [x] 实现 `getRecommendations(array $signals, string $intent, int $limit): array`
- [x] 实现 `cleanup(int $daysToKeep): int`
- [x] 实现 `reset(): void`
- [x] 创建 `tests/AssetCallLogTest.php` (12 tests)

#### 2. CLI 脚本工具集 ✅
- [x] 创建 `scripts/` 目录
- [x] 创建 `scripts/evolver-validate.php`
- [x] 创建 `scripts/evolver-report.php`
- [x] 创建 `scripts/evolver-a2a-export.php`
- [x] 创建 `scripts/evolver-a2a-ingest.php`

---

### 🟡 测试补全 ✅

#### 核心模块测试
- [x] `tests/MemoryGraphTest.php` (13 tests)
- [x] `tests/CandidatesTest.php` (4 tests)
- [x] `tests/QuestionGeneratorTest.php` (4 tests)
- [x] `tests/AssetCallLogTest.php` (12 tests)

#### Ops 模块测试
- [x] `tests/Ops/InnovationTest.php` (1 test)
- [x] `tests/Ops/CommentaryTest.php` (3 tests)
- [x] `tests/Ops/SkillsMonitorTest.php` (4 tests)
- [x] `tests/Ops/BenchmarkToolTest.php` (6 tests)

---

## ✅ 已完成任务（来自改进计划.md）

| 阶段 | 任务 | 文件 |
|------|------|------|
| 1.1 | SessionLogReader | `src/SessionLogReader.php` |
| 1.2 | SignalExtractor增强 | `src/SignalExtractor.php` |
| 1.3 | PromptBuilder增强 | `src/PromptBuilder.php` |
| 1.4 | QuestionGenerator | `src/QuestionGenerator.php` |
| 2.1 | A2A协议 | `src/GepA2AProtocol.php`, `src/EvoMapClient.php`, `src/TaskReceiver.php` |
| 2.3 | DeviceId | `src/DeviceId.php` |
| 3.1 | MemoryGraph | `src/MemoryGraph.php` |
| 3.2 | Candidates | `src/Candidates.php` |
| 3.3 | Mutation/Personality | `buildMutation()`, `isHighRiskAllowed()`, `selectPersonalityForRun()` |
| 4.1 | Innovation | `src/Ops/Innovation.php` |
| 4.2 | Commentary | `src/Ops/Commentary.php` |
| 4.3 | SkillsMonitor | `src/Ops/SkillsMonitor.php` |
| 5.1 | 测试拆分 | 23个测试文件, 436个测试方法 |

---

## 验收标准

- [x] `composer test` 全部通过 (Exit code: 0)
- [x] 测试文件数量 >= 20 (实际: 23)
- [x] AssetCallLog 功能完整
- [x] 至少3个CLI脚本可用 (实际: 4个)

---

## 🟢 次要任务（可选 - 未实现）

- [ ] 性能基准测试完善 (BenchmarkTool 已实现基础版)
- [ ] 自动更新检查机制
- [ ] 剪贴板集成（Bridge.php 扩展）

---

## 🐛 Bug修复 (2026-03-03)

修复了以下源代码bug：

| 文件 | 问题 | 修复 |
|------|------|------|
| `src/McpServer.php:464` | 变量名中文字符 `$modification检查` | 改为 `$modificationCheck` |
| `src/McpServer.php:488` | 变量名中文字符 `$dry运行` | 改为 `$dryRun` |
| `src/Ops/OpsManager.php:64,175` | 变量名中文字符 `$dry运行` | 改为 `$dryRun` |
| `src/Ops/DiskCleaner.php:58` | 变量名中文字符 `$space检查` | 改为 `$spaceCheck` |
| `src/EvoMapClient.php:293` | `curl_close()` PHP 8.5弃用 | 移除调用 |
| `tests/McpServerTest.php:511` | `setAccessible()` PHP 8.5弃用 | 移除调用 |
| `tests/McpServerTest.php:469` | sync_to_hub测试假设有Hub | 改为条件断言 |

---

*最后更新：2026-03-03 - 全部完成 + Bug修复*

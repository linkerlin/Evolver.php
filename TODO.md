# Evolver.php 改进任务清单

> 基于《改进意见.md》制定的执行计划
> 创建时间：2026-03-03

---

## 📋 任务总览

| 阶段 | 任务数 | 预计工期 | 状态 |
|------|--------|----------|------|
| 阶段一：高优先级修复 | 4 | 2-3 天 | 🔄 进行中 |
| 阶段二：架构优化 | 4 | 3-4 天 | ⏳ 待开始 |
| 阶段三：质量提升 | 4 | 2-3 天 | ⏳ 待开始 |
| 阶段四：低优先级 | 4 | 按需 | ⏳ 待开始 |

---

## 🔴 阶段一：高优先级修复（立即执行）

### 任务 1.1：统一 Schema 版本
**优先级**：🔴 高  
**预计耗时**：1-2 小时  
**状态**：⏳ 待开始

**问题描述**：
- ContentHash::SCHEMA_VERSION = '1.6.0'
- PromptBuilder::SCHEMA_VERSION = '1.5.0'
- Database::SCHEMA_VERSION = '1.6.0'

**完成标准**：
- [ ] 所有 Schema 版本统一为 '1.6.0'
- [ ] 创建单一版本源（Config 类或常量文件）
- [ ] 版本一致性检查工具
- [ ] 测试通过

**相关文件**：
- `src/ContentHash.php`
- `src/PromptBuilder.php`
- `src/Database.php`

---

### 任务 1.2：修复 SQL 注入风险
**优先级**：🔴 高  
**预计耗时**：2-3 小时  
**状态**：⏳ 待开始

**问题描述**：
```php
$this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
```

**完成标准**：
- [ ] 审计所有动态 SQL
- [ ] 使用参数化查询替代字符串拼接
- [ ] 添加输入验证和净化
- [ ] 安全测试通过

**相关文件**：
- `src/GepAssetStore.php`
- `src/Database.php`
- 所有使用动态 SQL 的文件

---

### 任务 1.3：完善输入验证层
**优先级**：🔴 高  
**预计耗时**：3-4 小时  
**状态**：⏳ 待开始

**问题描述**：MCP 工具参数缺乏严格验证

**完成标准**：
- [ ] 创建 `InputValidator` 类
- [ ] 为所有 12 个 MCP 工具添加参数验证
- [ ] 类型检查、范围检查、大小限制
- [ ] 路径遍历防护
- [ ] 错误消息规范化

**相关文件**：
- `src/McpServer.php`
- 新建 `src/InputValidator.php`

---

### 任务 1.4：拆分测试文件结构
**优先级**：🔴 高  
**预计耗时**：2-3 小时  
**状态**：⏳ 待开始

**问题描述**：所有测试在单个 2911 行的文件中

**完成标准**：
- [ ] 按功能拆分测试文件
- [ ] 保持所有现有测试用例
- [ ] 测试覆盖率不下降
- [ ] 修复废弃/跳过的测试

**目标结构**：
```
tests/
├── DatabaseTest.php
├── SignalExtractorTest.php
├── GeneSelectorTest.php
├── PromptBuilderTest.php
├── SolidifyEngineTest.php
├── ContentHashTest.php
├── SafetyControllerTest.php
├── SourceProtectorTest.php
├── EnvFingerprintTest.php
├── McpServerTest.php
└── Ops/
    ├── DiskCleanerTest.php
    ├── LifecycleManagerTest.php
    └── SignalDeduplicatorTest.php
```

---

## 🟡 阶段二：架构优化（近期执行）

### 任务 2.1：统一错误处理模式
**优先级**：🟡 中  
**预计耗时**：3-4 小时  
**状态**：⏳ 待开始

**问题描述**：错误处理方式不一致（返回数组 vs 抛出异常）

**完成标准**：
- [ ] 创建异常类层级：
  - `EvolverException`（基础）
  - `ValidationException`
  - `SecurityException`
  - `DatabaseException`
- [ ] 统一错误返回格式
- [ ] 更新所有错误处理代码
- [ ] 测试通过

**相关文件**：
- 新建 `src/Exceptions/` 目录
- 所有抛出异常或返回错误数组的文件

---

### 任务 2.2：创建配置中心类
**优先级**：🟡 中  
**预计耗时**：2-3 小时  
**状态**：⏳ 待开始

**问题描述**：配置分散，阈值硬编码

**完成标准**：
- [ ] 创建 `EvolverConfig` 类
- [ ] 集中管理所有阈值：
  - 安全限制（max_files, max_lines）
  - 检测阈值（repair_loop, stagnation）
  - 缓存配置
- [ ] 支持环境变量覆盖
- [ ] 支持配置文件 `.evolver.json`

**相关文件**：
- 新建 `src/EvolverConfig.php`
- `src/SignalExtractor.php`
- `src/SolidifyEngine.php`

---

### 任务 2.3：引入事件总线
**优先级**：🟡 中  
**预计耗时**：3-4 小时  
**状态**：⏳ 待开始

**问题描述**：组件间耦合度高

**完成标准**：
- [ ] 创建 `EventBus` 类
- [ ] 定义核心事件类型：
  - `EvolutionStarted`
  - `EvolutionCompleted`
  - `GeneSelected`
  - `CapsuleCreated`
  - `ErrorOccurred`
- [ ] 重构现有组件使用事件通信
- [ ] 测试通过

**相关文件**：
- 新建 `src/EventBus.php`
- `src/McpServer.php`
- `src/SolidifyEngine.php`

---

### 任务 2.4：提取 SuppressedSignalSet 类
**优先级**：🟡 中  
**预计耗时**：1 小时  
**状态**：⏳ 待开始

**问题描述**：SignalExtractor 中使用匿名类

**完成标准**：
- [ ] 创建 `SuppressedSignalSet` 类
- [ ] 迁移匿名类逻辑
- [ ] 添加完整类型声明
- [ ] 测试通过

**相关文件**：
- 新建 `src/SuppressedSignalSet.php`
- `src/SignalExtractor.php`

---

## 🟢 阶段三：质量提升（后续执行）

### 任务 3.1：添加内存缓存层
**优先级**：🟢 中  
**预计耗时**：3-4 小时  
**状态**：⏳ 待开始

**问题描述**：频繁的数据库查询和 JSON 解码

**完成标准**：
- [ ] 创建 `Cache` 类（支持 TTL）
- [ ] 在 GepAssetStore 中添加缓存
- [ ] 智能缓存失效策略
- [ ] 缓存命中率统计

**相关文件**：
- 新建 `src/Cache.php`
- `src/GepAssetStore.php`

---

### 任务 3.2：完善代码文档
**优先级**：🟢 中  
**预计耗时**：4-6 小时  
**状态**：⏳ 待开始

**问题描述**：许多公共方法缺少 PHPDoc

**完成标准**：
- [ ] 为所有公共方法添加 PHPDoc
- [ ] 添加参数类型和返回类型说明
- [ ] 添加异常说明
- [ ] 生成 API 文档

**相关文件**：
- 所有 `src/` 下的 PHP 文件

---

### 任务 3.3：添加 Ops 模块测试
**优先级**：🟢 中  
**预计耗时**：4-6 小时  
**状态**：⏳ 待开始

**问题描述**：Ops 模块（14个文件）测试严重不足

**完成标准**：
- [ ] 为每个 Ops 类创建测试
- [ ] 覆盖率 > 70%
- [ ] 边界条件测试
- [ ] 错误路径测试

**相关文件**：
- `tests/Ops/` 目录下新建测试文件
- `src/Ops/*.php`

---

### 任务 3.4：重构 McpServer 类
**优先级**：🟢 中  
**预计耗时**：4-5 小时  
**状态**：⏳ 待开始

**问题描述**：类超过 1000 行，职责过多

**完成标准**：
- [ ] 提取工具处理逻辑到 `ToolHandler`
- [ ] 按功能分组工具方法
- [ ] 提取资源处理到 `ResourceHandler`
- [ ] 保持向后兼容
- [ ] 测试通过

**相关文件**：
- `src/McpServer.php`
- 新建 `src/ToolHandler.php`
- 新建 `src/ResourceHandler.php`

---

## ⚪ 阶段四：低优先级（按需执行）

### 任务 4.1：创建 CHANGELOG.md
**优先级**：⚪ 低  
**预计耗时**：30 分钟  
**状态**：⏳ 待开始

**完成标准**：
- [ ] 创建 CHANGELOG.md
- [ ] 记录历史版本变更
- [ ] 遵循 Keep a Changelog 格式

---

### 任务 4.2：创建 CONFIG.md
**优先级**：⚪ 低  
**预计耗时**：1 小时  
**状态**：⏳ 待开始

**完成标准**：
- [ ] 整理所有环境变量
- [ ] 整理所有配置选项
- [ ] 提供配置示例

---

### 任务 4.3：添加性能基准测试
**优先级**：⚪ 低  
**预计耗时**：2-3 小时  
**状态**：⏳ 待开始

**完成标准**：
- [ ] 创建 `BenchmarkTool` 完善版本
- [ ] 测试大规模基因/胶囊查询
- [ ] 测试大文本处理
- [ ] 生成性能报告

---

### 任务 4.4：命令白名单配置化
**优先级**：⚪ 低  
**预计耗时**：1-2 小时  
**状态**：⏳ 待开始

**完成标准**：
- [ ] 支持配置文件扩展白名单
- [ ] 验证配置安全性
- [ ] 文档说明

---

## 📊 执行跟踪

### 当前进行中的任务

| 任务 | 负责人 | 开始时间 | 预计完成 | 进度 |
|------|--------|----------|----------|------|
| 任务 2.2：创建配置中心类 | AI Assistant | 2026-03-03 | 2026-03-03 | 0% |

### 已完成任务

| 任务 | 完成时间 | 备注 |
|------|----------|------|
| 任务 1.1：统一 Schema 版本 | 2026-03-03 | 已统一为 1.6.0 |
| 任务 1.2：修复 SQL 注入风险 | 2026-03-03 | 添加了 SQL 标识符验证 |
| 任务 1.3：完善输入验证层 | 2026-03-03 | 新建 InputValidator 类 |
| 任务 2.2：创建配置中心类 | 2026-03-03 | 新建 EvolverConfig 类 |

---

## 📝 执行日志

### 2026-03-03
- 创建《改进意见.md》
- 创建《TODO.md》
- ✅ 任务 1.1：统一 Schema 版本
  - 更新 PromptBuilder.php 中的版本号
  - 修复 GepAssetStore.php 中的默认版本
- ✅ 任务 1.2：修复 SQL 注入风险
  - 添加 validateSqlIdentifier() 方法
  - 添加 validateSqlType() 方法
  - 修复 Database.php 中的动态 SQL
  - 修复 SolidifyEngine.php 和 GepAssetStore.php 中的变量名 bug
- ✅ 任务 1.3：完善输入验证层
  - 新建 InputValidator.php
  - 为所有 12 个 MCP 工具添加验证方法
  - 集成到 McpServer.php
  - 添加路径遍历防护
- ✅ 任务 2.2：创建配置中心类
  - 新建 EvolverConfig.php
  - 集中管理所有配置常量
  - 支持环境变量和配置文件
  - 添加功能标志支持

---

*最后更新：2026-03-03*

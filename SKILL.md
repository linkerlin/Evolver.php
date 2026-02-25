# Evolver.php Skill

## 概述

Evolver.php 是一个自进化引擎，赋予 AI Agent 自我诊断、自我修复和自我优化的能力。当检测到代码错误、性能瓶颈或功能需求时，自动触发进化流程。

## 触发条件

### 自动触发场景

1. **代码错误修复**
   - PHP 语法错误、运行时异常
   - 未定义变量、类型错误
   - 数据库连接失败、API 超时

2. **性能优化**
   - 响应时间超过阈值
   - 内存使用过高
   - 数据库查询缓慢

3. **功能扩展**
   - 用户请求新功能
   - 需要添加新的 API 端点
   - 需要集成第三方服务

4. **安全加固**
   - 检测到安全漏洞
   - 需要输入验证
   - 需要添加权限检查

## MCP Tools 调用指南

### 1. 信号提取 (evolver_extract_signals)

**何时调用**: 收到错误日志或异常信息时

**参数**:
```json
{
  "logContent": "错误日志内容",
  "context": "可选的额外上下文"
}
```

**示例**:
```
用户报告: "PHP Fatal error: Uncaught Error: Call to undefined function processData()"

→ 调用 evolver_extract_signals
→ 参数: {"logContent": "PHP Fatal error: Uncaught Error: Call to undefined function processData() in /app/src/Processor.php:42"}
```

### 2. 运行进化 (evolver_run)

**何时调用**: 需要生成修复/优化方案时

**策略选择**:
- `repair-only`: 紧急修复错误，不添加新功能
- `balanced`: 平衡修复和优化
- `innovate`: 添加新功能
- `harden`: 安全加固

**参数**:
```json
{
  "context": "问题描述和上下文",
  "strategy": "repair-only|balanced|innovate|harden",
  "driftEnabled": false
}
```

**示例**:
```
→ 调用 evolver_run
→ 参数: {
  "context": "修复 Processor.php 第42行的未定义函数 processData 错误",
  "strategy": "repair-only"
}
```

### 3. 固化结果 (evolver_solidify)

**何时调用**: 成功应用修复后

**参数**:
```json
{
  "intent": "repair|optimize|innovate",
  "summary": "一句话描述修改内容",
  "signals": ["信号列表"],
  "blastRadius": {"files": 1, "lines": 10},
  "modifiedFiles": ["修改的文件路径"]
}
```

**示例**:
```
→ 调用 evolver_solidify
→ 参数: {
  "intent": "repair",
  "summary": "Added missing processData() function to Processor class",
  "signals": ["log_error", "undefined_function"],
  "blastRadius": {"files": 1, "lines": 15},
  "modifiedFiles": ["src/Processor.php"]
}
```

## 完整工作流程

### 场景 1: 修复 PHP 错误

```
1. 用户报告错误
   ↓
2. 调用 evolver_extract_signals 提取信号
   ↓
3. 调用 evolver_run 生成修复方案
   ↓
4. 向用户展示修复建议
   ↓
5. 用户确认后应用修复
   ↓
6. 调用 evolver_solidify 固化结果
```

### 场景 2: 添加新功能

```
1. 用户请求新功能
   ↓
2. 调用 evolver_run (strategy: innovate)
   ↓
3. 获取实现方案
   ↓
4. 实现代码
   ↓
5. 调用 evolver_solidify (intent: innovate)
```

### 场景 3: 性能优化

```
1. 检测到性能问题
   ↓
2. 调用 evolver_run (strategy: harden)
   ↓
3. 获取优化建议
   ↓
4. 应用优化
   ↓
5. 调用 evolver_solidify (intent: optimize)
```

## 辅助 Tools

### 查看状态

- `evolver_stats`: 获取数据库统计信息
- `evolver_safety_status`: 查看安全模式和保护配置
- `evolver_list_genes`: 查看可用的进化策略
- `evolver_list_capsules`: 查看成功案例

### 管理操作

- `evolver_cleanup`: 清理旧日志和临时文件
- `evolver_upsert_gene`: 添加自定义策略
- `evolver_delete_gene`: 删除策略

## 安全注意事项

### 自动检查清单

在应用任何修改前，确认:

1. **blastRadius** 在可接受范围内
   - files ≤ 60
   - lines ≤ 20000

2. **不修改受保护文件**
   - src/McpServer.php
   - src/Database.php
   - src/GepAssetStore.php
   - evolver.php

3. **安全模式检查**
   - `never`: 禁止修改，仅诊断
   - `review`: 需要人工确认
   - `always`: 允许自动修改

### 禁止的操作

- 不执行 shell 命令 (`;`, `&&`, `||`, `|` 等)
- 不修改 vendor 目录
- 不删除 .git 目录

## 最佳实践

### 1. 信号命名

使用描述性信号名称:
- ✅ `api_timeout`, `db_connection_error`
- ❌ `error1`, `problem`

### 2. Summary 规范

- 使用现在时
- 具体说明修改内容
- 示例: "Fixed null pointer by adding null check in UserController"

### 3. Blast Radius 记录

准确记录影响范围:
```json
{
  "blastRadius": {
    "files": 2,
    "lines": 45
  },
  "modifiedFiles": ["src/Controller.php", "src/Service.php"]
}
```

### 4. 策略选择

| 场景 | 策略 | 说明 |
|------|------|------|
| 生产环境紧急修复 | repair-only | 最小变更，快速恢复 |
| 日常维护 | balanced | 修复+优化 |
| 新功能开发 | innovate | 允许较大变更 |
| 安全审计后 | harden | 专注安全和稳定性 |

## 故障排除

### 常见问题

**Q: evolver_run 返回空结果？**
A: 检查 context 是否包含足够信息，尝试添加更多错误日志。

**Q: solidify 失败？**
A: 检查 blastRadius 是否超出限制，或是否尝试修改受保护文件。

**Q: 找不到合适的 Gene？**
A: 调用 evolver_list_genes 查看可用策略，或创建新的 Gene。

### 调试步骤

1. 调用 evolver_stats 检查系统状态
2. 调用 evolver_safety_status 确认安全模式
3. 检查数据库健康: 读取 gep://health 资源
4. 查看最近的进化事件: evolver_list_events

## 集成示例

### 与 CI/CD 集成

```yaml
# GitHub Actions 示例
- name: Self-heal
  run: |
    # 运行测试，如果失败则尝试自修复
    if ! phpunit; then
      # 提取错误信号
      php evolver.php --run-extract < test_output.log
      # 运行进化
      php evolver.php --run-evolve --strategy=repair-only
    fi
```

### 与监控集成

```php
// 当错误率超过阈值时触发
if ($errorRate > 0.05) {
    // 自动调用 evolver_extract_signals
    // 自动调用 evolver_run
}
```

## 资源

- **GitHub**: https://github.com/linkerlin/Evolver.php
- **GEP Protocol**: https://zhichai.net/topic/177168577
- **MCP Resources**:
  - `gep://genes` - 可用策略
  - `gep://capsules` - 成功案例
  - `gep://stats` - 统计数据
  - `gep://health` - 数据库健康
  - `gep://safety` - 安全状态

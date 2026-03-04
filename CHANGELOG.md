# Changelog

All notable changes to Evolver.php will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [2.0.0] - 2026-03-04

### 🎉 Major Release - Feature Complete

Evolver.php has evolved from a basic port to a comprehensive, production-ready implementation that surpasses the original Node.js evolver in functionality, test coverage, and architectural maturity.

### Added

#### Core Systems
- **SessionLogReader** (566 lines) - Real-time session log analysis with multi-session support, scope isolation, and smart deduplication
- **SignalExtractor Enhanced** (844 lines) - Advanced signal extraction with repair loop detection, circuit breaker logic, and multi-source fusion
- **AssetCallLog** (232 lines) - Complete asset usage tracking with SQLite persistence and intelligent recommendations
- **QuestionGenerator** (212 lines) - Proactive question generation based on context analysis
- **TaskReceiver** (507 lines) - Hub task fetching, claiming, and management
- **PromptBuilder Enhanced** (732 lines) - Rich prompt assembly with Hub integration, health reports, and mood awareness
- **MemoryGraph System** (784 lines) - Complete memory graph operations with advice generation and hypothesis tracking

#### Network & Protocol
- **GepA2AProtocol** (526 lines) - Full GEP-A2A protocol implementation with message envelopes and validation
- **HubSearch** (715 lines) - Hub asset search with scoring and ranking
- **EvoMapClient** (959 lines) - EvoMap Hub client with node registration and heartbeat
- **DeviceId** (381 lines) - Device fingerprinting and persistent identification

#### Ops Module (14 Components, 3,152 lines)
- **LifecycleManager** - Server lifecycle management
- **DiskCleaner** - Automated disk cleanup and archiving
- **HealthCheck** - Comprehensive health monitoring
- **SkillsMonitor** - Skill availability and health monitoring
- **Innovation** - Innovation opportunity detection
- **Commentary** - Evolution commentary and explanation
- **SignalDeduplicator** - Signal deduplication and suppression
- **SecurityAuditLogger** *(New)* - Security event auditing and logging
- **StructuredLogger** *(New)* - JSON Lines structured logging
- **BenchmarkTool** *(New)* - Performance benchmarking utilities
- **DaemonManager** *(New)* - Daemon process management
- **GitSelfRepair** *(New)* - Git repository self-repair
- **OpsManager** *(New)* - Unified ops command interface
- **Trigger** - Event triggering system

#### Testing Infrastructure
- **50 modular test files** - Comprehensive test coverage (12,145 lines)
- Component-specific tests for all major classes
- Enhanced test suites for SignalExtractor and PromptBuilder
- Complete Ops module test coverage

#### CLI Scripts
- `evolver-a2a-export.php` - A2A asset export
- `evolver-a2a-ingest.php` - A2A asset ingestion
- `evolver-report.php` - Report generation
- `evolver-validate.php` - GEP validation

#### Configuration
- **EvolverConfig** (409 lines) - Centralized configuration management
- **StrategyConfig** (551 lines) - Strategy parameter configuration
- **InputValidator** (383 lines) - Comprehensive input validation

### Architecture

- **MCP stdio server** - Modern Model Context Protocol implementation
- **SQLite WAL + mmap** - Reliable, high-performance storage
- **PSR-4 autoloading** - Standard PHP autoloading
- **Strict typing** - `declare(strict_types=1)` throughout

### Security

- Source protection mechanism (SourceProtector)
- SafetyController with three modes (never/review/always)
- Command whitelist validation
- SQL injection prevention
- Path traversal protection

### Performance

- SQLite WAL mode for concurrent access
- Memory-mapped I/O optimization
- Signal deduplication to prevent loops
- Smart caching strategies

### Stats

- Core code: 18,618 lines (vs original 8,381 lines)
- Test code: 12,145 lines (vs original 5,800 lines)
- Test files: 50 (vs original 11)
- Ops components: 14 (vs original 9)

---

## [1.1.0] - 2026-03-02

### Added
- GEP 1.6.0 protocol compliance
- Input validation layer
- Schema version consistency fixes
- Enhanced safety controls

### Fixed
- SQL injection risks in dynamic queries
- Schema version inconsistencies
- Input validation gaps

---

## [1.0.0] - 2026-02-25

### Added
- Initial PHP port of evolver
- Core MCP server implementation
- Basic GEP protocol support
- SQLite storage foundation
- 5 default genes
- Basic test coverage

---

## Comparison with Original Evolver (Node.js)

| Dimension | Node.js evolver | Evolver.php 2.0.0 | Status |
|-----------|-----------------|-------------------|--------|
| Core Lines | 8,381 | 18,618 | +122% |
| Test Lines | 5,800 | 12,145 | +109% |
| Test Files | 11 | 50 | +355% |
| Ops Components | 9 | 14 | +56% |
| Architecture | OpenClaw Skill | MCP Server | Modern |
| Storage | Filesystem | SQLite WAL | Robust |

---

*This release marks Evolver.php as the definitive implementation of the Capability Evolver concept, ready for production use and further ecosystem development.*

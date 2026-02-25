# Evolver.php

🧬 Capability Evolver 的PHP实现版本，支持MCP服务，部署简单，数据私有化。

A pure PHP 8.3+ 1:1 port of [EvoMap/evolver](https://github.com/EvoMap/evolver) as a stdio MCP server with local SQLite storage (WAL + mmap).

---

## Features

- **MCP stdio server** — JSON-RPC 2.0 over stdin/stdout, zero platform dependency
- **GEP Protocol** — standardized evolution with 5 mandatory objects (Mutation → PersonalityState → EvolutionEvent → Gene → Capsule)
- **Signal-driven** — extracts signals from logs/context, suppresses duplicates, detects repair loops
- **Gene/Capsule dual-track** — Genes = reusable strategy templates, Capsules = successful result snapshots
- **SQLite storage** — WAL mode + mmap for performance, fully local/private
- **Built-in genes** — 5 default genes seeded on first run (repair, optimize, innovate, SQLite, security)
- **Safety model** — blast radius limits (60 files/20000 lines), validation command whitelist, forbidden path protection

## Requirements

- PHP 8.3+
- `sqlite3` PHP extension (enabled by default)
- `pdo_sqlite` PHP extension (enabled by default)

## Installation

```bash
git clone https://github.com/linkerlin/Evolver.php.git
cd Evolver.php
composer install
php evolver.php --validate
```

## MCP Configuration

Add to your MCP client config (e.g. Claude Desktop `claude_desktop_config.json`):

```json
{
  "mcpServers": {
    "evolver": {
      "command": "php",
      "args": ["/path/to/Evolver.php/evolver.php"],
      "env": {
        "EVOLVER_DB_PATH": "/path/to/your/evolver.db"
      }
    }
  }
}
```

> **Note**: If `EVOLVER_DB_PATH` is not specified, the default is `~/.evolver/evolver.db`.

## Available MCP Tools

| Tool | Description |
|------|-------------|
| `evolver_run` | Run an evolution cycle: extract signals, select Gene/Capsule, generate GEP prompt |
| `evolver_solidify` | Solidify result: validate, record EvolutionEvent, update Gene, store Capsule |
| `evolver_extract_signals` | Extract evolution signals from log content |
| `evolver_list_genes` | List available Genes (filterable by category) |
| `evolver_list_capsules` | List available Capsules |
| `evolver_list_events` | List recent evolution events |
| `evolver_upsert_gene` | Create or update a Gene in the store |
| `evolver_stats` | Get store statistics |

## Usage Examples

### Run an evolution cycle

```json
{
  "name": "evolver_run",
  "arguments": {
    "context": "[ERROR] TypeError in module: null pointer dereference",
    "strategy": "balanced"
  }
}
```

### Solidify after applying changes

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

### Extract signals from logs

```json
{
  "name": "evolver_extract_signals",
  "arguments": {
    "logContent": "2024-01-01 [ERROR] Connection timeout after 30s"
  }
}
```

## Strategy Presets

| Strategy | Description |
|----------|-------------|
| `balanced` | Default — handle errors and opportunities proportionally |
| `innovate` | Maximize new features and capability expansion |
| `harden` | Focus on stability, security, and robustness |
| `repair-only` | Emergency mode — only fix errors, no innovation |

## GEP Protocol Output

When `evolver_run` generates a prompt, the LLM must output exactly 5 JSON objects (raw, no markdown):

1. **Mutation** — the change trigger with risk level and rationale
2. **PersonalityState** — evolution mood (rigor, creativity, verbosity, risk_tolerance, obedience)
3. **EvolutionEvent** — auditable record with parent chain, signals, blast radius
4. **Gene** — reusable strategy template (create new or update existing)
5. **Capsule** — success snapshot for future reuse

## Architecture

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
└─────────────────────────────────────────┘
```

## Running Tests

```bash
composer test
```

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `EVOLVER_DB_PATH` | `~/.evolver/evolver.db` | Path to SQLite database file |

## Security Model

- **Validation command whitelist**: Only `php`, `composer`, `phpunit`, `phpcs`, `phpstan` commands allowed
- **No shell operators**: `;`, `&&`, `||`, `|`, `>`, `<`, `` ` ``, `$()` rejected
- **Blast radius limits**: Hard limits of 60 files and 20,000 lines per evolution
- **Gene constraint enforcement**: Each gene specifies its own `max_files` and `forbidden_paths`

## License

MIT


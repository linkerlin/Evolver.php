# AGENTS.md - Evolver.php Codebase Guide for AI Agents

This document provides essential information for AI agents working in the Evolver.php codebase.

## Project Overview

Evolver.php is a PHP 8.3+ implementation of the Capability Evolver engine, exposed as an MCP (Model Context Protocol) stdio server with local SQLite storage. It enables capability evolution through a signal-driven GEP protocol with Genes (reusable strategy templates) and Capsules (successful result snapshots).

**Key Characteristics**:
- Pure PHP port of EvoMap/evolver
- JSON-RPC 2.0 over stdin/stdout
- SQLite database with WAL mode and mmap optimization
- Built-in safety model with blast radius limits and validation command whitelist
- Default Genes seeded from `data/default_genes.json`

## Essential Commands

### Development
- `composer install` – Install dependencies
- `composer test` – Run PHPUnit tests with testdox output
- `php evolver.php --validate` – Validate installation and database health
- `php evolver.php --db /path/to.db` – Use custom database path
- `php evolver.php` – Start MCP stdio server (default)

### Testing
- Tests are located in `tests/`
- Use in-memory SQLite database (`:memory:`) for isolated testing
- Test suite includes database schema, GepAssetStore, SignalExtractor, GeneSelector, PromptBuilder, SolidifyEngine, and safety features
- Run with `composer test` or directly `phpunit --testdox`

### Code Quality
- No static analysis or linting tools configured in composer.json
- Code follows PSR-4 autoloading and strict typing

## Code Organization

### Directory Structure
```
Evolver.php/
├── src/                 # Core source code (PSR-4 namespace Evolver\)
│   ├── Ops/            # Operational utilities (DiskCleaner, LifecycleManager, SignalDeduplicator)
│   └── *.php           # Main classes
├── tests/              # PHPUnit tests (namespace Evolver\Tests\)
├── data/               # Default genes JSON file
├── vendor/             # Composer dependencies (ignored)
├── .rlm-gepa/          # RLM-GEPA agent memory and context storage (not part of core)
├── evolver.php         # Main entry point (MCP stdio server)
├── composer.json       # Project configuration and autoloading
├── phpunit.xml         # PHPUnit configuration
└── README.md           # Project documentation
```

### Namespace and Autoloading
- **Primary namespace**: `Evolver\`
- **Test namespace**: `Evolver\Tests\`
- **PSR-4 autoloading** mapped in composer.json:
  - `Evolver\\` → `src/`
  - `Evolver\\Tests\\` → `tests/`

### Class Conventions
- All PHP files start with `declare(strict_types=1);`
- Use `final` class modifiers where appropriate
- Private methods for internal logic, public methods for API
- Type hints for all parameters and return values
- Docblocks for public methods and complex logic
- Constants for configuration values (e.g., `SCHEMA_VERSION`)

## Naming and Style Patterns

- **Class names**: `PascalCase` (e.g., `Database`, `SignalExtractor`)
- **Method names**: `camelCase` (e.g., `ensureDirectoryExists`, `fetchAll`)
- **Variable names**: `camelCase` (e.g., `$geneSelector`, `$migrationLog`)
- **Constants**: `SCREAMING_SNAKE_CASE` (e.g., `SCHEMA_VERSION`)
- **Private properties**: prefixed with `$` and `camelCase` (e.g., `private \SQLite3 $db;`)

## Testing Approach

- Extends `PHPUnit\Framework\TestCase`
- `setUp()` method initializes common dependencies (Database, GepAssetStore, etc.)
- Use in-memory SQLite database (`:memory:`) for isolation
- Test methods named `test*` with `void` return type
- Assertions: `$this->assert*` (e.g., `assertContains`, `assertNotEmpty`)
- No mocking framework observed; rely on real dependencies

## Important Gotchas

### Schema Version Inconsistency
- `ContentHash::SCHEMA_VERSION = '1.6.0'`
- `PromptBuilder::SCHEMA_VERSION = '1.5.0'`
- Be aware of potential version mismatch when modifying schema-related code.

### Safety Limits (Hardcoded)
- Blast radius hard limits: **60 files**, **20,000 lines** per evolution
- Validation command whitelist: `['php', 'composer', 'phpunit', 'phpcs', 'phpstan']`
- Forbidden shell operators: `;`, `&&`, `||`, `|`, `>`, `<`, `` ` ``, `$()`
- Gene-specific constraints: each Gene defines its own `max_files` and `forbidden_paths`

### Self-Modification Safety Modes
Controlled by environment variable `EVOLVE_ALLOW_SELF_MODIFY`:
- `never`: Disable self-modification (diagnostics only)
- `review`: Require human confirmation for modifications (recommended)
- `always`: Full automation (use with caution)

### Database Configuration
- Default database path: `~/.evolver/evolver.db` (overridden by `EVOLVER_DB_PATH`)
- SQLite WAL mode and mmap optimization enabled
- Automatic schema migration and health checks
- In-memory database (`:memory:`) used for tests

### MCP Server Protocol
- The MCP server expects JSON-RPC 2.0 messages over stdin/stdout
- Tools are defined in `src/McpServer.php` (e.g., `evolver_run`, `evolver_solidify`)
- When `evolver_run` generates a GEP prompt, the LLM must output exactly 5 JSON objects in order:
  1. `Mutation`
  2. `PersonalityState`
  3. `EvolutionEvent`
  4. `Gene`
  5. `Capsule`
- Missing any object results in protocol failure.

### RLM-GEPA Agent Integration
- The `.rlm-gepa/` directory stores memory, context, and output for the RLM-GEPA recursive language model agent.
- This is separate from the core Evolver engine and used for agent-specific experiments.

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `EVOLVER_DB_PATH` | `~/.evolver/evolver.db` | Path to SQLite database file |
| `EVOLVE_ALLOW_SELF_MODIFY` | `always` | Safety mode: `never`, `review`, or `always` |
| `A2A_HUB_URL` | - | EvoMap Hub URL for sync (optional) |
| `A2A_NODE_SECRET` | - | Node secret for authentication (optional) |

## Security Model

- **Command validation**: Only whitelisted commands allowed during evolution
- **Shell operator blocking**: Prevents injection of shell operators
- **Blast radius limits**: Hard limits on files and lines changed per evolution
- **Source protection**: Core engine files protected from self-modification
- **Gene constraints**: Each gene enforces its own limits and forbidden paths

## Common Tasks for Agents

### Adding a New Gene
1. Add entry to `data/default_genes.json` (or use `evolver_upsert_gene` tool)
2. Ensure `signals_match` patterns correspond to detected signals
3. Define `max_files` and `forbidden_paths` constraints

### Modifying Database Schema
1. Update `Database::SCHEMA_VERSION` constant
2. Add migration logic in `Database::runMigrations()`
3. Update `ContentHash::SCHEMA_VERSION` if needed
4. Run tests to verify migration works

### Creating a New MCP Tool
1. Add tool definition in `McpServer::__construct()` `$this->tools` array
2. Implement corresponding method (e.g., `evolver_new_tool`)
3. Add validation and safety checks
4. Update documentation in README.md and AGENTS.md

### Running Tests After Changes
Always run `composer test` to ensure no regressions. If tests fail:
- Check database schema consistency
- Verify environment variables (in-memory database used)
- Ensure no hardcoded paths affect test isolation

## References

- [README.md](./README.md) – Detailed project documentation, MCP configuration, and examples
- [composer.json](./composer.json) – Dependencies and scripts
- [phpunit.xml](./phpunit.xml) – Test configuration
- [data/default_genes.json](./data/default_genes.json) – Default gene definitions
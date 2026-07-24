# Changelog

## v1.16.1 — Tenant isolation fix + stubs traits

### Fixed
- **MySQL/Multi-tenant**: `createTable()` ne détruit plus les données à chaque appel (tenant-aware via `$createdTableName`).
- **SqliteEngine**: `table()` inclut désormais le préfixe tenant (manquait vs FileEngine/MySqlEngine).
- **Cache** : les clés de cache incluent le tenant ID → pas de fuite entre tenants pendant les recherches.
- **SqliteEngine**: `createTable()` / `dropTable()` créent la meta table avant d'y écrire (nécessaire pour les nouveaux tenants).

### Refactored
- **3 traits partagés** : `NoopVacuum`, `NullPragma`, `StubQueryVocab` — remplacent les stubs dupliqués dans FileEngine et MySqlEngine.
- **MySqlEngine** : méthodes `getPragma()`/`queryVocab()` supprimées (fournies par les traits).
- **MySqlEngine** : méthodes manquantes `getEngineVersion()`, `getDatabasePath()`, `getDatabaseSize()`, `isFts5Available()` ajoutées (complétude interface).
- **FileEngine** : constantes `VERSION`, `SEARCH_OVERFETCH_MARGIN`, `VOCAB_WORDS_FILE`, `CONFIG_FILE`.

### Tests
- **`tenant_isolation_prefixes_tables`** — cross-engine (File + SQLite + MySQL) : vérifie qu'un document n'est pas visible par un autre tenant.
- **`search_with_only_operators_returns_empty`** — `AND OR NOT` ne doit pas lever d'exception.
- **540 tests** (était 537), 1209 assertions.

---

## v1.16.0 — FileEngine + Trigram Index + Field Boosting BM25

### New: FileEngine (`ILLUMI_SEARCH_DRIVER=file`)

Zero-dependency flat-file search engine — no PHP extensions required.

- **Chunk-based storage** — documents stored in serialized PHP files (100 rows per chunk)
- **Trigram inverted index** — O(1) lookup via fixed-size binary index (810 KB, 37³ = 50 653 entries)
- **BM25 field‑weighted scoring** — Robertson-Sparck Jones IDF (k1=1.2, b=0.75), each weight column scored independently
- **Score normalization 0–100** — consistent ranking across all queries and model classes
- **Search result caching** — file-based, ×500 speedup on warm searches (< 1ms)
- **Concurrent chunk processing** — `pcntl_fork` (CLI) with sequential fallback (web)
- **Crash recovery** — sentinel file with PID, auto‑repair on stale sentinel
- **Zero extension requirements** — works on any PHP 8.2+ host

### New: Shared infrastructure

- **`SearchCache`** — file-based result cache, now available to all engines (was FileEngine‑only)
- **`HasScoring`** trait — `normalizeScore()` for BM25 0–100 normalization on all engines
- **`HasDebugCollector`** trait — DebugBar integration for all engines (was SQLite‑only)
- **`VocabService`** — unified trigram + Levenshtein suggest (shared by FileEngine and MySQL)
- **`ConcurrentProcessor`** — `pcntl_fork` with sequential fallback (FileEngine)
- **`SmartDatasetProvider`** — seed.json analysis, intelligent query generation, ranking assertions for tests
- **`TestDataFactory`** — reusable test data helpers (makeDoc, rankingDataset, booleanTestDocs)
- **`ChunkStorage`, `StatsService`, `ScoreService`, `MatchService`** — extracted from FileEngine for clean separation

### Enhanced: Benchmark (`illumi-search:benchmark`)

- **New quality metrics** — Recall@5, F1@5, NDCG@5, MAP@5, Precision@1, MRR, Avg first relevant position
- **New performance metrics** — Latency p50/p95/p99 (ms), Peak RAM (MB)
- **Controlled dataset** — injected perfect‑match documents for MRR > 0
- **`--repetitions=N`** — run N times, shows mean ± σ
- **`--seed=N`** — deterministic random seed for reproducible benchmarks
- **`--cache=cold|warm`** — control result cache state
- **Weight‑3 column soundness** — verify that a weight‑3 column returns higher scores than weight‑1
- **Wildcard soundness** — `prog*` must find `programming`
- **FileEngine** now benchmarked alongside SQLite and MySQL in `--all-engines` mode

### Enhanced: All engines

- **`Engine` interface** — `setRebuilding(bool)` now mandatory (34 methods)
- **BM25 field boosting** — each weight column scored independently, weighted average (replaces docText repetition)
- **Score normalization** — all engines normalize BM25 scores to 0–100 when stats available
- **Search caching** — SQLite and MySQL now cache results (×100 on repeated queries)
- **StopwordFilter** — O(n) via `array_diff_key` (was O(n²) via `in_array`)
- **Tests** — 479 total (was 386), 1090 assertions (was 831)
- **`CrossEngineConsistencyTest`** — same queries, same expected results across all 3 engines
- **Code style** — 100% PSR‑12 (pint), PHPStan level 6 with baseline

### Fixed

- **MySQL special chars crash** — `!@#$%^&*()` no longer throws a MySQL syntax error (early return on empty query)
- **FileEngine OOM during rebuild** — streaming chunk processing (was loading all documents at once)
- **AND/OR precedence with NOT** — corrected evaluation logic
- **Missing DebugBar on MySQL and FileEngine** — now all engines report to DebugBar
- **`emptyResultsRate` benchmark metric** — filters queries whose terms don't exist in the corpus
- **Inconsistent `getEngineStatus()` keys** — standardized across all engines

### Changed

- `config/illumi-search.php` — added `processing.table_prefix`, `workers`
- `Contracts/Engine.php` — `setRebuilding()` added to interface
- `.env` structure — MySQL credentials uncommented by default in demo project

---

## v1.15.0

- **Multi-engine architecture.** New `MySqlEngine` for MySQL 8.0+ FULLTEXT alongside existing `SqliteEngine`.
- **Per-column weight columns.** MySQL stores weight levels in separate FULLTEXT columns (`text_w1`, `text_w2`, `text_w3`) instead of text repetition. BM25 ranking uses `MATCH(col) * weight` for precise scoring.
- **Atomic swap rebuild.** `rebuildVocabFromScratch()` and `rebuildIndexFromScratch()` use `RENAME TABLE` atomic swap on MySQL.
- **`getEngineStatus()`** — new Engine interface method returning engine-specific metadata.
- **Config restructured.** Shared settings under `processing.*`, engine-specific under `engines.sqlite.*` / `engines.mysql.*`.
- **`max_weight`** — configurable per-column weight clamping (default: 3).
- **Script-aware spellcheck.** `scriptsOf()` detects 30+ Unicode scripts with configurable mismatch penalty.
- **Benchmark command.** `php artisan illumi-search:benchmark` with `--all-engines`, `--mode=raw`.
- **`ConfigQueue`** — persistent bounded lists via engine config storage.
- **`ServiceProvider::extend()`** — extensible engine registry for third-party engines.
- **`getSupportedOperators()`, `supportsPhraseSearch()`, `supportsPrefixWildcard()`** — Engine interface additions.
- **Multi-tenant MySQL.** Table prefixing (`tenant_id_search_index`) for data isolation.
- **OOM fix.** Fallback processor without `ext-intl`, configurable `--memory=2G`.
- **386 tests**, 831 assertions across two engines.
- **Breaking changes:**
  - Config paths moved: `illumi-search.fts5.*` → `illumi-search.engines.sqlite.fts5.*`, etc.
  - `buildSearchText()` returns array keyed by weight column.
  - `search_index` table schema changed: weight columns instead of single `search_text`.

---

## v1.14.0

- **OperatorRegistry** — centralized operator tokenization, masking, and unmasking for stopword-filter-safe operator handling.
- **Count pagination** — `COUNT(*) OVER ()` window function in FTS5 queries for accurate total counts.

---

## v1.13.0

- Engine interface cleaned up (33 methods).
- N+1 authorization fixed.
- Soft delete support.
- afterCommit for queue jobs.
- PHPStan baseline ~98% reduction.
- Laravel Debugbar integration.
- 256+ tests, 524+ assertions.

---

## v1.11.0

- REST API, CLI search, spellcheck.

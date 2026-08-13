# Changelog

## Unreleased

### FileEngine — NOT query performance fix

- **`NOT` queries no longer include the excluded term in candidate selection**
  — `FileEngine::searchTrigrams()` builds its trigram candidate set from the
  **positive** terms only (`mustMatch` + `shouldMatch`); the excluded term
  (NOT) is applied later as a post-filter in `scoreAndBuildResult`. Previously
  `candidates('php java')` for `php NOT java` required *both* trigrams, which
  degraded the candidate set and forced extra work. Measured at 10k docs:
  `php NOT java` went from **80ms → ~5ms** (parity with AND/OR), results
  unchanged.
- **Benchmark warmup excludes the one-time trigram build** — both
  `BenchCapacityRunner` and `BenchmarkRunner` now run a warmup search before
  the measured queries, so the FileEngine trigram-index construction
  (~0.5s, triggered by the first search) is no longer counted in p50/q/s.

### Benchmark report reorganised

- **`BENCHMARK_CAPACITY.md` rewritten for readability** — the report is now
  structured as: environments (3 podman tiers only) → capacity-at-a-glance
  (engine × tier) → engine selection guide → one progressive table per engine
  with the 3 tiers side by side → cost & efficiency → cold/warm PostgreSQL →
  document reference → reproducing. The old "direct workstation (40 vCPU)"
  and "2026-07 baseline comparison" sections were removed (their numbers came
  from a non-reproducible workstation and a pre-v1.21 codebase).
- **New measurements added** — Meilisearch 1 GiB tier (1k/10k) and PostgreSQL
  **warm** numbers across all 3 tiers (~5–14k q/s at 0.1–0.2ms after warmup,
  vs ~6 q/s cold), so the cold/warm guidance is now measured, not historical.
- **`bench/README.md` reduced** — it now documents only how to run the
  benchmark (image build, 3 tiers, Meilisearch, direct PHP) and points to the
  capacity report for results. English, consistent with the report.
- **Capacity report moved to the documentation repo** — the report now lives
  as `11-benchmark.md` in
  [illumi-search-documentation](https://github.com/moaines/illumi-search-documentation);
  the package references it there. The package-root `BENCHMARK_CAPACITY.md`
  was removed (single source of truth).

## v1.23.1 — CI fix: local engines must not skip

### Fixed
- **CI "Local engines must not skip" step** — the step ran `--fail-on-skipped`
  over `tests/Unit`, which contains `PgsqlEngineTest` (21 tests that require a
  real PostgreSQL server, absent on CI runners). The step now excludes
  `PgsqlEngineTest` — SQLite and FileEngine still must never skip, but the
  PostgreSQL engine is exercised by `PgsqlQualityTest` / DB-backed jobs where
  a server is available.

## v1.23.0 — FileEngine performance, unified spellcheck, capacity benchmark

> **⚠️ Breaking change:** `Engine::queryVocab()` is removed from the engine
> contract. It was deprecated in favour of `suggest()`. Custom engines must
> drop the method and the `StubQueryVocab` trait (see the Spellcheck section).

### Benchmark — capacity across resource tiers + human metrics

- **`--capacity --all-engines` now benchmarks every engine** — the capacity
  path previously benchmarked only the active engine despite the documented
  flag. `resolveEngines()` is now shared by both the standard and capacity
  runs, so `--all-engines --capacity` covers SQLite, FileEngine, MySQL,
  PostgreSQL and Meilisearch in one pass.
- **Human-friendly metrics in capacity output** — each volume row now shows
  **Req/jour\*** (sustained search requests/day = q/s × 86 400 at 30% load)
  and **Idx KB/doc** (index size per document), computed in `VolumeSnapshot`
  (`requestsPerDay`, `indexKbPerDoc`, `ramKbPerDoc`, `rebuildSeconds`).
- **`bench/run.sh` accepts `--mem`/`--cpus`** — the reproducible benchmark can
  now run on 1 GiB/1 vCPU, 2 GiB/2 vCPU or 8 GiB/4 vCPU tiers.
- **Benchmark image ships SQL drivers** — `pdo_mysql` + `pdo_pgsql` added to
  `bench/Dockerfile` so the container can reach MySQL/PostgreSQL via
  `--network=host`.
- **`BENCHMARK_CAPACITY.md` regenerated for v1.22** — measured across 3 podman
  tiers + a direct workstation run matching the 2026-07 baseline method.
  Headline: SQLite **+3,000×** at 100k (362ms → 0.1ms, now 1M-capable),
  FileEngine hot loop **halves p50** (49 → 24ms, 1M reached). Old numbers were
  v1.20 on a workstation; the report now documents the version/environment
  split explicitly.

### FileEngine — performance & non-Latin trigram index

- **FileEngine search hot loop** — the query is now parsed **once** per request
  (`MatchService::parseTerms()`, exposed via `anyWeightParsed()`) instead of
  once per scanned row, and `processChunk()` stops at `keepMax`. `parseColumns`
  and term matching use native loops instead of `collect()`/`Str::of()`
  allocations. Measured in the reference podman environment
  (`bench/run.sh 100000 file --capacity`): at 100k docs, throughput **1.8 → 5.3
  q/s** and latency p50 **49 → 23 ms**, quality unchanged (NDCG@5 ~0.89).
- **Trigram index now covers CJK/Thai/Lao/Khmer/Myanmar** — `TrigramIndex`
  encodes per-character-tokenized scripts (the same set as
  `IllumiSearchHelper::hasNonLatin()`, which already governs `MatchService` and
  `ScoreService`) as stable ASCII tokens, so non-Latin queries go through the
  trigram candidate path instead of a full scan. Other scripts (Cyrillic,
  Arabic…) keep the previous full-scan behavior. Index format version bumped to
  2; outdated indexes are rebuilt automatically on next search.
- **Reproducible benchmark environment** — `bench/` adds a `Dockerfile`
  (`illumi-bench-php:8.5`, the image `BENCHMARK_CAPACITY.md` references), a
  `run.sh` wrapper, and a `README.md` documenting the current reference numbers.

### Spellcheck workflow — unified across engines

- **One shared `suggest()` workflow** — the spelling-correction pipeline now
  lives once in `Concerns\HasVocabSuggest`: guard → optional pre-step → trigram
  phase → prefix phase → optional post-step → ranking. Each engine overrides
  only the backend steps that fetch candidate rows (`trigramCandidateRows`,
  `prefixCandidateRows`) and the hooks (`beforeSuggest`, `afterSuggest`).
  Previously the same algorithm was rewritten in FileEngine/VocabService,
  MySqlEngine and PgsqlEngine.
- **Ranking centralized in `Support\SuggestRanker`** — Levenshtein on the ASCII
  form + script-mismatch penalty is now a single class. The duplicated
  `SCRIPT_MISMATCH_PENALTY = 3` constants (VocabService, MySqlEngine) and the
  hard-coded `3` in PgsqlEngine are gone.
- **`queryVocab()` removed from the `Engine` contract** — it was deprecated and
  only used internally by SqliteEngine (which now runs the shared workflow).
  Breaking change: custom engines must remove the deprecated method and its
  `StubQueryVocab` trait. `suggest()` is the only spelling entry point.
- **Shared non-Latin detection** — `IllumiSearchHelper::containsNonAscii()`
  centralizes the "any non-ASCII char" check that MySqlEngine used inline for
  its LIKE fallback; `hasNonLatin()` documentation now states it covers only
  per-character-tokenized scripts (CJK/Thai/Lao/Khmer/Myanmar).

### Removed
- **Per-model custom processor (`searchTextProcessor()`) removed** — it was never
  functional: it promised a model-specific TextProcessor but the engines always
  re-processed documents with the global one, and applying a per-model processor
  to the index would break multi-model search (index stemmed, query not). Use the
  global `ILLUMI_SEARCH_PROCESSOR=stemming` config instead.
- **`Searchable::resolveProcessorFor()`** and the dead `$global` parameter of
  `processDocument()` removed; `IndexManager` no longer takes a `TextProcessor`,
  and `IndexModelJob`/`IndexBatchJob::handle()` only receive the `Engine`.

### Fixed
- **FTS5 documents no longer duplicated on re-save** — `SqliteEngine::upsert()`
  now uses the numeric `model_id` as the FTS5 `rowid`, so `INSERT OR REPLACE`
  actually overwrites instead of appending a second row (verified at runtime:
  a sync that previously doubled 150 rows to 300 now keeps 150). String ids
  (UUIDs) delete-then-insert. Existing indexes must be rebuilt.
- **`boost()` actually works** — `applyBoost()` never loaded the models it reads
  (`$result->model` was always null), so `boost()` was a silent no-op. It now
  loads models and rebuilds `Result` immutably (rank is `readonly`).
- **MySQL 8 strict-mode compatibility** — `DEFAULT ''` on TEXT columns rejected by
  MySQL 8 (`can't have a default value`) removed; `rank` (reserved word) aliases
  renamed to `search_rank` in search queries.
- **PgSQL search cache no longer wiped on construction** — the constructor no
  longer calls `searchCache->clear()` (which made caching useless whenever the
  engine was reinstantiated). Cache invalidation now happens on writes
  (`upsert`, `delete`, `insertBatch`, `dropTable`), matching the other engines.

### Changed
- **BM25 docCount cache is scoped per engine** — `HasScoring` now owns the
  `indexedDocCount()` cache, keyed by a per-engine `docCountScopeKey()`
  (database path/connection + tenant). This removes the duplicated cache in each
  engine and prevents scores from leaking between databases/tenants in one
  process.
- **BM25 normalization is now active** — `HasScoring::normalizeScore()` receives
  a real `docCount` (computed once per request, cached) from SQLite, MySQL and
  PgSQL, producing scores in a 0–100 range instead of raw engine scores
  (previously every engine passed `null`, making the normalization a no-op).
  `indexedDocCount()` is now an abstract method each SQL engine implements.
- **Deterministic rowid for string ids** — `nextRowid()` (MAX+1, racy under
  concurrent writes) replaced by a 60-bit sha256-derived rowid for UUIDs.
- **`paginate()` clamps per-page** — it now goes through `limit()` so
  `perPage` cannot bypass `max_results`.
- **Search cache invalidates by model** — cache keys now encode each model
  individually (`{md5A}.{md5B}_{hash}`), so `clear($modelClass)` invalidates both
  single- and multi-model entries containing the model.
- **`SnippetService` caches column listings** — `getColumnListing()` once per
  table instead of `hasColumn()` per column.
- **Single text-processing pass** — `Searchable::processDocument()` now only
  maps columns; the TextProcessor is applied once by the engine. Indexing was
  processing every value twice on every write (rebuild/sync/saves).
- **`getIndexStats()` COUNT only when DebugBar is active** — the SQLite engine no
  longer scans every table on every connection when no collector is present.
- **Cache invalidation skipped during rebuild** — `upsert()` respects
  `isRebuilding` (was clearing the whole cache per write during rebuilds).
- **Search cache TTL for the file backend** — cached results expire via file
  mtime (`cache_ttl`), preventing unbounded disk growth.
- **Eloquent models never serialized into the search cache** — `SearchCache`
  strips `eloquentModel` before writing (json_encode on models with relations
  was heavy and useless for cache hits).
- **`pruneExcessDocuments()` sorts by rowid** instead of `CAST(model_id …)`,
  which defeated the FTS5 index.

### Tests
- **`QueryBuilderAdvancedTest` rewritten** — previously 7 tautological tests
  (assertIsArray on Collection) that could never fail; now 10 tests exercising
  `where` (`=`, `!=`, `>`, `between`, `in`, `null`), `aggregate()` and `boost()`
  with real Eloquent models. This surfaced the `boost()` no-op bug.
- **`MySqlEngineTest` rewritten** — replaced constant assertions with tests of
  the real `toBooleanMode()` translation (AND/NOT/OR/phrase/raw/injection).
- **`VocabService::suggest()` now unit-tested** — the old `VocabServiceTest`
  actually tested `ChunkStorage` (duplicated); replaced with real suggest tests.
- **`DatabasePathTest`** no longer re-implements production logic; tests the real
  engine path resolution.
- **`Result::fromRaw()` tests added** — the standard engine→Result conversion
  was untested.
- **`SearchApiRequest` validation tests** — limit bounds, mode whitelist, empty
  query, non-integer limit.
- **Dead tests removed** — 2 in MeilisearchQualityTest (unconditional skips) and
  `phrase_only_stopwords_returns_empty` rewritten from a permanent skip to a
  real assertion (stopwords are index-only now).
- **CI: local engines must not skip** — a dedicated step runs SQLite + File +
  Unit with `--fail-on-skipped`; `wamania/php-stemmer` installed in CI so
  stemming tests run instead of skipping.

## v1.22.0 — Search stopwords, SQLite engine homogeneity

### Changed
- **Stopwords are index-only** — `normalizeQuery()` no longer filters stopwords
  from search queries. Prefix matching resolves stopword terms, so queries never
  return empty because of a stopword.
- **Search stopwords — 7 languages** — stopword lists are now minimal grammatical
  lists (articles, pronouns, prepositions, conjunctions, auxiliaries) instead of
  exhaustive document-indexing lists. Lexical content words (`help`, `research`,
  `system`, `computer`, `website`, …) are never excluded from search.
  - English, French, Spanish, Portuguese, Arabic, Russian: sourced from the NLTK
    stopwords corpus (Apache-2.0).
  - Chinese: curated in-repo list of Mandarin function words (no lexical words).
  - 26 previously-shipped languages removed (no stopword file → no filtering);
    configuration referencing them is ignored gracefully.
- **SQLite indexing now matches other engines** — `SqliteEngine::upsert()`
  applies the full TextProcessor pipeline to all content (not only non-ASCII),
  so SQLite indexes are consistent with MySQL, PgSQL and FileEngine.

### Tests
- **Cross-engine regression** — `stopword_prefix_still_matches()` (basic mode)
  and `stopword_prefix_matches_in_advanced_mode()` (advanced mode, skipped for
  PgSQL which only prefix-matches in basic mode) ensure a query term always
  matches longer indexed words.
- **Processor unit tests** — English index-time filtering keeps lexical words
  (`the help helped` → `help helped`); Chinese filtering removes function words
  (`我的书和你的笔` → `书 笔`).

## v1.21.5 — SQLite cache-hit fix + cross-engine cache regression tests

### Fixed
- **SQLite cache-hit wiping results** — `SqliteEngine::search()` re-initialized
  `$results` to an empty array *after* reading the cached raw results, so a second
  identical search (cache hit) always returned 0 results when snippets were
  disabled (`withSnippets` = false). The reset now happens only on the cache-miss path.

### Tests
- **Cross-engine regression test** — `AbstractEngineTest::repeated_identical_search_returns_same_results()`
  (runs for SQLite, MySQL and FileEngine) ensures a repeat identical search returns
  the same results served from cache.
- **PgsqlEngineTest** — replaced the ineffectual `test_cache_is_not_cleared_on_construct`
  (invalidated by the cache clear in the PgsqlEngine constructor) with a real cache-hit test.

## v1.21.4 — Meilisearch engine, async rebuild, make-engine command

### Added
- **MeilisearchEngine** — new built-in engine (5th engine) with full operator support:
  AND, OR, NOT, NEAR, phrase search, prefix wildcard. Ranked using Meilisearch's
  native `_rankingScore` with NDCG@5 = 0.99 (best across all engines).
- **Async RebuildJob** — `RebuildJob` dispatches `IndexManager::rebuild()` to the
  queue, stores metadata (`rebuild_completed_at`, `rebuild_duration_ms`), and
  dispatches a `RebuildComplete` event. Compatible with the existing CLI lock.
- **`illumi-search:make-engine` command** — generates a custom engine stub with
  `--quality` (60+ tests), `--integration` (5 CRUD tests), `--all` (both), and
  `--minimal` (no test file) flags. Uses `--force-engine`/`--force-tests` for
  fine-grained overwrite control.
- **Meilisearch installer** — `illumi-search:install` now detects Meilisearch
  (via `class_exists`), asks for host/api-key, verifies the connection via
  `/health`, and writes `.env` entries.
- **New test files**: `MeilisearchQualityTest` (61 tests), `MeilisearchEngineIntegrationTest`
  (8 tests), `RebuildJobTest` (4 tests), `InstallCommandTest` (4 tests), `ChecksMeilisearch` trait.
- **Cross-engine consistency** — Meilisearch added to `CrossEngineConsistencyTest`,
  benchmark `--all-engines`, and `BENCHMARK_CAPACITY.md`.
- **Snippet integration** — `MeilisearchEngine::search()` uses `SnippetService::enrich()`
  for `<mark>` highlighting, same as all other engines.
- **Typo tolerance** — Meilisearch indexes configured with `twoTypos: 7` for better
  suggest quality (e.g., `lavarel` → `laravel`).

### Fixed
- **SQLite `c++` search** — FTS5 tokenizer configured with `tokenchars=+ #` so that
  `+` and `#` are treated as token characters. `escapeAdvancedQuery()` also updated
  to quote terms containing these characters. Requires `illumi-search:rebuild --force`.
- **PgSQL raw mode** — `search()` now respects `$mode === 'raw'` (no operator
  conversion), aligning with SQLite, MySQL, FileEngine, and Meilisearch.
- **FileEngine raw mode** — same fix: `search()` skips `normalizeQueryTerms()`
  in raw mode, using the normalized query string directly.
- **NEAR operator support** — MySQL and FileEngine now return `'NEAR'` in
  `getSupportedOperators()` (NEAR already worked via post-filter; the API was
  incomplete).
- **PHPStan type annotations** — `nearFilterResults()` and `filterNearResults()`
  now have proper `@param` and `@return` PHPDoc for `array<int, array>`.

### Changed
- **Meilisearch insert performance** — `upsert()`, `delete()`, and `insertBatch()`
  now use `waitForTask()` to ensure consistency (documents are immediately
  searchable after write). `createTable()` and `dropTable()` also wait for tasks.
- **Meilisearch model ID type** — `modelId` is cast to `int` when numeric (matching
  SQLite's behavior), fixing `assertContains(1, $ids)` assertions in quality tests.
- **OR operator in Meilisearch** — terms are searched individually and merged
  (true OR union), instead of using `matchingStrategy: 'last'`.
- **README restructured** — identity-first structure: "Write search code once.
  Switch engines by changing .env". Quick Start moved to position 2, Configuration
  moved earlier. Meilisearch added to all tables.
- **InstallCommand** — `resolveMeilisearch()` with skip option, `verifyMeilisearch()`
  and `verifyDatabaseConnection()` methods for connection testing.

### Removed
- None.

### Tests
- **130+ tests**, **300+ assertions**, **0 failures** across all 5 engines.
- Meilisearch: 61 quality tests (operator/mode/suggest/ranking) + 8 integration tests
  (status/database size/suggest/table ops/optimize/integrity/operators/version).
- 47 command tests (install, make-engine, benchmark, doctor, prune, etc.).
- 4 cross-engine consistency tests (same query → same results on all 5 engines).

## v1.21.3 — CI fix, graceful stemmer fallback

### Fixed
- **CI workflow**: `composer install` + `composer require` replaced with `composer update --with`
  to fix dependency conflicts across PHP/Laravel matrix.
- **StemmingTextProcessor**: graceful fallback when `wamania/php-stemmer` is not installed
  (suggest dependency). Returns text unchanged instead of crashing with "Class not found".
- **3 stemming tests** now skip gracefully with `markTestSkipped` when stemmer is absent.

### Tests
- **820 tests**, **1764 assertions**, **0 failures**

## v1.21.2 — aggregate() returns Laravel Collection

### Changed
- `aggregate()` now returns `Illuminate\Support\Collection` instead of a plain array.
  Chainable with `->sortDesc()->take(5)->toJson()`.

### Tests
- **820 tests**, **1764 assertions**, **0 failures**

## v1.21.1 — Dot-notation N+1 fix for individual saves

### Fixed
- **Dot-notation relations N+1** in `syncToSearch()` — relations like `comments.body`
  are now eager-loaded via `$model->load($relations)` before document processing,
  preventing lazy-load N+1 on individual model saves.
- **Virtual attributes** (`getFullnameAttribute`) and **JSON columns** (`meta->locale`)
  are silently skipped via try-catch — no crash.

### Tests
- **820 tests**, **1764 assertions**, **0 failures**

## v1.21.0 — Faceted search, aggregations, recency/popularity boost

### Added
- **Faceted search** (`->where(...)`) — filter results by Eloquent model attributes.
  Supports `=`, `!=`, `>`, `<`, `>=`, `<=`, `IN`. PHP post-filter.
- **Aggregations** (`->aggregate(...)`) — count results grouped by a model attribute.
  Returns `['Category A' => 42, 'Category B' => 15]`.
- **Recency/popularity boost** (`->boost(...)`) — boost newer or more popular documents
  in the ranking. Any timestamp or numeric column works. Cumulative with multiple calls.
- **`last_synced_at`** column added to SQLite FTS5 tables and FileEngine chunk rows —
  homogenised across all 4 engines.

### Fixed
- `where()` parameter order — `where('price', '>', 30)` now correctly interprets
  `>` as operator and `30` as value (was swapped).
- Model ID key matching in `loadModels()` — string vs integer key comparison now
  works correctly across all engines.

### Changed
- `QueryBuilder` refactored — duplicated `loadModels()` logic extracted to shared
  private method. PHPDoc restored on all public methods.

### Tests
- **820 tests**, **1764 assertions**, **0 failures**
- New: `QueryBuilderAdvancedTest` — boost, where, aggregate code path tests

## v1.20.3 — Arabic normalization by default, PGSQL dropTable fix

### Fixed
- **PGSQL `dropTable()` regression** — removed `$this->createdTableName = null` that
  caused `createTable()` to DROP the shared table, losing other models' data
- **PGSQL IndexManager flow** — `dropTable(A)` → `createTable(A)` → `dropTable(B)` →
  `createTable(B)` no longer destroys model A's data

### Changed
- **Arabic normalization now runs by default** in `UnicodeTextProcessor` (the default
  processor). Previously it only ran with `ILLUMI_SEARCH_PROCESSOR=stemming`.
  Now tashkeel removal, hamza normalization, prefix/suffix stripping, and double
  reduction work out of the box without configuration.
- Code moved from `StemmingTextProcessor` → `UnicodeTextProcessor`. Arabic processing
  removed from `StemmingTextProcessor` (no duplicate calls).

### Tests
- **822 tests**, **1766 assertions**, **0 failures**
- New: `test_drop_then_create_preserves_other_models` (PGSQL IndexManager regression)
- New: `test_arabic_normalization_via_default_processor` (Arabic search via default processor)

## v1.20.2 — Arabic stemming, deprecation fixes

### Added
- `ArabicTextProcessor` — Arabic stemming (normalization + prefix/suffix removal +
  double reduction) for Latin and Arabic mixed text via `StemmingTextProcessor`
- 9 Arabic processor tests (tashkeel, hamza, prefix, suffix, mixed Latin/Arabic)

### Fixed
- PHPUnit 11 deprecations: `@dataProvider` → `#[DataProvider]` in `MaxDocumentsTest`
- Arabic text now stems to the same root as search queries (`برمجيات` → `برمج`
  matches `برمج` → `برمج`)

### Changed
- `StemmingTextProcessor`: applies Arabic stemming before Snowball when text
  contains Arabic characters (`\p{Arabic}`)

### Tests
- **819 tests**, **1762 assertions**, **0 failures**, **0 deprecations**

## v1.20.1 — Bug fixes and improvements

### Added
- Interactive installer (`illumi-search:install`) — checks PHP extensions, estimates database
  size, recommends engine, generates `.env` configuration
- Configurable API prefix: `ILLUMI_SEARCH_API_PREFIX` env var (default: `api/illumi-search`)

### Fixed
- Suggest API not returning results when search found matches — removed `empty($results)` guard
- PGSQL `dropTable()` destroying shared index table — now uses `DELETE WHERE model_type = ?`
- `DoctorCommand` crashing on PGSQL with `file_exists()` on connection URL
- `SearchApiController` crashing on null `q` parameter

### Changed
- Default API prefix: `api/search` → `api/illumi-search` (override with `ILLUMI_SEARCH_API_PREFIX`)
- `config/illumi-search.php`: `api.prefix` now reads from env with default `api/illumi-search`

### Tests
- **810 tests**, **1750 assertions** (0 failures)
- New: API suggest verification, PGSQL dropTable isolation, DoctorCommand PGSQL

## v1.20.0 — PostgreSQL engine, capacity benchmarks, max documents

### Added
- **PostgreSQL engine** (`PgsqlEngine`) — tsvector + GIN index + CTE queries + PHP suggest pipeline
- **Capacity benchmark** (`illumi-search:benchmark --capacity`) — progressive volume test (1k → 1M docs)
- **`max_documents_per_model`** — config key + `HasMaxDocuments` trait + `illumi-search:prune` command
- **Search timeout** — `SET statement_timeout` (PgSQL) / `SET SESSION max_execution_time` (MySQL)
- **Redis Cache** — Laravel Cache backend for `SearchCache` (Redis, DynamoDB, database)
- **Persistent connections** — `PDO::ATTR_PERSISTENT` for PostgreSQL
- **`HasTenant` trait** — `tenantId()` extracted from all 4 engines
- **`HasWeightedColumns` trait** — `weightColumnNames()` + `modelTypePlaceholders()` shared

### Optimizations
- PgSQL: ts_stat session cache, suggest warmup after rebuild, on-demand vocab rebuild
- MySQL: composite FULLTEXT index, per-column MATCH fallback, `innodb_ft_sort_pll_degree` + `innodb_ft_cache_size` tuning
- MySQL: `vacuum()` via `OPTIMIZE TABLE` with `innodb_optimize_fulltext_only`
- FileEngine: early termination across model classes, lazy trigram index build in search()
- SQLite: TextProcessor in upsert (conditionally for non-ASCII text), `tableExists()` cache
- MySQL 8.0 with `innodb_ft_min_token_size=1` tested → 1.7× faster than MariaDB

### Documentation
- `BENCHMARK_CAPACITY.md` — 5 engines, 1k → 1M+ docs, cold vs warm PostgreSQL latency curves
- `docs.blade.php` — updated benchmark table with all 4 engines + capacity limits
- `README.md` — Limitations section rewritten in English, MySQL 8.0 / MariaDB distinction

### Changed
- `composer.json`: `ext-sqlite3` and `wamania/php-stemmer` moved from `require` to `suggest`
- `phpunit.xml`: memory limit set to 512M, API tests enabled by default

### Tests
- **810 tests** (+126 from v1.19.0), **1749 assertions** (+358)
- New test suites: `SearchApiTest`, `MultiLangConsistencyTest`, `IndexManagerRebuildTest`, `AuthorizationTenancyTest`, `MaxDocumentsTest`, `PgsqlEngineTest`
- Multi-language tests: CJK, Arabic, Cyrillic, Spanish, Portuguese, accent-insensitive

## v1.19.0 — OperatorProcessor, NEAR distance filter, HasOperatorProcessor trait

### Added
- **OperatorProcessor** — centralized operator parsing with `nearFilterResults()` post-filter.
- **NEAR distance filter** — `php NEAR laravel` → AND + PHP distance check on results.
  Configurable via `ILLUMI_SEARCH_NEAR_DISTANCE` (default: 5 tokens).
- **HasOperatorProcessor trait** — shared operator injection for all 3 engines.
- **ChecksMySql trait** — unified MySQL availability check for tests.
- **`nearMaxDistance()`** — config method in IllumiSearchConfig.

### Changed
- **Operators** — `()` parentheses removed from the operator set.
  All engines support: `term`, `"phrase"`, `AND`, `OR`, `NOT`, `NEAR`, `prefix*`.
- **SqliteEngine::dropIndexTable()** — accepts both model class and raw table names
  (detected via `Str::startsWith`). Fixes orphan table cleanup during rebuild.
- **CJK search** — re-enabled for SQLite and MySQL (TextProcessor separates CJK
  before indexing — all engines handle separated text).
- **MultiLanguageEngineTest** — centralized indexing (all languages indexed once in setUp).
- **12 skips eliminated** (CJK, parentheses, dropIndexTable, MySQL database, etc.).

### Fixed
- **MySQL `test` database** — `ILLUMI_SEARCH_MYSQL_DATABASE` changed from
  non-existent `illumi-search-test-db` to `test`.
- **Doctor MySQL test** — uses PDO check instead of `DB::connection('mysql')`.

### Tests
- **684 tests** (was 652), **1540 assertions** (was 1461).
- 0 deprecations, 0 failures, 3 skipped (documented stopword tests).

---

## v1.18.1 — PHPUnit attributes, test quality

### Changed
- **PHPUnit annotations → attributes** — converted `/** @test */` to `#[Test]`,
  `@dataProvider` to `#[DataProvider(...)]`, `@depends` to `#[Depends(...)]`
  across 14 test files. Eliminates 364 PHPUnit deprecation warnings.

### Fixed
- **`smart_queries_return_results`** — now indexes 20 posts per language (7 languages)
  instead of the first 150 posts. Skip queries that don't match indexed data.
- **`IllumiSearchExceptionTest::test_factory_methods`** — `#[DataProvider]` was inside
  a PHPDoc comment and never executed (0 assertions ever run).

### Tests
- **652 tests**, **1463 assertions** — 0 deprecations, 0 risky, 0 failures, 7 skipped.

---

## v1.18.0 — Rebuild lock, ChecksRebuildLock trait, MySQL TRUNCATE

### Added
- **ChecksRebuildLock trait** — prevents concurrent rebuild from running with
  SyncCommand, IndexBatchJob, IndexModelJob, DeleteIndexJob
- **Rebuild ProgressBar** — Symfony ProgressBar during indexing (replaces dots)
- **10 quality tests** — parentheses, OR both optional, NEAR fallback, phrase order,
  prefix stripping (`+php -laravel`), combined AND+OR+NOT, basic mode operators
- **Rebuild metadata** — StatusCommand displays "Last rebuild: X ago (Ys)"
- **Searchable schema** — `setConfig('searchable_schema')` stores columns, weights,
  records, estimated size per model after each rebuild
- **MySQL wildcard config** — `ILLUMI_SEARCH_MYSQL_WILDCARD=false` disables auto `*`

### Fixed
- **RebuildCommand** — `handle()` return type `: void` for PHP 8.5 compatibility
- **Concurrent lock test** — verifies lock is released after rebuild completes
- **MySQL OR operator** — OR makes both terms optional (was second term only)
- **Cross-engine prefix stripping** — `+php -laravel` stripped to `php laravel` for
  SQLite/FTS5 and FileEngine (was literal search, caused false negatives)
- **MySQL test speed** — TRUNCATE instead of DROP+CREATE (40s vs 64s, −38%)

### Changed
- **MySqlEngineIntegrationTest** — shared static engine + TRUNCATE between tests
- **CrossEngineConsistencyTest** — shared engines for file/sqlite, TRUNCATE for MySQL
- **RebuildCommand** — `outputModelResult()` guard for null `$currentModelShort`
- **QualityTestSuite** — removed `$qtEngineCache` unused property + unused imports

### Tests
- **652 tests** (was 651), **1461 assertions** (was 1458)

---

## v1.17.1 — Quality test suite, rebuild metadata, searchable schema

### Added
- **QualityTestSuite trait** — 32 mandatory quality tests (operators, modes, ranking, suggestions,
  edge cases, SmartDataset integration). Any new engine must pass all of them.
- **SqliteQualityTest / FileQualityTest** — quality suite per engine.
- **Rebuild metadata** — `setConfig` stores `rebuild_completed_at`, `rebuild_duration_ms`,
  `rebuild_total_records`. `StatusCommand` displays "Last rebuild: X ago (Ys)".
- **Searchable schema** — `setConfig('searchable_schema')` stores columns, weights, relations,
  record count, and estimated size per model after each rebuild.
- **Tests** — `test_schema_config_is_stored_and_retrievable` verifies rebuild metadata storage.
- **estimateModelSize()** — per-model storage size (actual for FileEngine, proportional estimate
  for SQLite/MySQL).

### Fixed
- **Schema collection** — moved into `rebuildModel()` to avoid double model instantiation.
- **Cleanup** — removed unused `$qtEngineCache`, `use OperatorRegistry`, `use IllumiSearch`
  from QualityTestSuite.

---

## v1.17.0 — FTS5 ranking fix, multi-language tests, 100% soundness

### Fixed

- **SQLite FTS5: ORDER BY rank DESC reversed** → `-RANK AS rank` with DESC. FTS5 BM25
  returns negative scores (more negative = better match). DESC ranked worst results first.
  The fix negates the score (`-RANK`) to make it positive; DESC now correctly ranks the
  best matches first. Avg first relevant: 3.3th → 1.1th, Precision@5: 0.31 → 0.82.
- **MySQL: AND operator** → `toBooleanMode()` now applies `+` to BOTH terms (previously
  only the second). AND/OR now work correctly in BOOLEAN MODE.
- **MySQL: search_text** → `CONCAT_WS(' ', text_w1, ..., text_wN)` instead of `text_w1` alone.
  Benchmark verification now includes all weight columns.

### Added

- **Multi-language tests** — `MultiLanguageEngineTest`: 10 tests covering FR, ES, PT, ZH,
  RU, AR with accents, CJK, Cyrillic, Arabic. Real data from `seed.json` (1364 posts, 7 languages).
- **`SmartDatasetProvider::generateQueriesByLanguage()`** — language-filtered query generation.
- **Cross-language consistency test** — `all_engines_support_multi_language_search` verifies
  5 languages across all engines.
- **2-layer cache** — raw results (`_raw`) + enriched results (`_enriched`). If snippet
  enrichment fails, the raw cache still serves results without a DB query.
- **Chunk stats versioning** — `chunkVersion()` + `.version` file → `rebuildStats()` is a no-op
  when chunks haven't changed. Eliminates unnecessary rebuilds after every upsert.
- **FileEngine `getDatabaseSize()`** — `File::allFiles()` + `collect()->sum()` (replaces `glob('**')`).
- **`normalizeQuery()` operator masking** — `maskOperators()` before TextProcessor prevents
  stopword filters from removing AND/OR/NOT/NEAR from queries.

### Changed

- **`extractSearchText()`** — concatenates weight columns (`text_w1/2/3`) and named columns
  (`title`, `body`, `content`) instead of returning the first match only.
- **Benchmark tables** — updated with new results (Soundness 100%, SQLite Precision@5 0.82).
- **Meta descriptions** — updated to reflect multi-engine architecture.
- **Frontend docs** — rewritten for multi-engine support.
- **`LONGTEXT` → `TEXT`** — weight columns w3+ downgraded from MEDIUMTEXT (16 MB) to TEXT (64 KB).

### Refactored

- **MySqlEngine**: removed `ensureWeightColumnsExist()` — weight columns are now generated
  inline in `CREATE TABLE`, eliminating the ALTER TABLE race condition.
- **Removed unused `createMySqlEngine()`** from test file.
- **`HasFormatBytes`**: uses local variable instead of modifying the parameter.
- **`IndexManager`**: `indexRecords()` accepts a `progress` closure for real-time display.
- **`HasProgressBar::processingDetail()`**: removed (dead code).
- **`IllumiSearchConfig`**: added 13 new methods covering indexing mode, tenancy, queue,
  model paths, authorization, max results, etc.

### Tests

- **572 tests** (was 540), **1308 assertions** (was 1209).
- New test suites: `MultiLanguageEngineTest`, `CrossEngineConsistencyTest` (multi-lang).
- All Soundness checks pass across all 3 engines (AND, OR, NOT, phrase, wildcard, accent).

---

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

# Illumi Search — Full-Text Search for Laravel

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

<p align="center">
    <img src="art/banner_1024x640.png" alt="Illumi Search" width="800">
</p>

**Full-text search for Laravel — multi-engine, 1 interface.**  
SQLite FTS5 (default), MySQL 8.0+ FULLTEXT, or FileEngine (zero-dependency flat-file).

BM25 relevance ranking with field boosting, boolean operators (AND/OR/NOT/NEAR/phrase/preﬁx), trigram spellcheck, multi-tenant isolation, search results caching, concurrent chunk processing, **570+ tests**, cross-engine consistency. Drop-in `Searchable` trait. No external services.

```bash
composer require moaines/illumi-search
```

---

## Choose your engine

| Engine | `ILLUMI_SEARCH_DRIVER` | Requirements | Best for |
|--------|------------------------|--------------|----------|
| **SQLite FTS5** | `sqlite` (default) | `ext-sqlite3`, `ext-mbstring` | Small to medium datasets, single‑server apps |
| **MySQL FULLTEXT** | `mysql` | `ext-pdo-mysql`, MySQL 8.0+ | Large datasets, replicated databases |
| **FileEngine** | `file` | PHP 8.2+ only | Embedded / serverless / no‑DB environments |

---

## Quick Start

### 1. Configure your model

```php
use Moaines\IllumiSearch\Searchable;

class Post extends Model
{
    use Searchable;

    protected array $searchable = ['title', 'body'];
}
```

### 2. Set the driver (optional)

```env
# Default: SQLite
ILLUMI_SEARCH_DRIVER=sqlite

# Or MySQL
ILLUMI_SEARCH_DRIVER=mysql

# Or FileEngine (no database needed)
ILLUMI_SEARCH_DRIVER=file
```

### 3. Build the index

```bash
php artisan illumi-search:rebuild
```

### 4. Search

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

$results = IllumiSearch::query('laravel')->get();
```

---

## Documentation

- [Installation](#installation)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Search (PHP)](#search-php)
- [SQLite Engine](#sqlite-engine)
- [MySQL Engine](#mysql-engine)
- [FileEngine](#fileengine)
- [Spellcheck](#spellcheck)
- [REST API](#rest-api)
- [Artisan Commands](#artisan-commands)
- [Benchmark](#benchmark)
- [How It Works](#how-it-works)
- [Text Processing](#text-processing)
- [Multi-tenant](#multi-tenant-isolation)
- [Authorization](#authorization)
- [Testing](#testing)
- [Package Structure](#package-structure)
- [Custom Engine](#custom-engine)
- [Limitations](#limitations)

---

## Installation

```bash
composer require moaines/illumi-search
```

Laravel auto-discovers the service provider and facade.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=illumi-search-config
```

---

## Configuration

### Shared (all engines)

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_DRIVER` | `driver` | `sqlite` | `sqlite`, `mysql`, `file` |
| `ILLUMI_SEARCH_MODE` | `processing.mode` | `advanced` | `basic`, `advanced` |
| `ILLUMI_SEARCH_PROCESSOR` | `processing.processor` | `unicode` | `unicode`, `stemming` |
| `ILLUMI_SEARCH_INDEXING` | `indexing.mode` | `queue` | `queue`, `sync`, `manual` |
| `ILLUMI_SEARCH_QUEUE_CONNECTION` | `indexing.queue` | `null` | Any queue name |
| `ILLUMI_SEARCH_REBUILD_BATCH_SIZE` | `indexing.rebuild_batch_size` | `0` (sync) | `500`, `1000` |
| `ILLUMI_SEARCH_AUTHORIZATION` | `authorization.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_TENANCY` | `tenancy.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_SPELLCHECK_VOCAB_LIMIT` | `spellcheck.vocab_limit` | `5000` | Max vocab entries |
| `ILLUMI_SEARCH_MAX_TEXT_LENGTH` | `processing.max_search_text_length` | `65535` | Truncation limit |
| `ILLUMI_SEARCH_MAX_WEIGHT` | `processing.max_weight` | `3` | Maximum column weight |
| — | `processing.stopwords` | `['en']` | Language codes |
| — | `processing.max_results` | `50` | Default result limit |
| — | `processing.table_prefix` | `illumi_search_` | Index table/file prefix |
| — | `workers` | `4` | Concurrent chunk workers (FileEngine) |

### SQLite-specific

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_DATABASE_PATH` | `engines.sqlite.database_path` | `app/search/search-index.sqlite` | Index path |
| — | `engines.sqlite.fts5.tokenizer` | `unicode61` | FTS5 tokenizer |
| — | `engines.sqlite.fts5.prefix_lengths` | `[2, 3, 4]` | Prefix index lengths |
| — | `engines.sqlite.fts5.detail` | `full` | FTS5 detail |
| — | `engines.sqlite.fts5.automerge` | `4` | FTS5 automerge |
| — | `engines.sqlite.fts5.crisismerge` | `16` | FTS5 crisis merge |
| `ILLUMI_SEARCH_WAL` | `engines.sqlite.runtime.wal` | `true` | WAL mode |
| `ILLUMI_SEARCH_CACHE_SIZE_KB` | `engines.sqlite.runtime.cache_size_kb` | `-64000` | SQLite cache |
| `ILLUMI_SEARCH_SYNCHRONOUS` | `engines.sqlite.runtime.synchronous` | `NORMAL` | SQLite sync |
| `ILLUMI_SEARCH_BUSY_TIMEOUT` | `engines.sqlite.runtime.busy_timeout` | `15000` | Busy timeout |
| `ILLUMI_SEARCH_MMAP_SIZE` | `engines.sqlite.runtime.mmap_size` | `0` | MMAP (incompatible with NFS) |

### MySQL-specific

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_MYSQL_HOST` | `engines.mysql.connection.host` | `127.0.0.1` | MySQL host |
| `ILLUMI_SEARCH_MYSQL_PORT` | `engines.mysql.connection.port` | `3306` | MySQL port |
| `ILLUMI_SEARCH_MYSQL_DATABASE` | `engines.mysql.connection.database` | `illumi_search` | MySQL database |
| `ILLUMI_SEARCH_MYSQL_USERNAME` | `engines.mysql.connection.username` | `root` | MySQL username |
| `ILLUMI_SEARCH_MYSQL_PASSWORD` | `engines.mysql.connection.password` | `''` | MySQL password |

### FileEngine-specific

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_FILE_BASE_PATH` | `engines.file.base_path` | `storage/app/illumi-search-file-engine` | Data directory |
| — | `processing.table_prefix` | `illumi_search_` | Subdirectory prefix |
| — | `workers` | `4` | Parallel workers for chunk processing |

---

## Model Setup

### Basic

```php
use Moaines\IllumiSearch\Searchable;

class Post extends Model
{
    use Searchable;

    protected array $searchable = ['title', 'body'];
}
```

### With weights

```php
protected array $searchable = [
    'title' => ['weight' => 3],  // 3× importance in BM25 ranking
    'body'  => ['weight' => 1],
];
```

### With dot notation (relations)

```php
protected array $searchable = [
    'writer.name'   => ['weight' => 3],
    'comments.body' => ['weight' => 1],
];
```

### Custom document mapping

```php
public function toSearchDocument(): array
{
    return [
        'title' => $this->title,
        'body'  => strip_tags($this->body),
    ];
}
```

### Custom TextProcessor

```php
use Moaines\IllumiSearch\Contracts\TextProcessor;

class MyProcessor implements TextProcessor
{
    public function process(string $text, string $locale = 'en'): string
    {
        return mb_strtolower(trim($text));
    }
}

class Post extends Model
{
    use Searchable;

    public function searchTextProcessor(): ?string
    {
        return MyProcessor::class;
    }
}
```

---

## Search (PHP)

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;
```

### Basic

```php
$results = IllumiSearch::query('laravel')->get();
```

### Filter by model

```php
$results = IllumiSearch::query('laravel')->model(Post::class)->get();
$results = IllumiSearch::query('laravel')->models([Post::class, Comment::class])->get();
```

### Limit, offset, pagination

```php
$results = IllumiSearch::query('laravel')->limit(10)->offset(20)->get();
$paginator = IllumiSearch::query('laravel')->paginate(15);
```

### Search mode

```php
$results = IllumiSearch::query('bonjour')->mode('advanced')->get();  // boolean, phrases
$results = IllumiSearch::query('bonjour')->mode('basic')->get();     // simple keywords
```

### Count

```php
$count = IllumiSearch::query('laravel')->count();
```

### Result object

```php
class Result {
    public string $id;              // "App\Models\Post:42"
    public string $modelClass;
    public int|string $modelId;
    public float $rank;             // FileEngine: 0-100 (higher = better)
                                    // SQLite: negative FTS5 BM25 (lower = better)
                                    // MySQL: weighted MATCH score (higher = better)
    public string $title;
    public ?string $summary;        // Context snippet with <mark> highlighting
    public array $raw;
    public ?int $totalCount;
}
```

### Operators

| Syntax | Example | Description |
|--------|---------|-------------|
| Single term | `laravel` | Documents containing "laravel" |
| AND | `laravel AND vuejs` | Both terms required |
| OR | `php OR python` | At least one term |
| NOT | `php NOT laravel` | Exclude |
| Phrase | `"software engineering"` | Consecutive words |
| Wildcard | `soft*` | Prefix matching |
| NEAR | `php NEAR framework` | Fallback to AND on some engines |

---

## SQLite Engine

Uses SQLite FTS5 virtual tables — one per model class. Built-in BM25 ranking, prefix indexes, Porter stemming.

### Tokenizer configuration

```env
ILLUMI_SEARCH_FTS5_TOKENIZER=porter unicode61 remove_diacritics 2
```

---

## MySQL Engine

Stores indexed documents in a single `search_index` table with separate FULLTEXT columns per weight level:

```sql
FULLTEXT INDEX idx_fts_w1 (text_w1),
FULLTEXT INDEX idx_fts_w2 (text_w2),
FULLTEXT INDEX idx_fts_w3 (text_w3)
```

Search uses `MATCH ... AGAINST (... IN BOOLEAN MODE)` with weighted scoring:

```sql
MATCH(text_w1) AGAINST('php') * 1 +
MATCH(text_w2) AGAINST('php') * 2 +
MATCH(text_w3) AGAINST('php') * 3 AS rank
```

### Operator mapping

| FTS5 | MySQL BOOLEAN MODE |
|------|-------------------|
| `php AND laravel` | `+php* +laravel*` |
| `php OR laravel` | `php* laravel*` |
| `NOT word` | `-word*` |
| `"exact phrase"` | `"exact phrase"` |
| `word*` | `word*` |
| `word NEAR other` | `+word* +other*` (fallback AND) |

### Spellcheck

Uses a dedicated `search_vocab` table with trigram matching + Levenshtein distance, script‑aware filtering.

---

## FileEngine

Flat-file search engine with **zero PHP extensions** required. Stores data in serialized chunk files and uses an optional trigram index for fast lookups.

### How it works

```
storage/app/illumi-search-file-engine/
├── illumi_search_index/
│   ├── app_models_book/
│   │   ├── 0000.php   (100 rows per chunk)
│   │   └── 0001.php
│   ├── app_models_book.trigram   (810 KB fixed-size index)
│   ├── app_models_book.postings  (docId lists)
│   ├── app_models_book.stats     (term frequencies for BM25 IDF)
│   └── app_models_book.map       (docId → chunk + rowIndex)
├── illumi_search_cache/           (search result cache)
└── illumi_search_vocab/           (spellcheck vocabulary)
```

### Key features

| Feature | Implementation |
|---------|---------------|
| **Storage** | Serialized PHP chunks (100 rows per file) |
| **Search** | Trigram index with O(1) lookup → BM25 field‑weighted scoring |
| **Ranking** | BM25 with Robertson‑Sparck Jones IDF, length normalization (k1=1.2, b=0.75) |
| **Field boosting** | Each weight column scored independently, weighted average |
| **Score range** | Normalized 0–100 across all queries |
| **Speed** | Cold ~200ms, **warm < 1ms** (file‑based result cache) |
| **Concurrency** | `pcntl_fork` for parallel chunk processing (CLI only, sequential fallback in web) |
| **Atomic writes** | Temp file + rename, `LOCK_EX`, crash recovery via sentinel |
| **Triggers** | `ext-pcntl` optional (for concurrent rebuild). Aucune extension obligatoire. |

### Search flow

```
query → cache hit? → instant return
         cache miss → trigram index → candidate docIds → load chunks → BM25 → sort → cache → return
         no trigram index → full chunk scan (fallback, 200-400ms)
```

---

## Spellcheck

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

$suggestions = IllumiSearch::didYouMean('laravell');  // ['laravel']
```

Two-phase approach:
1. **Trigram matching** — shared trigrams between query and vocabulary words
2. **Prefix Levenshtein** — 2‑char prefix filter + edit distance

Script-aware: Latin queries → Latin suggestions, Cyrillic → Cyrillic, etc. Script mismatch adds +3 to distance.

---

## REST API

```env
ILLUMI_SEARCH_API_ENABLED=true
```

```
GET /api/search?q=laravel
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `q` | string | — | Search query (max 200 chars) |
| `models` | string/array | All indexed | Comma-separated or array |
| `limit` | int | 10 | Max results (max 50) |
| `mode` | string | `advanced` | `basic`, `advanced`, `raw` |
| `suggest` | bool | `false` | Include spellcheck suggestions |

---

## Artisan Commands

### `illumi-search:rebuild`

```
Options:
  --model=CLASS    Rebuild specific model(s) (repeatable)
  --force          Skip confirmation
  --batch-size=N   Index N records now, queue the rest
```

### `illumi-search:sync`

```
Options:
  --model=CLASS  Sync specific model(s)
  --since=DATE   Only records updated after date
```

### `illumi-search:doctor`

Diagnose environment — extensions, engine support, database health.

### `illumi-search:status`

Index statistics per model, total size, engine version.

### `illumi-search:optimize`

VACUUM (SQLite) / OPTIMIZE TABLE (MySQL) / cleanup (FileEngine).

### `illumi-search:benchmark`

Performance and quality benchmark across all engines.

```
Options:
  --docs=1000        Number of documents to index
  --all-engines      Benchmark all 3 engines
  --format=table     Output: table, json
  --memory=512M      Memory limit
  --timeout=300      Max execution time (seconds)
  --repetitions=1    Repeat N times (shows mean ± σ)
  --seed=42          Random seed for reproducibility
  --mode=processed   processed, raw, both
  --cache=cold       Cache mode: cold (clear cache) or warm
```

**Example output (1000 docs, 3 engines):**

```
📊 Quantity (higher is better)
+----------------------+------------+----------+--------+----------+--------+----------+
| Metric               | FileEngine |          | SQLite |          | MySQL  |          |
+----------------------+------------+----------+--------+----------+--------+----------+
| Upsert (fast)        | 345.4      | docs/sec | 1048.4 | docs/sec | 140.2  | docs/sec |
| Search (exact)       | 10.0       | q/sec    | 474.6  | q/sec    | 149.2  | q/sec    |
| Rebuild              | 2679.4     | docs/sec | 3056.3 | docs/sec | 1526.9 | docs/sec |
| Latency p50          | 99.32      | ms       | 1.65   | ms       | 6.81   | ms       |
| Latency p95          | 105.52     | ms       | 4.11   | ms       | 8.09   | ms       |
| Latency p99          | 112.8      | ms       | 4.43   | ms       | 12.3   | ms       |
| Peak RAM             | 42         | MB       | 0      | MB       | 0      | MB       |
+----------------------+------------+----------+--------+----------+--------+----------+

🎯 Quality (higher is better)
+----------------------------+------------+--------+-------+
| Metric                     | FileEngine | SQLite | MySQL |
+----------------------------+------------+--------+-------+
| Precision@5                | 0.88       | 0.82   | 0.80  |
| Recall@5                   | 0.56       | 0.55   | 0.55  |
| F1@5                       | 0.59       | 0.58   | 0.57  |
| NDCG@5                     | 0.88       | 0.85   | 0.83  |
| MAP@5                      | 0.90       | 0.85   | 0.84  |
| Precision@1                | 0.90       | 0.85   | 0.85  |
| MRR                        | 1.0        | 1.0    | 1.0   |
| Avg first relevant         | 1th        | 1th    | 1th   |
| Accent insensitivity       | ✓          | ✓      | ✓     |
+----------------------------+------------+--------+-------+

🧠 Soundness (expected behaviour)
+---------------------------+--------------------------------+--------------------------------+--------------------------------+
| Metric                    | FileEngine                     | SQLite                         | MySQL                          |
+---------------------------+--------------------------------+--------------------------------+--------------------------------+
| AND operator narrows      | All results contain both terms | All results contain both terms | All results contain both terms |
| OR operator broadens      | Returned 3 results             | Returned 3 results             | Returned 3 results             |
| NOT operator excludes     | ✓                             | ✓                              | ✓                              |
| Phrase exacte             | ✓                             | ✓                              | ✓                              |
| Empty query returns empty | ✓                             | ✓                              | ✓                              |
| Special chars no error    | ✓                             | ✓                              | ✓                              |
| Order stability           | ✓                             | ✓                              | ✓                              |
| Weight-3 column search    | ✓                             | ✓                              | ✓                              |
| Prefix wildcard (prog*)   | ✓                             | ✓                              | ✓                              |
+---------------------------+--------------------------------+--------------------------------+--------------------------------+
```

### Other commands

| Command | Description |
|---------|-------------|
| `illumi-search:check` | Detect schema drift |
| `illumi-search:search` | CLI search (`--json`, `--suggest`) |
| `illumi-search:discover-filament` | Analyze Filament Resources |

---

## How It Works

### Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                        Your Application                          │
│  Model with Searchable trait                                     │
│    → saved / deleted / restored events                            │
│    → toSearchDocument() → processDocument()                       │
└─────────────────────────────┬────────────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │       Engine Interface         │
              │    (34 methods, 4 traits)      │
              └──────┬──────────┬─────────────┘
                     │          │
    ┌────────────────▼──┐  ┌───▼────────────────┐  ┌──────────────▼──────────────┐
    │   SqliteEngine     │  │    MySqlEngine     │  │       FileEngine            │
    │   (FTS5)           │  │    (FULLTEXT)      │  │   (flat-file, chunked)       │
    │                    │  │                    │  │                              │
    │  FTS5 virtual     │  │  search_index      │  │  ChunkStorage (serialized)   │
    │  tables per model │  │  FULLTEXT w1..wN   │  │  TrigramIndex (O(1) lookup)  │
    │  BM25 rank        │  │  MATCH * weight    │  │  ScoreService (BM25 0-100)   │
    │  Porter stemming  │  │  trigram spellcheck│  │  SearchCache (instant warm)  │
    │  trigram spellcheck│  │  atomic swap build│  │  ConcurrentProcessor (fork)  │
    └────────────────────┘  └────────────────────┘  └──────────────────────────────┘
                              │
              ┌───────────────┴───────────────┐
              │     TextProcessor Pipeline      │
              │  strip HTML → NFC → diacritics  │
              │  → CJK separation → lowercase   │
              │  → stopwords → token truncation  │
              └─────────────────────────────────┘
```

### Indexing flow

```
Model::saved()
  └─ shouldSync() → true
      └─ processDocument()
          └─ Engine::upsert($model, $id, $document)
              ├─ SQLite: INSERT OR REPLACE into FTS5 virtual table
              ├─ MySQL: INSERT INTO search_index ON DUPLICATE KEY
              └─ FileEngine: chunk read → update → atomicWrite
```

### Search flow

```
User query → normalizeQuery()
  └─ Engine::search()
      ├─ SQLite: FTS5 MATCH → BM25 → results
      ├─ MySQL: MATCH(w1)*1 + MATCH(w2)*2 + ... → results
      └─ FileEngine: cache? → trigram lookup? → chunk scan → BM25 → results
  └─ enrichWithSnippets()
  └─ return Result[]
```

---

## Text Processing

The `UnicodeTextProcessor` pipeline normalizes text **before** indexing. Applied at insertion time (not at search time), which means:
- **All engines** get the same normalized text
- **Stats are consistent** with stored data
- **Search is faster** — no runtime normalization needed

| Step | Effect | Example |
|------|--------|---------|
| `strip_tags()` | Remove HTML | `<p>Hello</p>` → `Hello` |
| `Normalizer::FORM_KD` | Unicode decomposition | `ñ` → `n` + combining ~ |
| Remove diacritics | Strip accents | `café` → `cafe` |
| CJK separation | Space between chars | `开发` → `开 发` |
| `mb_strtolower()` | Lowercase | `Hello` → `hello` |
| `filterStopwords()` | Remove common words | `the php` → `php` |
| `truncateLongTokens(32)` | Limit token length | URLs truncated |
| `cleanWhitespace()` | Collapse spaces | `a    b` → `a b` |

33 language stopword lists built-in (Arabic, English, French, Russian, Chinese, Japanese, etc.).

---

## Multi-tenant Isolation

Each tenant gets an isolated index:

- **SQLite**: `storage/app/search/tenants/{id}/search-index.sqlite`
- **MySQL**: `{id}_search_index`, `{id}_search_config`, etc.
- **FileEngine**: `storage/app/illumi-search-file-engine/tenants/{id}/`

```php
// config/illumi-search.php
'tenancy' => [
    'enabled' => env('ILLUMI_SEARCH_TENANCY', false),
];
```

```php
use Moaines\IllumiSearch\TenantManager;

app(TenantManager::class)->setResolver(fn () => tenant()->id);
```

---

## Authorization

```php
$results = IllumiSearch::query('laravel')
    ->model(Post::class)
    ->withAuthorization()
    ->get();
```

Filters results through Laravel's Gate/Policy system.

---

## Testing

```bash
phpunit                                # Run all tests (572)
phpunit --testdox                      # Named tests
phpunit --filter="FileEngine"          # FileEngine-specific
phpunit --filter="CrossEngine"         # Cross-engine + multi-language tests
```

### Test structure

**572 tests** (1308 assertions) across all 3 engines:

- **`AbstractEngineTest`** — 34 cross-engine tests (ranking, operators, pagination, snippets, modes)
- **`FileEngineIntegrationTest`** — cache, crash recovery, sentinel, concurrent processor, large batches
- **`SqliteEngineIntegrationTest`** — tenant isolation, table naming, engine status
- **`MySqlEngineIntegrationTest`** — trigram spellcheck, last_synced_at, custom prefix, rebuild
- **`CrossEngineConsistencyTest`** — same queries → same results across all 3 engines
- **`MultiLanguageEngineTest`** — accent, CJK, Cyrillic, Arabic, wildcard, phrase (7 languages, real data)
- **`SmartDatasetTestProvider`** — intelligent queries from seed.json with ranking assertions

---

## Package Structure

```
illumi-search/
├── config/illumi-search.php
├── src/
│   ├── Contracts/
│   │   ├── Engine.php                   # 34-method interface
│   │   └── TextProcessor.php
│   ├── Engines/
│   │   ├── FileEngine.php               # Flat-file engine (chunks + trigram)
│   │   ├── SqliteEngine.php             # FTS5 engine
│   │   └── MySqlEngine.php              # MySQL FULLTEXT engine
│   ├── Text/
│   │   ├── HasTextHelpers.php           # Shared: scriptsOf, tokenizeText, normalizeQuery
│   │   ├── HasScoring.php               # Shared: normalizeScore (0–100)
│   │   ├── HasDebugCollector.php        # Shared: DebugBar integration
│   │   ├── UnicodeTextProcessor.php
│   │   ├── StemmingTextProcessor.php
│   │   └── FallbackTextProcessor.php
│   ├── Support/
│   │   ├── ChunkStorage.php             # FileEngine chunk I/O
│   │   ├── StatsService.php             # Term-frequency stats for BM25 IDF
│   │   ├── ScoreService.php             # BM25 + tokenization
│   │   ├── MatchService.php             # AND/OR/NOT/phrase matching
│   │   ├── VocabService.php             # Unified suggest + trigram spellcheck
│   │   ├── SearchCache.php              # File-based result cache (all engines)
│   │   ├── TrigramIndex.php             # O(1) trigram inverted index
│   │   ├── ConcurrentProcessor.php      # pcntl_fork with sequential fallback
│   │   ├── OperatorRegistry.php         # Operator parsing
│   │   ├── ConfigQueue.php
│   │   ├── SnippetService.php
│   │   ├── SmartDatasetProvider.php     # seed.json analysis + query generation
│   │   └── Benchmark/                   # BenchmarkRunner, MetricCollector, DataGenerator
│   ├── Exceptions/
│   │   ├── IOException.php
│   │   ├── CorruptChunkException.php
│   │   └── FileEngineException.php
│   ├── Console/Commands/
│   │   ├── RebuildCommand.php
│   │   ├── SyncCommand.php
│   │   ├── BenchmarkCommand.php         # --repetitions, --seed, --all-engines
│   │   ├── SearchCommand.php
│   │   ├── DoctorCommand.php
│   │   ├── StatusCommand.php
│   │   ├── OptimizeCommand.php
│   │   ├── CheckCommand.php
│   │   └── DiscoverFilamentCommand.php
│   ├── Debug/
│   │   └── IllumiSearchCollector.php    # DebugBar collector
│   ├── Http/Controllers/
│   │   └── SearchApiController.php
│   ├── Jobs/
│   ├── Facades/IllumiSearch.php
│   ├── QueryBuilder.php
│   ├── Result.php
│   ├── IndexManager.php
│   ├── Searchable.php
│   └── Spellcheck.php
├── tests/
│   ├── Unit/
│   │   ├── Engines/
│   │   └── Support/...                  # StopwordFilter, HasTextHelpers, ConfigQueue, etc.
│   ├── Feature/Engines/
│   │   ├── AbstractEngineTest.php       # 34 cross-engine tests
│   │   ├── FileEngineIntegrationTest.php
│   │   ├── SqliteEngineIntegrationTest.php
│   │   ├── MySqlEngineIntegrationTest.php
│   │   └── CrossEngineConsistencyTest.php
│   └── Support/
│       └── TestDataFactory.php          # Reusable test data helpers
└── resources/stopwords/                 # 33 language stopword lists
```

---

## Custom Engine

The `Engine` interface defines the contract (34 methods). Implement it to add your own engine:

```php
use Moaines\IllumiSearch\Contracts\Engine;

class MyCustomEngine implements Engine
{
    public function setRebuilding(bool $isRebuilding): void {}
    public function upsert(string $modelClass, int|string $modelId, array $document): void {}
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array {}
    // ... 31 more methods (see Engine.php)
}
```

Register via the ServiceProvider:

```php
use Moaines\IllumiSearch\IllumiSearchServiceProvider;

IllumiSearchServiceProvider::extend('custom', fn ($app) => new MyCustomEngine);
```

All implementations must pass `AbstractEngineTest`.

---

## Limitations

### SQLite FTS5

- **Cloud storage not supported** — FTS5 index must reside on local filesystem
- **Ephemeral environments** — index lost on redeploy (Vapor, Kubernetes)
- **Concurrent writes** — SQLite handles reads well but not concurrent writes

### MySQL FULLTEXT

- **FTS5-specific features** (`getPragma`, `vacuum`, `queryVocab`) return null/no-op
- **CJK search** requires `ngram` parser (not configured by default)
- **No native stemming** — relies on PHP preprocessing
- **Dedicated connection** — uses `illumi-search-mysql` connection

### FileEngine

- **Search speed** — slower than SQLite/MySQL (no native inverted index). Cold ~200ms, cached <1ms
- **Disk usage** — larger than SQLite (chunks + trigram index + postings + stats + cache)
- **Fork availability** — concurrent chunk processing requires `ext-pcntl` (CLI only; web falls back to sequential)
- **Write amplification** — every upsert rewrites a chunk file
- **Trigram index rebuild** — required after bulk operations (done automatically in `rebuildVocabFromScratch`)

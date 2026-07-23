# Illumi Search — Multi-Engine FTS

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

<p align="center">
    <img src="art/banner_1024x640.png" alt="Illumi Search" width="800">
</p>

**Full-text search for Laravel — works with SQLite FTS5 or MySQL 8.0+ FULLTEXT.**  
BM25 relevance ranking, search-as-you-type prefix indexing, multilingual accent folding
(Latin, CJK, Arabic, Cyrillic), per-column weights, boolean operators,
auto-detected operator support with NEAR→AND fallback, spellcheck,
multi-tenant isolation, authorization.
Drop-in `Searchable` trait with queue/sync/lazy batch indexing.
No external services. No configuration.

```bash
composer require moaines/illumi-search
```

---

## Why?

| | `LIKE %term%` | Illumi Search |
|---|---|---|
| Relevance ranking | None | BM25 |
| Accent insensitive | No | Yes (intl / Symfony / collation) |
| Search-as-you-type | No | Prefix indexing |
| Chinese / Japanese / Korean | No | Character-level tokenization |
| Column weighting | No | Per-column weights |
| Performance (10k+ rows) | Table scan | Inverted index |
| PHP extension required | None | `ext-sqlite3` + `ext-mbstring` (SQLite) or `ext-pdo_mysql` (MySQL) |
| Database engine | — | **SQLite FTS5** (default) or **MySQL 8.0+ FULLTEXT** |
| Hosting compatibility | Any | ✅ Almost anywhere Laravel runs — FTS5 is bundled with PHP, MySQL is universal.

---

## Requirements

### SQLite driver (default)

| Dependency | Required | Notes |
|---|---|---|
| PHP `^8.2` | ✅ | |
| `ext-sqlite3` (with FTS5) | ✅ | Bundled with PHP 8+. Run `php artisan illumi-search:doctor` to verify FTS5 support. |
| `ext-mbstring` | ✅ | Bundled with PHP |
| `ext-intl` | 🟡 Optional | Full Unicode normalization. Symfony `String::ascii()` fallback active without it. |

### MySQL driver (`ILLUMI_SEARCH_DRIVER=mysql`)

| Dependency | Required | Notes |
|---|---|---|
| PHP `^8.2` | ✅ | |
| `ext-pdo_mysql` | ✅ | |
| MySQL 8.0+ / MariaDB 10.5+ | ✅ | InnoDB with FULLTEXT indexes |
| `ext-mbstring` | ✅ | Bundled with PHP |
| `ext-intl` | 🟡 Optional | Same fallback as SQLite driver |

> **Note:** When using the MySQL driver, `ext-sqlite3` is **not required**. Each driver has its own dependencies.

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

### 2. Build the index

```bash
php artisan illumi-search:rebuild
```

### 3. Search

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

$results = IllumiSearch::query('laravel')->model(Post::class)->get();
```

---

## Documentation

- [Installation](#installation)
- [Configuration](#configuration)
- [Model Setup](#model-setup)
- [Search (PHP)](#search-php)
- [MySQL Driver](#mysql-driver)
- [Spellcheck](#spellcheck)
- [REST API](#rest-api)
- [Artisan Commands](#artisan-commands)
- [How It Works](#how-it-works)
- [Text Processing](#text-processing)
- [Multi-tenant](#multi-tenant-isolation)
- [Authorization](#authorization)
- [Benchmark](#benchmark)
- [Testing](#testing)
- [Package Structure](#package-structure)

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

### Environment variables

#### Shared (all engines)

| Env | Config key | Default | Possible values |
|-----|-----------|---------|----------------|
| `ILLUMI_SEARCH_DRIVER` | `driver` | `sqlite` | `sqlite`, `mysql` |
| `ILLUMI_SEARCH_MODE` | `processing.mode` | `advanced` | `basic` (simple wildcards), `advanced` (boolean, phrase) |
| `ILLUMI_SEARCH_PROCESSOR` | `processing.processor` | `unicode` | `unicode`, `stemming` |
| `ILLUMI_SEARCH_INDEXING` | `indexing.mode` | `queue` | `queue`, `sync`, `manual` |
| `ILLUMI_SEARCH_QUEUE_CONNECTION` | `indexing.queue` | `null` | Any queue name |
| `ILLUMI_SEARCH_REBUILD_BATCH_SIZE` | `indexing.rebuild_batch_size` | `0` (sync) | `500`, `1000` |
| `ILLUMI_SEARCH_AUTHORIZATION` | `authorization.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_TENANCY` | `tenancy.enabled` | `false` | `true`, `false` |
| `ILLUMI_SEARCH_SPELLCHECK_VOCAB_LIMIT` | `spellcheck.vocab_limit` | `5000` | Max vocab entries loaded for spellcheck |
| `ILLUMI_SEARCH_MAX_TEXT_LENGTH` | `processing.max_search_text_length` | `65535` | Truncation limit per document |
| `ILLUMI_SEARCH_MAX_WEIGHT` | `processing.max_weight` | `3` | Maximum column weight |
| — | `processing.stopwords` | `['en']` | Language codes for stopword filtering |
| — | `processing.max_results` | `50` | Default result limit |

#### SQLite-specific

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_DATABASE_PATH` | `engines.sqlite.database_path` | `app/search/search-index.sqlite` | Relative to `storage_path()`, or absolute (starts with `/`) |
| — | `engines.sqlite.fts5.tokenizer` | `unicode61` | `unicode61`, `ascii`, `porter`, `porter unicode61 remove_diacritics 2` |
| — | `engines.sqlite.fts5.prefix_lengths` | `[2, 3, 4]` | Prefix index lengths |
| `ILLUMI_SEARCH_COLUMNSIZE` | `engines.sqlite.fts5.columnsize` | `1` | `1` (default), `0` (omit column sizes) |
| — | `engines.sqlite.fts5.detail` | `full` | `full`, `column`, `none` |
| — | `engines.sqlite.fts5.automerge` | `4` | Segments before auto-merge |
| — | `engines.sqlite.fts5.crisismerge` | `16` | Segments before forced merge |
| — | `engines.sqlite.fts5.pgsz` | `1000` | Index page size (bytes) |
| `ILLUMI_SEARCH_WAL` | `engines.sqlite.runtime.wal` | `true` | `true`, `false` |
| `ILLUMI_SEARCH_CACHE_SIZE_KB` | `engines.sqlite.runtime.cache_size_kb` | `-64000` (64 MB) | Positive = pages, negative = kilobytes |
| `ILLUMI_SEARCH_SYNCHRONOUS` | `engines.sqlite.runtime.synchronous` | `NORMAL` | `NORMAL`, `FULL`, `OFF` |
| `ILLUMI_SEARCH_TEMP_STORE` | `engines.sqlite.runtime.temp_store` | `MEMORY` | `MEMORY`, `FILE`, `DEFAULT` |
| `ILLUMI_SEARCH_BUSY_TIMEOUT` | `engines.sqlite.runtime.busy_timeout` | `15000` | Milliseconds (0 = no timeout) |
| `ILLUMI_SEARCH_MMAP_SIZE` | `engines.sqlite.runtime.mmap_size` | `0` (disabled) | ⚠️ Incompatible with NFS/Docker |
| `ILLUMI_SEARCH_OPERATORS` | `operators.enabled` | `null` | `null` (auto-detect), `['AND', 'OR']`, `[]` |

#### MySQL-specific

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_MYSQL_HOST` | `engines.mysql.connection.host` | `127.0.0.1` | MySQL host |
| `ILLUMI_SEARCH_MYSQL_PORT` | `engines.mysql.connection.port` | `3306` | MySQL port |
| `ILLUMI_SEARCH_MYSQL_DATABASE` | `engines.mysql.connection.database` | `illumi_search` | MySQL database |
| `ILLUMI_SEARCH_MYSQL_USERNAME` | `engines.mysql.connection.username` | `root` | MySQL username |
| `ILLUMI_SEARCH_MYSQL_PASSWORD` | `engines.mysql.connection.password` | `''` | MySQL password |

### Environment file example

```env
# Application database (MySQL)
DB_CONNECTION=mysql
DB_DATABASE=my_app

# illumi-search engine: MySQL
ILLUMI_SEARCH_DRIVER=mysql
ILLUMI_SEARCH_MYSQL_HOST=127.0.0.1
ILLUMI_SEARCH_MYSQL_DATABASE=my_app

# illumi-search engine: SQLite (alternative)
# ILLUMI_SEARCH_DRIVER=sqlite
# ILLUMI_SEARCH_DATABASE_PATH=app/search/search-index.sqlite
```

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

Assign BM25 relevance multipliers:

```php
protected array $searchable = [
    'title' => ['weight' => 3],  // 3× importance in ranking
    'body'  => ['weight' => 1],
];
```

Three syntaxes:

| Syntax | Example | Description |
|--------|---------|-------------|
| **Explicit** | `'title' => ['weight' => 3]` | Full configuration with options |
| **Minimal** | `'author'` | Default weight, no options |
| **Shorthand** | `'excerpt' => true` | Same as minimal |

Weights are clamped to `max_weight` (configurable via `ILLUMI_SEARCH_MAX_WEIGHT`, default 3).

### With dot notation

Search across related model attributes:

```php
protected array $searchable = [
    'writer.name'      => ['weight' => 3],  // belongsTo → Writer.name
    'comments.body'    => ['weight' => 1],  // hasMany → Comment.body
    'fullname'         => ['weight' => 2],  // accessor → getFullnameAttribute()
];
```

| Notation | Resolution | Example |
|---|---|---|
| `'writer.name'` | `$book->writer->name` | `'Jean Dupont'` |
| `'comments.body'` | `$book->comments->pluck('body')->implode(' ')` | `'Great! Loved it.'` |
| `'fullname'` | `$book->fullname` (accessor) | `'Les Misérables by Jean Dupont'` |

Dots (`.`) and arrows (`->`) in column names are converted to underscores (`_`) for storage.

### Custom document mapping

```php
public function toSearchDocument(): array
{
    return [
        'title'  => $this->title,
        'body'   => strip_tags($this->body),
        'author' => $this->author->name,
    ];
}
```

### Custom TextProcessor

```php
use Moaines\IllumiSearch\Contracts\TextProcessor;

class MyCustomProcessor implements TextProcessor
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
        return MyCustomProcessor::class;
    }
}
```

---

## Search (PHP)

### Facade

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;
```

### Basic

```php
$results = IllumiSearch::query('bonjour')->get();
```

### Filter by model

```php
$results = IllumiSearch::query('bonjour')->model(Post::class)->get();
$results = IllumiSearch::query('bonjour')->models([Post::class, Comment::class])->get();
```

### Limit and offset

```php
$results = IllumiSearch::query('bonjour')
    ->model(Post::class)
    ->limit(10)->offset(20)
    ->get();
```

### Search mode

```php
$results = IllumiSearch::query('bonjour')->mode('advanced')->get();  // boolean operators, phrases
$results = IllumiSearch::query('bonjour')->mode('basic')->get();     // simple keywords + wildcard
```

### Count

```php
$count = IllumiSearch::query('bonjour')->count();
```

### Pagination

```php
$paginator = IllumiSearch::query('bonjour')->paginate(15);
```

### Result object

```php
class Result {
    public string $id;          // "App\Models\Post:42"
    public string $modelClass;
    public int|string $modelId;
    public float $rank;         // BM25 score (lower = more relevant)
    public string $title;
    public ?string $summary;    // Context snippet with <mark> highlighting
    public array $raw;          // All indexed columns
    public ?int $totalCount;    // Total matching records (for pagination)
}
```

### Operators in search queries

| Syntax | Example | Behavior |
|--------|---------|----------|
| Single term | `laravel` | Documents containing "laravel" |
| AND | `laravel AND vuejs` | Both terms must match |
| OR | `php OR python` | At least one term matches |
| NOT | `php NOT laravel` | Exclude "laravel" |
| Exact phrase | `"software engineering"` | Consecutive words matching |
| Wildcard | `soft*` | Prefix matching |

---

## MySQL Driver

### How it works

The MySQL engine stores all indexed documents in a single `search_index` table with multiple `text_w{N}` columns — one per weight level:

```sql
CREATE TABLE search_index (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    model_type VARCHAR(255) NOT NULL,   -- 'App\Models\Post'
    model_id VARCHAR(255) NOT NULL,     -- Primary key (supports UUIDs)
    text_w1 LONGTEXT NOT NULL DEFAULT '',  -- weight 1: biography, nationality
    text_w2 LONGTEXT NOT NULL DEFAULT '',  -- weight 2
    text_w3 LONGTEXT NOT NULL DEFAULT '',  -- weight 3: title
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_model_model_id (model_type, model_id),
    FULLTEXT INDEX idx_fts_w1 (text_w1),
    FULLTEXT INDEX idx_fts_w2 (text_w2),
    FULLTEXT INDEX idx_fts_w3 (text_w3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Search uses MySQL `MATCH ... AGAINST (... IN BOOLEAN MODE)` with per-column weighting:

```sql
MATCH(text_w1) AGAINST('php') * 1 +
MATCH(text_w2) AGAINST('php') * 2 +
MATCH(text_w3) AGAINST('php') * 3  AS weighted_score
```

### Operator mapping

| FTS5 syntax | MySQL BOOLEAN MODE |
|---|---|
| `php AND laravel` | `+php* +laravel*` |
| `php OR laravel` | `php* laravel*` |
| `NOT word` | `-word*` |
| `"exact phrase"` | `"exact phrase"` |
| `word*` (prefix) | `word*` |
| `word NEAR other` | `+word* +other*` (fallback AND) |

### Spellcheck

The MySQL engine has its own spellcheck system via a `search_vocab` table. Words are harvested during indexing with their ASCII transliteration and document frequency. Spellcheck queries filter by prefix (`ascii_word LIKE 'la%'`) then compute Levenshtein distance on the filtered set.

### Script-aware suggestions

Spellcheck considers Unicode scripts: Latin queries prioritize Latin suggestions, Cyrillic queries prioritize Cyrillic. A script mismatch adds a penalty of +3 to the distance score.

### What's different from SQLite

| Feature | SQLite FTS5 | MySQL FULLTEXT |
|---|---|---|
| Storage | Virtual tables per model | Single `search_index` table with weight columns |
| Ranking | Native BM25 by FTS5 | `MATCH(w_N) * N` combined score |
| Stemming | Via `porter` tokenizer | Via PHP `wamania/php-stemmer` in `processDocument()` |
| Accent folding | Via `remove_diacritics 2` tokenizer | Via `utf8mb4_unicode_ci` collation + PHP preprocessing |
| Index rebuild | `DROP TABLE` + recreate per model | Atomic `RENAME TABLE` swap |
| `getPragma()` | Returns SQLite PRAGMA values | Returns `null` |
| `vacuum()` | Reclaims SQLite disk space | No-op |
| `queryVocab()` | Returns FTS5 vocab entries | Always returns `[]` |

### Limitations

- **FTS5-specific methods** (`getPragma`, `vacuum`, `queryVocab`) return null / no-op
- **Spellcheck** relies on `search_vocab` table (populated during indexing)
- **Full-text search** quality depends on MySQL's built-in FULLTEXT parser; PHP pre-normalization (stemming, accent folding, stopwords) compensates for MySQL's lack of native stemming

---

## Spellcheck (Did you mean?)

```php
use Moaines\IllumiSearch\Facades\IllumiSearch;

$suggestions = IllumiSearch::didYouMean('laravell');  // ['laravel']
```

Advanced usage:

```php
use Moaines\IllumiSearch\Spellcheck;

$spellcheck = app(Spellcheck::class);
$suggestions = $spellcheck
    ->maxDistance(2)          // max Levenshtein distance (default: 2)
    ->maxSuggestions(5)       // max suggestions (default: 5)
    ->suggest('laravell', [Post::class]);
```

The spellcheck engine works differently depending on the driver:
- **SQLite**: Queries FTS5 `%_vocab` tables via `queryVocab()`
- **MySQL**: Queries a dedicated `search_vocab` table (populated during indexing)

Both support script-aware suggestions (Latin + Cyrillic + CJK + Arabic + Hebrew + Greek + Devanagari).

---

## REST API

Search endpoint for headless apps, mobile apps, or programmatic access.

### Enable

```env
ILLUMI_SEARCH_API_ENABLED=true
```

### Endpoint

```
GET /api/search
```

### Parameters

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | ✅ | — | Search query (max 200 chars) |
| `models` | string/array | ❌ | All indexed | Comma-separated or array |
| `limit` | int | ❌ | 10 | Max results (max 50) |
| `mode` | string | ❌ | `advanced` | `basic`, `advanced`, `raw` |
| `suggest` | bool | ❌ | `false` | Include spellcheck suggestions when no results |

### Response

```json
{
    "results": [
        {
            "id": "records:...",
            "title": "Laravel Testing",
            "url": "/admin/posts/1/edit",
            "summary": "... <mark>Laravel</mark> ..."
        }
    ],
    "total": 1,
    "suggestions": ["laravel", "laravel framework"]
}
```

---

## Artisan Commands

### `illumi-search:rebuild`

Drop and recreate all search indexes, then repopulate from Eloquent models.

```
Options:
  --model=CLASS     Rebuild specific model(s)  (repeatable)
  --force           Skip confirmation
  --batch-size=N    Index N records now, queue the rest
```

### `illumi-search:sync`

Incremental sync of changed records.

```
Options:
  --model=CLASS  Sync specific model(s)
  --since=DATE   Only records updated after date
```

### `illumi-search:doctor`

Diagnose the search environment — PHP extensions, engine support, database health.

### `illumi-search:status`

Index statistics (records per model, total size, engine version).

### `illumi-search:optimize`

Run optimization on the search index (VACUUM for SQLite, OPTIMIZE TABLE for MySQL).

### `illumi-search:benchmark`

Run performance and quality benchmarks on the configured engine (or both with `--all-engines`).

```
Options:
  --docs=1000       Number of documents to index
  --all-engines     Benchmark both SQLite and MySQL
  --format=table    Output format: table, json
  --memory=512M     Memory limit for the benchmark
  --timeout=300     Max execution time (seconds)
  --mode=processed  Indexing mode: processed, raw, both
```

Output example:

```
📊 Quantity (higher is better)
+---------------------------------+--------+---------+
| Metric                          | MySQL  | SQLite  |
+---------------------------------+--------+---------+
| Upsert (fast)                   | 86.7   | 606.3   |
| Search (exact)                  | 44.9   | 875.4   |
| Suggest                         | 31.9   | 1720.8  |
| Index size                      | 91.0   | 0.3     |
+---------------------------------+--------+---------+

🎯 Quality (higher is better)
+---------------------------------+--------+---------+
| Metric                          | MySQL  | SQLite  |
+---------------------------------+--------+---------+
| Precision@5                     | 0.85   | 0.85    |
| NDCG@5                          | 0.85   | 0.85    |
| Fuzzy tolerance                 | ✓      | ✓       |
| Accent insensitivity            | ✓      | ✓       |
+---------------------------------+--------+---------+

🧠 Soundness (expected behaviour)
+---------------------------------+--------+---------+
| Metric                          | MySQL  | SQLite  |
+---------------------------------+--------+---------+
| Stemming (developing→development)| ✗      | ✓       |
| AND operator narrows             | ✓      | ✓       |
| Script isolation                 | ✓      | ✓       |
| Order stability                  | ✓      | ✓       |
+---------------------------------+--------+---------+
```

### Other commands

| Command | Description |
|---------|-------------|
| `illumi-search:check` | Detect schema drift |
| `illumi-search:search` | Search from CLI (`--json`, `--suggest`) |
| `illumi-search:discover-filament` | Analyze Filament Resources for searchable columns |

---

## How It Works

### Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Your Application                       │
│  ┌───────────────────────────────────────────────────┐  │
│  │  Model with Searchable trait                       │  │
│  │  - saved / deleted / restored events               │  │
│  │  - toSearchDocument() + processDocument()          │  │
│  └──────────────────────┬────────────────────────────┘  │
│                         │                                │
│  ┌──────────────────────▼────────────────────────────┐  │
│  │            Engine Contract                         │  │
│  │  ┌─────────────────┐    ┌──────────────────────┐  │  │
│  │  │   SqliteEngine  │    │     MySqlEngine      │  │  │
│  │  │   (FTS5)        │    │     (FULLTEXT)       │  │  │
│  │  └────────┬────────┘    └──────────┬───────────┘  │  │
│  │           │                        │                │
│  │  ┌────────▼────────┐    ┌──────────▼───────────┐  │  │
│  │  │ search-index    │    │ search_index (MySQL)  │  │  │
│  │  │ .sqlite (FTS5)  │    │ model_type, model_id  │  │  │
│  │  │ idx_posts       │    │ text_w1, text_w2,     │  │  │
│  │  │ idx_comments    │    │ text_w3 (FULLTEXT)    │  │  │
│  │  └─────────────────┘    └──────────────────────┘  │  │
│  └───────────────────────────────────────────────────┘  │
│                                                          │
│  ┌───────────────────────────────────────────────────┐  │
│  │  TextProcessor Pipeline (pre-indexing, all engines)│  │
│  │  1. strip HTML                                     │  │
│  │  2. Normalize Unicode (NFC/NFD)                    │  │
│  │  3. Remove diacritics (café → cafe)                │  │
│  │  4. Separate CJK characters (开 发 入 门)          │  │
│  │  5. lowercase (Str::lower)                         │  │
│  │  6. Filter stopwords                               │  │
│  │  7. Truncate long tokens (> 32 chars)              │  │
│  │  8. Collapse whitespace                            │  │
│  └───────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

### Indexing flow

```
Model::saved()
  └─ shouldSync() → true
      └─ processDocument() → [title: "...", body: "..."]
          └─ Engine::upsert($modelClass, $modelId, $document)
              ├─ SQLite: INSERT OR REPLACE into FTS5 virtual table
              └─ MySQL: INSERT INTO search_index ON DUPLICATE KEY UPDATE
                   (distributes columns by weight → text_w1..wN)
```

### Search flow

```
User query → normalizeQuery() → toBooleanMode() / escapeQuery()
  └─ Engine::search($query, $modelClasses, $limit, $offset)
      ├─ SQLite: FTS5 MATCH → BM25 ranking → results
      └─ MySQL: MATCH(text_w1)*1 + MATCH(text_w2)*2 + ... → ranking → results
  └─ enrichWithSnippets() → <mark> highlighting
  └─ Return Result[]
```

---

## Text Processing

The `UnicodeTextProcessor` pipeline normalizes text **before** indexing. This preprocessing compensates for MySQL's lack of native stemming and accent folding:

| Step | Effect | Example |
|------|--------|---------|
| `strip_tags()` | Remove HTML | `<p>Hello</p>` → `Hello` |
| `Normalizer::FORM_C` | Unicode NFC | `é` (NFD) → `é` (NFC) |
| Remove diacritics | Remove accents | `café` → `cafe` |
| `separateCjk()` | Space between CJK chars | `开发` → `开 发` |
| `lowercase()` | Lowercase | `Hello` → `hello` |
| `filterStopwords()` | Remove common words | `the php` → `php` |
| `truncateLongTokens(32)` | Limit token length | URLs, UUIDs truncated |
| `cleanWhitespace()` | Collapse spaces | `a    b` → `a b` |

### Stopwords

33 language word lists built-in (Arabic, English, French, Russian, Chinese, Japanese, etc.). Configure:

```php
// config/illumi-search.php
'processing' => [
    'stopwords' => ['fr', 'en', 'ar'],
],
```

---

## Multi-tenant Isolation

Isolate search indexes per tenant. Each tenant gets its own isolated index:

- **SQLite**: separate database file per tenant (`storage/app/search/tenants/{id}/search-index.sqlite`)
- **MySQL**: separate table set per tenant (`{id}_search_index`, `{id}_search_config`, `{id}_search_vocab`)

The configuration and resolver are shared across engines:

```php
// config/illumi-search.php
'tenancy' => [
    'enabled' => env('ILLUMI_SEARCH_TENANCY', false),
    'directory' => 'app/search/tenants',
],
```

Register a resolver:

```php
use Moaines\IllumiSearch\TenantManager;

$this->app->resolving(TenantManager::class, function (TenantManager $manager) {
    $manager->setResolver(fn () => tenant()->id);
});
```

Data isolation is guaranteed by table prefixing — the tenant ID is prepended to every table name (`42_search_index`, `42_search_vocab`). There is no shared `WHERE tenant_id` clause to forget, and no risk of cross-tenant data leakage.

---

## Authorization

Filter search results by user permissions using Laravel's Gate/Policy system:

```php
$results = IllumiSearch::query('laravel')
    ->model(Post::class)
    ->withAuthorization()
    ->get();
```

---

## Benchmark

Built-in performance benchmarking across all engines:

```bash
# Benchmark the configured engine
php artisan illumi-search:benchmark --docs=10000

# Compare both engines
php artisan illumi-search:benchmark --docs=5000 --all-engines --memory=2G

# Raw mode (no preprocessing, shows native engine capabilities)
php artisan illumi-search:benchmark --docs=5000 --mode=raw --all-engines
```

The benchmark measures:
- **Quantity**: Upsert, Search, Suggest, Rebuild throughput (ops/sec)
- **Quality**: Precision@5, NDCG@5, MAP@5, Fuzzy tolerance, Accent insensitivity
- **Soundness**: Stemming, AND/OR/NOT operators, Phrase matching, Script isolation, Order stability

---

## Custom Engine

The `Engine` interface defines the contract for all search engine implementations (33 methods). Implement it to add your own engine:

```php
use Moaines\IllumiSearch\Contracts\Engine;

class MyCustomEngine implements Engine
{
    public function upsert(string $modelClass, int|string $modelId, array $document): void {}
    public function search(string $query, array $modelClasses, int $limit, int $offset = 0, string $mode = 'advanced', bool $withSnippets = true): array {}
    public function suggest(string $query, int $maxDistance = 2, int $limit = 5): array {}
    public function getSupportedOperators(): array { return ['AND', 'OR']; }
    public function supportsPhraseSearch(): bool { return true; }
    public function supportsPrefixWildcard(): bool { return true; }
    public function getEngineStatus(): array { return ['driver' => 'Custom']; }
    // ... see src/Contracts/Engine.php for all 33 methods
}
```

Register via the ServiceProvider's extensible registry (in your `AppServiceProvider::register()`):

```php
use Moaines\IllumiSearch\IllumiSearchServiceProvider;

IllumiSearchServiceProvider::extend('custom', fn ($app) => new MyCustomEngine);
```

Or directly bind into the container:

```php
$this->app->singleton(Engine::class, fn () => new MyCustomEngine);
```

All implementations must pass `AbstractEngineTest` (19 cross-engine tests).

---

## Testing

```bash
phpunit                                 # Run all tests (386)
phpunit --testdox                       # Named tests
phpunit --filter="MySqlEngine"          # MySQL-specific tests
phpstan analyse                         # Static analysis
pint                                    # Code style
```

### Test coverage

**386 tests** (831 assertions) across two search engines:

- **Cross-engine compatibility** — 19 common tests run against both SQLite and MySQL (AbstractEngineTest)
- **Text processing** — accent folding, CJK, stemming, HTML stripping, token truncation, Unicode normalization
- **Spellcheck** — Levenshtein-based suggestions, script-aware filtering, coverage metrics
- **Authorization** — policy-based result filtering, Gate integration
- **Multi-tenant isolation** — tenant-aware database paths
- **Benchmark** — 8 quantitative + 10 qualitative + 8 soundness metrics
- **Config** — schema drift detection, operator masking, stopword filtering

### Benchmarking demo project

A demo project is available at [moaines/illumi-search-demo](https://github.com/moaines/illumi-search-demo) with seed data (~9000 records) for testing. It includes a Filament admin panel with search status page, spotlight search, and benchmark commands.

---

## Package Structure

```
illumi-search/
├── config/illumi-search.php
├── src/
│   ├── Contracts/                    # Engine, TextProcessor interfaces
│   ├── Engines/
│   │   ├── SqliteEngine.php          # FTS5 implementation
│   │   └── MySqlEngine.php           # MySQL FULLTEXT implementation
│   ├── Text/
│   │   ├── HasTextHelpers.php        # Shared trait (scriptsOf, tokenizeText, normalizeQuery)
│   │   ├── UnicodeTextProcessor.php
│   │   ├── StemmingTextProcessor.php
│   │   └── FallbackTextProcessor.php
│   ├── Support/
│   │   ├── OperatorRegistry.php      # AND/OR/NOT/NEAR masking and tokenizing
│   │   ├── ConfigQueue.php           # Persistent capped lists via engine config
│   │   ├── SnippetService.php
│   │   └── Benchmark/                # BenchmarkRunner, MetricCollector, DataGenerator,
│   │                                   ReportRenderer, IdentityProcessor
│   ├── Console/Commands/
│   │   ├── RebuildCommand.php
│   │   ├── SyncCommand.php
│   │   ├── BenchmarkCommand.php      # --all-engines, --format=json, --mode=raw
│   │   ├── SearchCommand.php
│   │   ├── DoctorCommand.php         # Multi-engine diagnostics
│   │   ├── StatusCommand.php
│   │   ├── OptimizeCommand.php
│   │   ├── CheckCommand.php
│   │   └── DiscoverFilamentCommand.php
│   ├── Http/Controllers/SearchApiController.php
│   ├── Http/Requests/SearchApiRequest.php
│   ├── Jobs/                         # IndexModelJob, DeleteIndexJob, IndexBatchJob
│   ├── Facades/IllumiSearch.php
│   ├── QueryBuilder.php
│   ├── Result.php
│   ├── IndexManager.php
│   ├── Searchable.php
│   └── Spellcheck.php
├── tests/
│   ├── Unit/Engines/                 # SqliteEngineTest, MySqlEngineTest
│   ├── Feature/Engines/
│   │   ├── AbstractEngineTest.php    # 17 cross-engine tests
│   │   ├── MySqlEngineIntegrationTest.php
│   │   ├── SqliteEngineIntegrationTest.php
│   │   └── DriverSwitchTest.php
│   └── ...                           # TextProcessor, Spellcheck, Commands, etc.
└── resources/stopwords/              # 33 language stopword lists
```

---

## Limitations

### SQLite driver
- **Cloud storage not supported.** The FTS5 index must reside on a local filesystem
- **Ephemeral environments.** On serverless platforms (Vapor, Kubernetes), the index file is lost on redeploy
- **Concurrent writes.** SQLite handles reads well but does not support concurrent writes from multiple processes

### MySQL driver
- **FTS5-specific features** (`getPragma`, `vacuum`, `queryVocab`) return null / no-op
- **No native stemming.** Relies on PHP preprocessing (`wamania/php-stemmer` + `processDocument()`)
- **Spellcheck** requires a populated `search_vocab` table (rebuild periodically)
- **Connection.** Uses a dedicated MySQL connection (`illumi-search-mysql`), independent from the application's default

---

## Changelog

### v1.15.0

- **Multi-engine architecture.** New `MySqlEngine` for MySQL 8.0+ FULLTEXT alongside existing `SqliteEngine`.
- **Per-column weight columns.** MySQL stores weight levels in separate FULLTEXT columns (`text_w1`, `text_w2`, `text_w3`) instead of text repetition. BM25 ranking uses `MATCH(col) * weight` for precise scoring.
- **Atomic swap rebuild.** `rebuildVocabFromScratch()` and `rebuildIndexFromScratch()` use `RENAME TABLE` atomic swap on MySQL.
- **`getEngineStatus()`** — new Engine interface method returning engine-specific metadata. `SearchStatus` Filament page renders dynamically without hardcoded FTS5 keys.
- **Config restructured.** Shared settings under `processing.*`, engine-specific under `engines.sqlite.*` / `engines.mysql.*`.
- **`max_weight`** — configurable per-column weight clamping (default: 3).
- **Script-aware spellcheck.** `scriptsOf()` detects 30+ Unicode scripts in both directions (Latin→Latin, Cyrillic→Cyrillic, CJK→CJK, etc.) with a configurable mismatch penalty.
- **Benchmark command.** `php artisan illumi-search:benchmark` with `--all-engines`, `--mode=raw`, quantitative + quality + soundness metrics.
- **`ConfigQueue`** — persistent bounded lists via engine config storage.
- **`ServiceProvider::extend()`** — extensible engine registry for third-party engines.
- **`getSupportedOperators()`**, `supportsPhraseSearch()`, `supportsPrefixWildcard()` — Engine interface additions for dynamic capability discovery.
- **Multi-tenant MySQL.** Table prefixing (`tenant_id_search_index`) for data isolation.
- **`init(128 MB)` OOM fix.** Fallback processor without `ext-intl`, configurable `--memory=2G` option in benchmark.
- **386 tests**, 831 assertions across two engines.
- **Breaking changes:**
  - Config paths moved: `illumi-search.fts5.*` → `illumi-search.engines.sqlite.fts5.*`, `illumi-search.mysql.*` → `illumi-search.engines.mysql.*`, `illumi-search.stopwords` → `illumi-search.processing.stopwords`, etc.
  - `buildSearchText()` returns an array keyed by weight column instead of a single string.
  - `search_index` table schema changed: weight columns instead of single `search_text` (FULLTEXT indexes now per-column).

### v1.14.0

- **OperatorRegistry** — centralized operator tokenization, masking, and unmasking for stopword-filter-safe operator handling (`NOT` preserved when English stopwords enabled).
- **Count pagination** — `COUNT(*) OVER ()` window function in FTS5 queries for accurate total counts without a separate `SELECT COUNT()`.

### v1.13.0

- Engine interface cleaned up (33 methods).
- N+1 authorization fixed.
- Soft delete support.
- afterCommit for queue jobs.
- PHPStan baseline ~98% reduction.
- Laravel Debugbar integration.
- 256+ tests, 524+ assertions.

### v1.11.0

- REST API, CLI search, spellcheck.

# Illumi Search — Full-Text Search for Laravel

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

**One interface, four engines.** Plug-and-play full-text search for Laravel — SQLite FTS5, MySQL FULLTEXT, PostgreSQL tsvector, FileEngine. Same API, zero configuration. Fits most web applications: e-commerce, intranets, admin panels, and content sites. Your data stays on your servers — no monthly fees.

```bash
composer require moaines/illumi-search

# Then in PHP:
$results = IllumiSearch::query('laravel')->get();        // < 1ms
```

No external services. Add the `Searchable` trait to your model, configure an engine, search.

---

## Choose your engine

| Engine | `ILLUMI_SEARCH_DRIVER` | Requirements | Search speed | Best for |
|--------|------------------------|--------------|:------------:|----------|
| **SQLite FTS5** | `sqlite` (default) | `ext-sqlite3`, `ext-mbstring` | **741 q/sec** | Most Laravel projects — shops < 50k items, admin panels, intranets, content sites. Zero config. |
| **PostgreSQL** | `pgsql` | `ext-pdo-pgsql`, PostgreSQL 12+ | **340 q/sec** | Multi-language apps, moderate-high traffic, large volumes (> 500k docs). Best perf/scale. |
| **MySQL FULLTEXT** | `mysql` | `ext-pdo-mysql`, MySQL 8.0+ | **194 q/sec** | Existing MySQL projects, Latin content. With `innodb_ft_min_token_size=1`, handles CJK and ~500k docs. |
| **FileEngine** | `file` | PHP 8.2+ only | **29 q/sec** | Serverless, embedded, no-DB environments. Stable up to 1M+ docs for moderate usage (admin, intranet). |

All engines support the same API, operators, features — switch by changing one `.env` value. 810 tests validate cross-engine consistency.

---

## When to use illumi-search

illumi-search fits most Laravel projects that need full-text search **without an external service**. Your data stays on your servers — no monthly fees, no third-party APIs, no network latency. Switch between engines by changing one `.env` value.

### ✅ Great for

| Use case | Recommended engine | Why |
|----------|-------------------|-----|
| **Monolithic Laravel app** (solo, startup, MVP) | SQLite FTS5 | Zero config, fast enough, no external service |
| **Self-hosted Algolia/MeiliSearch replacement** | PostgreSQL | Keep data on your servers, same API, < 1ms warm latency |
| **Multi-engine setup** (SQLite dev → PgSQL prod) | All | Switch by changing one `.env` value, same code, same API |
| **CRM, ERP, intranet** (30k–500k docs) | PostgreSQL | Handles moderate traffic, scales to team usage |
| **E-commerce catalog** | PgSQL / MySQL | Weighted search (title > description), boolean operators, suggestions |
| **Documentation / library content** (serverless) | FileEngine | No database needed, stable p50 under 50ms up to 1M+ docs |
| **Multi-language content** (CJK, Arabic, Cyrillic) | SQLite / PostgreSQL | CJK separation, Arabic normalization, accent-insensitive out of the box |

### ⚠️ Less suited for

| Situation | Why |
|-----------|-----|
| **High-traffic public sites** (> 100 concurrent users, > 15 q/sec sustained) | Consider ElasticSearch or MeiliSearch for dedicated search infrastructure |
| **Distributed / multi-server search** | illumi-search is a single-server library — no native clustering or replication. |
| **Real-time indexing at scale** (millions of writes/minute) | Indexing is synchronous by default. Queue mode helps but ElasticSearch scales better. |

---

## Quick Start (30 seconds)

### 1. Add the trait to your model

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

// Simple
$results = IllumiSearch::query('laravel')->get();

// Boolean operators
$results = IllumiSearch::query('php AND laravel')->get();
$results = IllumiSearch::query('php OR python')->get();
$results = IllumiSearch::query('php NOT java')->get();

// Phrase, prefix wildcard
$results = IllumiSearch::query('"design patterns"')->get();
$results = IllumiSearch::query('prog*')->get();

// Multi-model, pagination, count
$results = IllumiSearch::query('laravel')->models([Post::class, Comment::class])->get();
$total  = IllumiSearch::query('laravel')->count();
$page   = IllumiSearch::query('laravel')->paginate(15);

// Filters, aggregations, recency boost
$results = IllumiSearch::query('php')->where('price', '>', 20)->get();
$counts  = IllumiSearch::query('php')->aggregate('category');
$results = IllumiSearch::query('php')->boost('created_at', 0.1)->get();
```

---

## Features

| Feature | Supported | Detail |
|---------|:---------:|--------|
| Boolean operators | ✅ | AND, OR, NOT, NEAR |
| Phrase search | ✅ | `"exact phrase"` |
| Prefix wildcard | ✅ | `prog*` matches "programming" |
| Spellcheck | ✅ | Trigram + Levenshtein + script penalty |
| CJK / RTL | ✅ | Chinese, Arabic, Cyrillic, accents |
| 33 stopword languages | ✅ | Arabic, English, French, Russian, Chinese... |
| Accent-insensitive | ✅ | `genie` → `génie` (PostgreSQL: unaccent; others: PHP) |
| Multi-tenant isolation | ✅ | Separate indexes per tenant |
| Authorization (Laravel Gate) | ✅ | `->withAuthorization($user)` |
| REST API | ✅ | `GET /api/illumi-search?q=laravel` (configurable via `ILLUMI_SEARCH_API_PREFIX`) |
| Result highlighting | ✅ | `<mark>` snippets |
| DebugBar integration | ✅ | Per-query timing & engine info |
| Search cache | ✅ | File-based, cleared on upsert/delete |
| WAL mode (SQLite) | ✅ | Concurrent reads |
| Atomic swap rebuild (MySQL) | ✅ | Zero-downtime index rebuild |
| FileEngine concurrent processing | ✅ | `pcntl_fork` for parallel chunk rebuild |
| **Faceted search** | ✅ | `->where('genre', 'php')->where('price', '>', 20)` — PHP post-filter |
| **Aggregations** | ✅ | `->aggregate('category')` → `['PHP' => 42, 'JS' => 15]` |
| **Recency boost** | ✅ | `->boost('created_at', 0.1)` — rank newer documents higher |

---

## Search Modes

| Mode | Description | Example |
|------|-------------|---------|
| `advanced` | Boolean operators + phrases + wildcards | `php AND "laravel framework"` |
| `basic` | Simple keywords, quoted = exact, all terms required | `php laravel` |
| `raw` | No preprocessing, engine-native syntax | `php* AND "laravel*"` |

---

## Operators

| Syntax | Example | Description |
|--------|---------|-------------|
| Single term | `laravel` | Documents containing "laravel" |
| AND | `php AND laravel` | Both terms required |
| OR | `php OR python` | At least one term |
| NOT | `php NOT java` | Exclude |
| Phrase | `"software engineering"` | Exact consecutive words |
| Prefix | `prog*` | Prefix matching |
| NEAR | `php NEAR framework` | AND + distance filter (default 5 tokens) |

> `()` grouping is not supported. Use simple operator combinations.

---

## Multi-language

Out of the box, all engines handle:

| Language Family | Example | How |
|----------------|---------|-----|
| **Latin (accented)** | `développement`, `desarrollo` | Unicode normalization + remove diacritics |
| **CJK** | `软件` (Chinese) | CJK character separation with spaces |
| **RTL** | `برمجيات` (Arabic) | PHP TextProcessor normalizes before indexing |
| **Cyrillic** | `проект` (Russian) | Unicode-aware tokenization |

The `UnicodeTextProcessor` pipeline normalizes all text **at index time** — the same pipeline runs regardless of engine. This guarantees cross-engine consistency.

| Step | Effect | Example |
|------|--------|---------|
| `strip_tags()` | Remove HTML | `<p>Hello</p>` → `Hello` |
| Unicode normalization | NFC | `ñ` → `n` + combining |
| Remove diacritics | Strip accents | `café` → `cafe` |
| CJK separation | Space between chars | `开发` → `开 发` |
| `mb_strtolower()` | Lowercase | `Hello` → `hello` |
| Stopword filter | Remove common words | `the php` → `php` (33 languages) |
| Token truncation | Limit length | URLs truncated to 32 chars |
| Clean whitespace | Collapse spaces | `a    b` → `a b` |

For stemming, use the `StemmingTextProcessor`:

```env
ILLUMI_SEARCH_PROCESSOR=stemming
```

This uses the Snowball stemmer via `wamania/php-stemmer` (17 languages). Applied at index time + query time.

---

## Faceted search (filters)

Post-filter search results based on Eloquent model attributes. Requires real models (not test stubs).

```php
$results = IllumiSearch::query('php')
    ->model(Book::class)
    ->where('category', 'framework')     // equality
    ->where('price', '>', 20)            // numeric operators: >, <, >=, <=, !=
    ->whereIn('genre', ['php', 'js'])    // IN array
    ->whereNotNull('rating')             // not null
    ->whereBetween('price', [10, 50])    // inclusive range
    ->get();
```

Filters are applied in PHP after the index search — they work on the top N results (default 20). For large datasets, use a broader search query to capture enough candidates.

## Aggregations

Count results grouped by a model attribute:

```php
$counts = IllumiSearch::query('php')
    ->model(Book::class)
    ->aggregate('category');
// collect(['Framework' => 42, 'Language' => 15, 'Tool' => 8])

Returns a Laravel `Collection` — you can chain `sortDesc()`, `take(5)`, `toJson()`, etc.
Works on the top N results (configurable via `limit()`).

## Recency / popularity boost

Boost newer or more popular documents higher in the ranking. Any model column (timestamp or numeric) works.

```php
$results = IllumiSearch::query('php')
    ->model(Book::class)
    ->boost('published_at', 0.3)          // timestamp: newer first
    ->get();

$results = IllumiSearch::query('php')
    ->model(Post::class)
    ->boost('created_at', 0.1)            // timestamp: +10% per day of recency
    ->get();

$results = IllumiSearch::query('php')
    ->model(Book::class)
    ->boost('popularity', 0.3)            // numeric: popular content first
    ->get();

// Combined boosts are cumulative
$results = IllumiSearch::query('php')
    ->model(Book::class)
    ->boost('created_at', 0.1)
    ->boost('popularity', 0.3)
    ->get();
```

Date boost decays linearly over 30 days. A document created today gets the full boost; one 30+ days old gets none. Numeric boost (popularity, views, etc.) scales proportionally — higher values get higher boost.

## Spellcheck

```php
$suggestions = IllumiSearch::didYouMean('programing');
// ['programming']

$suggestions = IllumiSearch::didYouMean('lavarel');
// ['laravel']
```

Two-phase approach:
1. **Trigram matching** — shared trigrams between query and vocabulary words
2. **Prefix Levenshtein** — 2-char prefix filter + edit distance

Script-aware: Latin queries give Latin suggestions, Arabic → Arabic, etc. Script mismatch adds +3 to distance.

---

## REST API

```env
ILLUMI_SEARCH_API_ENABLED=true
```

```
GET /api/illumi-search?q=laravel

# Custom prefix
ILLUMI_SEARCH_API_PREFIX=api/illumi-search  (default)
ILLUMI_SEARCH_API_PREFIX=api/search         (legacy)
ILLUMI_SEARCH_API_PREFIX=api/custom-search
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `q` | string | — | Search query (max 200 chars) |
| `models` | string/array | All indexed | Comma-separated or array |
| `limit` | int | 10 | Max results (max 50) |
| `mode` | string | `advanced` | `basic`, `advanced`, `raw` |
| `suggest` | bool | `false` | Include spellcheck suggestions |

Response:

```json
{
  "results": [
    {
      "modelClass": "App\\Models\\Book",
      "modelId": 42,
      "rank": 0.85,
      "title": "Laravel for Pros",
      "summary": "A guide to <mark>laravel</mark> framework",
      "totalCount": 1
    }
  ],
  "total": 1,
  "suggestions": []
}
```

---

## Artisan Commands

| Command | Description |
|---------|-------------|
| `illumi-search:rebuild` | Full re-index (clear + rebuild) |
| `illumi-search:sync` | Sync unsynced records |
| `illumi-search:search` | CLI search (`--json`, `--suggest`) |
| `illumi-search:status` | Index stats per model, size, engine version |
| `illumi-search:doctor` | Environment diagnostics |
| `illumi-search:check` | Schema drift detection |
| `illumi-search:optimize` | VACUUM / OPTIMIZE TABLE |
| `illumi-search:benchmark` | Performance + quality benchmark (`--all-engines`) |
| `illumi-search:discover-filament` | Analyze Filament Resources |

---

## Performance (1000 docs benchmark)

**Full capacity report (1k to 1M+ docs per engine):** See [BENCHMARK_CAPACITY.md](BENCHMARK_CAPACITY.md) — cold vs warm PostgreSQL, CJK impact on MySQL/MariaDB, FileEngine stable latency, and per-engine latency curves.

| Metric | SQLite | FileEngine | MySQL | PostgreSQL |
|--------|:------:|:----------:|:-----:|:----------:|
| **Search (exact)** [q/sec] | **741** | 29 | 194 | 340 |
| **Search (nonexistent)** [q/sec] | 1784 | 7 | 309 | **430** |
| **Suggest** [q/sec] | **192** | 20 | 17 | 31 |
| **Latency p50** [ms] | **0.98** | 34 | 5 | 2.5 |
| **Latency p95** [ms] | **2.55** | 37 | 9.9 | 9.9 |
| **Latency p99** [ms] | **6.27** | 53 | 12.4 | 10.1 |

Quality (all 4 engines):

| Metric | SQLite | FileEngine | MySQL | PostgreSQL |
|--------|:------:|:----------:|:-----:|:----------:|
| Fuzzy tolerance | ✓ | ✓ | ✓ | ✓ |
| Suggest Prec@5 | 1.0 | 1.0 | 1.0 | 1.0 |
| Suggest coverage | 1.0 | 1.0 | 1.0 | 1.0 |
| Accent insensitivity | ✓ | ✓ | ✓ | ✓ |
| Phrase exacte | ✓ | ✓ | ✓ | ✓ |
| Prefix wildcard | ✓ | ✓ | ✓ | ✓ |

---

## Limitations

All limits below are given for **sustained load (> 15 concurrent queries/sec)**. In real-world usage (admin panels, intranets, team apps, moderate traffic sites), engines comfortably handle several times these volumes.

| Engine | Sustained load | Real-world (team/admin) | What doesn't work / limitations |
|--------|:--------------:|:-----------------------:|----------------------------------|
| **SQLite FTS5** | ~50k docs | **300k+ docs** | No cloud storage (local file). Index lost on redeploy (Vapor, Kubernetes). No concurrent writes. |
| **PostgreSQL** | ~500k docs (warm) | **> 1M docs** | No native stemming — `simple` dictionary used. Cold start ~158ms — needs warmup (~100 queries after deploy). |
| **MariaDB** | ~50k docs | **~300k docs** | `innodb_ft_min_token_size=3` is read-only — CJK tokens < 3 chars ignored by FULLTEXT. LIKE fallback activated automatically. |
| **MySQL 8.0+** | ~50k docs | **~500k docs** | Add `innodb_ft_min_token_size=1` to my.cnf then rebuild for native CJK FULLTEXT. Without it, same limitation as MariaDB. |
| **FileEngine** | ~250k docs | **> 1M docs** | 2–29 q/sec. RAM 40–225 MB. p50 stable at ~47ms. Not recommended for high-traffic public sites. |
| **All** | — | — | No distributed clustering. Single-server library. `()` grouping is not supported. |

---

## Configuration

Full reference: `config/illumi-search.php`

### Engine selection

```env
ILLUMI_SEARCH_DRIVER=sqlite        # default
ILLUMI_SEARCH_DRIVER=mysql
ILLUMI_SEARCH_DRIVER=file
ILLUMI_SEARCH_DRIVER=pgsql
```

### Shared

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_MODE` | `processing.mode` | `advanced` | `basic`, `advanced`, `raw` |
| `ILLUMI_SEARCH_PROCESSOR` | `processing.processor` | `unicode` | `unicode`, `stemming` |
| `ILLUMI_SEARCH_INDEXING` | `indexing.mode` | `queue` | `queue`, `sync`, `manual` |
| `ILLUMI_SEARCH_TENANCY` | `tenancy.enabled` | `false` | Multi-tenant isolation |
| `ILLUMI_SEARCH_API_ENABLED` | `api.enabled` | `false` | REST API |
| `ILLUMI_SEARCH_AUTHORIZATION` | `authorization.enabled` | `false` | Laravel Gate |

### Per-engine

| Engine | Env | Config key | Default |
|--------|-----|-----------|---------|
| SQLite | `ILLUMI_SEARCH_DATABASE_PATH` | `engines.sqlite.database_path` | `app/search/search-index.sqlite` |
| MySQL | `ILLUMI_SEARCH_MYSQL_HOST` | `engines.mysql.connection.host` | `127.0.0.1` |
| MySQL | `ILLUMI_SEARCH_MYSQL_DATABASE` | `engines.mysql.connection.database` | `illumi_search` |
| PgSQL | `ILLUMI_SEARCH_PGSQL_HOST` | `engines.pgsql.connection.host` | `127.0.0.1` |
| PgSQL | `ILLUMI_SEARCH_PGSQL_DATABASE` | `engines.pgsql.connection.database` | `illumi_search` |
| FileEngine | `ILLUMI_SEARCH_FILE_BASE_PATH` | `engines.file.base_path` | `storage/app/illumi-search-file-engine` |

---

## Multi-tenant Isolation

```php
app(TenantManager::class)->setResolver(fn () => tenant()->id);
```

| Engine | Isolation |
|--------|-----------|
| SQLite | Separate database file per tenant |
| MySQL / PgSQL | Separate tables with `{tenant_id}_` prefix |
| FileEngine | Separate directory per tenant |

Cache keys include tenant ID — no cross-tenant cache leaks.

---

## Model Setup

```php
class Post extends Model
{
    use Searchable;

    // Simple — all weight = 1
    protected array $searchable = ['title', 'body'];

    // Weighted — BM25 column boosting
    protected array $searchable = [
        'title' => ['weight' => 3],
        'body'  => ['weight' => 1],
    ];

    // Dot notation for relations
    protected array $searchable = [
        'author.name' => ['weight' => 3],
        'comments.body' => ['weight' => 1],
    ];

    // Custom document (return what the engine indexes)
    public function toSearchDocument(): array
    {
        return [
            'title' => $this->title,
            'body'  => strip_tags($this->body),
        ];
    }
}
```

---

## Testing

```bash
phpunit                                      # 810 tests, 1749 assertions
phpunit --filter="SqliteQualityTest"         # Quality suite — SQLite
phpunit --filter="PgsqlQualityTest"          # Quality suite — PostgreSQL
phpunit --filter="CrossEngineConsistency"    # Same queries, same results across 4 engines
phpunit --filter="MultiLangConsistency"      # 7 languages, CJK, RTL, Cyrillic
phpunit --filter="SearchApiTest"             # REST API
php artisan illumi-search:benchmark          # Performance + quality

PHPStan level 6:
composer analyse
```

### Test suite overview

| Suite | Tests | What it covers |
|-------|:-----:|----------------|
| `QualityTestSuite` | 38 | Operators, modes, suggest, ranking, edge cases (reused by all engines) |
| `AbstractEngineTest` | 34 | Cross-engine ranking, snippets, pagination, modes |
| `MultiLangConsistencyTest` | 14 | CJK, Arabic, Cyrillic, accents, Spanish, Portuguese |
| `CrossEngineConsistencyTest` | 6 | Same query → same documents across 4 engines |
| `SearchApiTest` | 7 | REST API endpoint, validation, suggest |
| `AuthorizationTenancyTest` | 3 | Tenant config, resolver, disabled |
| `IndexManagerRebuildTest` | 5 | Rebuild, idempotence, drop+recreate |
| Engine-specific integration | ~60 | Upsert, cache, concurrent, memory, `vacuum()` |
| Unit tests | ~600 | Methods, traits, processors, stopwords, exceptions |

---

## Package Structure

```
illumi-search/
├── src/
│   ├── Engines/
│   │   ├── SqliteEngine.php       # FTS5 — 741 q/sec
│   │   ├── PgsqlEngine.php        # tsvector + GIN + CTE
│   │   ├── MySqlEngine.php        # FULLTEXT + LIKE fallback
│   │   └── FileEngine.php         # Flat-file + trigram index
│   ├── Concerns/
│   │   ├── HasTenant.php          # Tenant ID resolution
│   │   └── HasWeightedColumns.php # Shared column helpers
│   ├── Text/                      # 4 traits, 3 processors
│   ├── Support/                   # 15+ services
│   ├── Console/Commands/          # 9 artisan commands
│   └── Http/                      # REST API controller
├── tests/                         # 803 tests
├── config/illumi-search.php       # Per-engine configuration
└── resources/stopwords/           # 33 stopword lists
```

---

## License

MIT

---

*illumi-search is a independent Laravel package. Not affiliated with Laravel, MySQL, PostgreSQL, or SQLite.*

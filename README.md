# Illumi Search — Full-Text Search for Laravel

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

**One interface, four engines.** Plug-and-play full-text search for Laravel projects small to medium (1k–500k documents).

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
| **SQLite FTS5** | `sqlite` (default) | `ext-sqlite3`, `ext-mbstring` | **741 q/sec** | Small datasets, single-server, zero-config |
| **PostgreSQL** | `pgsql` | `ext-pdo-pgsql`, PostgreSQL 12+ | **340 q/sec** | Moderate datasets, GIN-indexed tsvector |
| **MySQL FULLTEXT** | `mysql` | `ext-pdo-mysql`, MySQL 8.0+ | **194 q/sec** | Replicated databases, managed DB |
| **FileEngine** | `file` | PHP 8.2+ only | **29 q/sec** | Embedded, serverless, no-DB required |

All engines support the same API, operators, features — switch by changing one `.env` value. 810 tests validate cross-engine consistency.

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
| REST API | ✅ | `GET /api/search?q=laravel` |
| Result highlighting | ✅ | `<mark>` snippets |
| DebugBar integration | ✅ | Per-query timing & engine info |
| Search cache | ✅ | File-based, cleared on upsert/delete |
| WAL mode (SQLite) | ✅ | Concurrent reads |
| Atomic swap rebuild (MySQL) | ✅ | Zero-downtime index rebuild |
| FileEngine concurrent processing | ✅ | `pcntl_fork` for parallel chunk rebuild |

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
GET /api/search?q=laravel
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

| Engine | What doesn't work / limitations |
|--------|----------------------------------|
| **SQLite FTS5** | No cloud storage (local file). Index lost on redeploy (Vapor, Kubernetes). No concurrent writes. | 
| **PostgreSQL** | No native stemming — `simple` dictionary used. `ts_stat()` on empty table returns 0 rows. Cold start ~158ms at 100k — warmup needed after deploy (~100 queries to drop to 0.2ms). |
| **MariaDB / MySQL** | `innodb_ft_min_token_size=3` limits CJK tokens < 3 chars. **MariaDB:** read-only, LIKE fallback activated. **MySQL 8.0+:** add `innodb_ft_min_token_size=1` to my.cnf then rebuild for native CJK FULLTEXT. |
| **FileEngine** | 2–29 q/sec (vs 741 for SQLite). Works up to 1M+ docs but not recommended for latency-critical apps. RAM 40–225 MB. |
| **All** | No distributed clustering. Single-server library. `()` grouping is not supported. `max_documents_per_model` limits by model_id ordering — uses `pruneExcessDocuments()` after `insertBatch()`. FileEngine prune is no-op (use `rebuild --force` to apply). |

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

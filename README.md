# Illumi Search — Full-Text Search for Laravel

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

**Write search code once. Switch engines by changing one `.env` value.**

Laravel full-text search with your choice of backends — SQLite, MySQL, PostgreSQL, FileEngine, Meilisearch. Same API, same operators, same 820+ tests validating cross-engine consistency. Use your app's own database (SQLite, MySQL, PostgreSQL — zero extra infrastructure) or add a dedicated search server (Meilisearch — instant typo tolerance). Switch backends by changing one `.env` value — your application code never changes.

> **Why not just Laravel's native `whereFullText`?**
> Laravel 13's built-in full-text search covers basic `MATCH/AGAINST` (MySQL) and
> `to_tsvector` (PostgreSQL) — but it has no SQLite FTS5 support, no boolean query
> language, and no spellcheck. illumi-search adds a full operator set
> (`AND`/`OR`/`NOT`/`NEAR`/phrase/wildcard), spellcheck, CJK/RTL handling,
> faceted search, and cross-engine consistency — validated by 820+ tests that
> guarantee the same results regardless of backend.

```bash
composer require moaines/illumi-search

# Search with the default engine (SQLite):
$results = IllumiSearch::query('laravel')->get();        // < 1ms

# Switch to PostgreSQL or Meilisearch — same code:
# ILLUMI_SEARCH_DRIVER=pgsql
# ILLUMI_SEARCH_DRIVER=meilisearch
```

---

## Quick Start (30 seconds)

```php
// 1. Add the trait to your model
class Post extends Model
{
    use Moaines\IllumiSearch\Searchable;
    protected array $searchable = ['title', 'body'];
}

// 2. Build the index
// php artisan illumi-search:rebuild

// 3. Search
use Moaines\IllumiSearch\Facades\IllumiSearch;

$results = IllumiSearch::query('laravel')->get();               // simple
$results = IllumiSearch::query('php AND laravel')->get();       // boolean
$results = IllumiSearch::query('"design patterns"')->get();     // phrase
$results = IllumiSearch::query('prog*')->get();                 // prefix wildcard
$total  = IllumiSearch::query('laravel')->count();              // count only
$page   = IllumiSearch::query('laravel')->paginate(15);         // paginated
```

---

## Choose your engine

| Engine | `ILLUMI_SEARCH_DRIVER` | Requirements | Search speed | Best for |
|--------|------------------------|--------------|:------------:|----------|
| **SQLite FTS5** | `sqlite` (default) | `ext-sqlite3`, `ext-mbstring` | **741 q/sec** | Most Laravel projects — shops < 50k items, admin panels, intranets, content sites. Zero config. |
| **PostgreSQL** | `pgsql` | `ext-pdo-pgsql`, PostgreSQL 12+ | **340 q/sec** | Multi-language apps, moderate-high traffic, large volumes (> 500k docs). Best perf/scale. |
| **MySQL FULLTEXT** | `mysql` | `ext-pdo-mysql`, MySQL 8.0+ | **194 q/sec** | Existing MySQL projects, Latin content. With `innodb_ft_min_token_size=1`, handles CJK and ~500k docs. |
| **FileEngine** | `file` | PHP 8.2+ only | **29 q/sec** | Serverless, embedded, no-DB environments. Stable up to 1M+ docs for moderate usage (admin, intranet). |
| **Meilisearch** | `meilisearch` | `meilisearch/meilisearch-php`, Meilisearch server (Docker/binary) | **247 q/sec** | Dedicated search infrastructure. Typo-tolerant, instant ranking, scales to millions of docs. Best for medium-to-large datasets. |

**All engines share the same API.** Switch by changing `ILLUMI_SEARCH_DRIVER` in `.env`. 820+ tests guarantee the same results regardless of engine.

---

## When to use illumi-search

Embedded engines (SQLite, FileEngine) need nothing — your Laravel app is your search server. Meilisearch adds dedicated search infrastructure when your dataset or traffic grows. All share the same API, so you start simple and upgrade by changing `.env`.

### ✅ Great for

| Use case | Recommended engine | Why |
|----------|-------------------|-----|
| **Monolithic Laravel app** (solo, startup, MVP) | SQLite FTS5 | Zero config, fast enough, no external service |
| **Self-hosted Algolia/MeiliSearch replacement** | Meilisearch / PostgreSQL | Meilisearch: native typo tolerance, instant ranking. PostgreSQL: < 1ms warm latency, no extra infrastructure. |
| **Multi-engine setup** (SQLite dev → PgSQL/Meili prod) | All | Switch by changing one `.env` value, same code, same API |
| **CRM, ERP, intranet** (30k–500k docs) | PostgreSQL | Handles moderate traffic, scales to team usage |
| **E-commerce catalog** | PgSQL / MySQL | Weighted search (title > description), boolean operators, suggestions |
| **Documentation / library content** (serverless) | FileEngine | No database needed, stable p50 under 50ms up to 1M+ docs |
| **Multi-language content** (CJK, Arabic, Cyrillic) | SQLite / PostgreSQL | CJK separation, Arabic normalization, accent-insensitive out of the box |
| **Typo-tolerant search** (public site, e-commerce) | Meilisearch | Native typo tolerance, instant ranking, < 5ms latency regardless of volume |

### ⚠️ Less suited for

| Situation | Why |
|-----------|-----|
| **Extremely high-traffic public sites** (> 500 concurrent users, > 200 q/sec sustained) | Consider ElasticSearch for dedicated clustered search infrastructure |
| **Distributed / multi-server search** | illumi-search is a single-server library — no native clustering or replication. |
| **Real-time indexing at scale** (millions of writes/minute) | Indexing is synchronous by default. Queue mode helps but ElasticSearch scales better. |
| **Meilisearch-specific ranking (NEAR, column-weight BM25)** | Not natively supported by Meilisearch DSL. The benchmark validates comparable quality (NDCG@5 = 0.99) using Meilisearch's own ranking rules (`attribute`, `proximity`, `exactness`). |

---

## Features

All 19 features work identically across all built-in engines.

| Feature | Support | Detail |
|---------|:-------:|--------|
| Boolean operators | ✅ | AND, OR, NOT, NEAR across all engines |
| Phrase search | ✅ | `"exact phrase"` — native on Meilisearch, FTS syntax on others |
| Prefix wildcard | ✅ | `prog*` matches "programming" |
| Spellcheck | ✅ | Trigram or Levenshtein (per-engine, see advanced features) |
| CJK / RTL | ✅ | Chinese, Arabic, Cyrillic, accents |
| Stopword filtering | ✅ | Search stopwords (grammatical only) for EN, FR, ES, PT, AR, RU, ZH |
| Accent-insensitive | ✅ | `genie` → `génie` (PostgreSQL: unaccent; others: PHP) |
| Multi-tenant isolation | ✅ | Separate indexes per tenant |
| Authorization (Laravel Gate) | ✅ | `->withAuthorization($user)` |
| REST API | ✅ | `GET /api/illumi-search?q=laravel` |
| Result highlighting | ✅ | `<mark>` snippets |
| DebugBar integration | ✅ | Per-query timing & engine info |
| Search cache | ✅ | File-based, cleared on upsert/delete |
| Faceted search | ✅ | PHP post-filter on top N results |
| Aggregations | ✅ | `->aggregate('category')` |
| Recency / popularity boost | ✅ | `->boost('created_at', 0.1)` |
| Filament integration | ✅ | Spotlight search, Search Status page |

---

## Performance

| Metric | SQLite | FileEngine | MySQL | PostgreSQL | Meilisearch |
|--------|:------:|:----------:|:-----:|:----------:|:-----------:|
| **Search (exact)** | 741 q/s | 29 q/s | 194 q/s | 340 q/s | 218 q/s |
| **Latency p50** | 0.98 ms | 34 ms | 5 ms | 2.5 ms | 4.4 ms |
| **Latency p95** | 2.55 ms | 37 ms | 9.9 ms | 9.9 ms | 5.8 ms |
| **NDCG@5** (ranking) | 0.85 | 0.89 | 0.85 | 0.85 | **0.99** |
| **Upsert speed** | 1939 d/s | 17009 d/s | 5200 d/s | 7000 d/s | ~65 d/s |

**Detailed capacity report:** [BENCHMARK_CAPACITY.md](BENCHMARK_CAPACITY.md) — 1k to 1M+ docs per engine, cold vs warm PostgreSQL, CJK impact on MySQL.

**Key trade-off:** Meilisearch offers the best ranking (NDCG@5 = 0.99) and volume-independent latency, but upsert/rebuild is slower due to HTTP round-trips. SQLite and PostgreSQL are fastest for write-heavy workloads.

---

## Configuration

Full reference: `config/illumi-search.php`

### Driver selection

```env
ILLUMI_SEARCH_DRIVER=sqlite        # default — zero config, works immediately
ILLUMI_SEARCH_DRIVER=pgsql         # PostgreSQL 12+ with tsvector
ILLUMI_SEARCH_DRIVER=mysql         # MySQL 8.0+ with FULLTEXT
ILLUMI_SEARCH_DRIVER=file          # no database needed
ILLUMI_SEARCH_DRIVER=meilisearch   # dedicated search server
```

### Shared settings

| Env | Config key | Default | Description |
|-----|-----------|---------|-------------|
| `ILLUMI_SEARCH_MODE` | `processing.mode` | `advanced` | `basic`, `advanced`, `raw` |
| `ILLUMI_SEARCH_PROCESSOR` | `processing.processor` | `unicode` | `unicode`, `stemming` |
| `ILLUMI_SEARCH_INDEXING` | `indexing.mode` | `queue` | `queue`, `sync`, `manual` |
| `ILLUMI_SEARCH_TENANCY` | `tenancy.enabled` | `false` | Multi-tenant isolation |
| `ILLUMI_SEARCH_API_ENABLED` | `api.enabled` | `false` | REST API |
| `ILLUMI_SEARCH_AUTHORIZATION` | `authorization.enabled` | `false` | Laravel Gate |

### Per-engine settings

| Engine | Env | Config key | Default |
|--------|-----|-----------|---------|
| SQLite | `ILLUMI_SEARCH_DATABASE_PATH` | `engines.sqlite.database_path` | `app/search/search-index.sqlite` |
| MySQL | `ILLUMI_SEARCH_MYSQL_HOST` | `engines.mysql.connection.host` | `127.0.0.1` |
| MySQL | `ILLUMI_SEARCH_MYSQL_DATABASE` | `engines.mysql.connection.database` | `illumi_search` |
| PgSQL | `ILLUMI_SEARCH_PGSQL_HOST` | `engines.pgsql.connection.host` | `127.0.0.1` |
| PgSQL | `ILLUMI_SEARCH_PGSQL_DATABASE` | `engines.pgsql.connection.database` | `illumi_search` |
| FileEngine | `ILLUMI_SEARCH_FILE_BASE_PATH` | `engines.file.base_path` | `storage/app/illumi-search-file-engine` |
| Meilisearch | `ILLUMI_SEARCH_MEILISEARCH_HOST` | `engines.meilisearch.host` | `http://localhost:7700` |
| Meilisearch | `ILLUMI_SEARCH_MEILISEARCH_KEY` | `engines.meilisearch.api_key` | `''` |

---

## Query language

The same query syntax works across all engines.

### Modes

| Mode | Description | Example |
|------|-------------|---------|
| `advanced` | Boolean operators + phrases + wildcards | `php AND "laravel framework"` |
| `basic` | Keywords, quoted = exact, all terms required | `php laravel` |
| `raw` | Passthrough — engine-native syntax | `php* AND "laravel*"` |

### Operators

| Syntax | Description | Example |
|--------|-------------|---------|
| `term` | Documents containing the term | `laravel` |
| `AND` | Both terms required | `php AND laravel` |
| `OR` | At least one term | `php OR python` |
| `NOT` | Exclude | `php NOT java` |
| `"phrase"` | Exact consecutive words | `"software engineering"` |
| `prefix*` | Prefix matching | `prog*` |
| `NEAR` | AND + distance filter (default 5 tokens) | `php NEAR framework` |

---

## Multi-language

All engines handle multilingual content through the same `UnicodeTextProcessor` pipeline at index time:

| Language | Example | Processing |
|----------|---------|------------|
| Latin (accented) | `développement`, `desarrollo` | Unicode normalization + diacritics removal |
| CJK | `软件` (Chinese) | Character separation with spaces |
| RTL | `برمجيات` (Arabic) | Arabic normalization |
| Cyrillic | `проект` (Russian) | Unicode-aware tokenization |

**Processing pipeline** (applied at index time, identical across engines):

`strip_tags()` → NFC normalization → diacritics removal → CJK separation → lowercase → stopword filter (EN, FR, ES, PT, AR, RU, ZH) → token truncation → whitespace cleanup

**Query normalization keeps all terms** — stopwords are never removed from queries. IllumiSearch matches prefixes (`help*`), so a partial term always matches longer indexed words (`helped`). Stopwords are minimal grammatical words (articles, pronouns, prepositions) used to reduce index noise; they never censor query terms, and lexical words (`help`, `research`, `system`) are never excluded.

For stemming, set `ILLUMI_SEARCH_PROCESSOR=stemming` (requires `wamania/php-stemmer`, supports 17 languages via Snowball).

---

## Advanced features

### Faceted search

```php
IllumiSearch::query('php')
    ->model(Book::class)
    ->where('category', 'framework')
    ->where('price', '>', 20)
    ->whereIn('genre', ['php', 'js'])
    ->whereNotNull('rating')
    ->whereBetween('price', [10, 50])
    ->get();
```

### Aggregations

```php
$counts = IllumiSearch::query('php')
    ->model(Book::class)
    ->aggregate('category');
// collect(['Framework' => 42, 'Language' => 15, 'Tool' => 8])
```

### Recency / popularity boost

```php
IllumiSearch::query('php')
    ->model(Book::class)
    ->boost('created_at', 0.1)    // newer first (decays over 30 days)
    ->boost('popularity', 0.3)    // higher values = higher boost
    ->get();
```

### Spellcheck

```php
IllumiSearch::didYouMean('programing');   // ['programming']
IllumiSearch::didYouMean('lavarel');       // ['laravel']
```

Each engine uses a different approach:

| Engine | Trigrams | Levenshtein | Script penalty |
|--------|:--------:|:-----------:|:--------------:|
| SQLite | — | ✅ | — |
| MySQL | — | ✅ | ✅ |
| PostgreSQL | ✅ | ✅ | — |
| FileEngine | ✅ | ✅ | — |
| Meilisearch | — | ✅ | — |

All engines: Latin → Latin suggestions, Arabic → Arabic, etc. Script mismatch adds +3 to Levenshtein distance.

---

## Multi-tenant isolation

```php
app(TenantManager::class)->setResolver(fn () => tenant()->id);
```

| Engine | Isolation strategy |
|--------|--------------------|
| SQLite | Separate database file per tenant |
| MySQL / PgSQL | Tables prefixed with `{tenant_id}_` |
| FileEngine | Separate directory per tenant |
| Meilisearch | Separate index per tenant |

---

## Model setup

```php
class Post extends Model
{
    use Moaines\IllumiSearch\Searchable;

    // Simple (all columns weight = 1)
    protected array $searchable = ['title', 'body'];

    // Weighted (BM25 column boosting)
    protected array $searchable = [
        'title' => ['weight' => 3],
        'body'  => ['weight' => 1],
    ];

    // Dot-notation for relations
    protected array $searchable = [
        'author.name' => ['weight' => 3],
        'comments.body' => ['weight' => 1],
    ];
}
```

---

## REST API

```env
ILLUMI_SEARCH_API_ENABLED=true
```

```http
GET /api/illumi-search?q=laravel
Content-Type: application/json

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
  "suggestions": ["laravell", "lavarel"]
}
```

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `q` | string | — | Search query (max 200 chars) |
| `models` | string,array | all indexed | Comma-separated or array |
| `limit` | int | 10 | Max results (max 50) |
| `mode` | string | `advanced` | `basic`, `advanced`, `raw` |
| `suggest` | bool | `false` | Include spellcheck suggestions |

Prefix configurable via `ILLUMI_SEARCH_API_PREFIX` (default: `api/illumi-search`).

---

## Artisan commands

| Command | Description |
|---------|-------------|
| `illumi-search:rebuild` | Full re-index (clear + rebuild) |
| `illumi-search:sync` | Sync unsynced records |
| `illumi-search:search` | CLI search (`--json`, `--suggest`) |
| `illumi-search:status` | Index stats per model, size, engine version |
| `illumi-search:doctor` | Environment diagnostics |
| `illumi-search:check` | Schema drift detection |
| `illumi-search:benchmark` | Performance + quality benchmark |
| `illumi-search:optimize` | VACUUM / OPTIMIZE TABLE |
| `illumi-search:make-engine` | Generate custom engine stub |
| `illumi-search:discover-filament` | Analyze Filament Resources for searchable models |

---

## Custom engines

Add support for any search service (Algolia, Typesense, Elasticsearch, etc.):

```bash
php artisan illumi-search:make-engine Algolia                     # 5 basic tests
php artisan illumi-search:make-engine Algolia --quality           # 60+ quality tests
php artisan illumi-search:make-engine Algolia --integration       # 5 CRUD + table tests
php artisan illumi-search:make-engine Algolia --all               # both (65+ tests)
php artisan illumi-search:make-engine Algolia --minimal           # no test file
```

Creates `app/Engines/Algolia.php` with all 31 methods of the `Engine` contract. 12 methods are pre-filled with defaults (config persistence, index mapping, operator support); 19 contain `// TODO` with example code from the built-in `MeilisearchEngine`.

Register in `AppServiceProvider`:

```php
IllumiSearchServiceProvider::extend('algolia', function ($app) {
    return new \App\Engines\Algolia(
        host: config('illumi-search.engines.algolia.host'),
        apiKey: config('illumi-search.engines.algolia.api_key'),
    );
});
```

**Reference implementation:** `src/Engines/MeilisearchEngine.php` — complete, production-ready API-based engine with full test coverage.

---

## Testing

```bash
phpunit                                      # 810 tests, 1749 assertions
phpunit --filter="CrossEngineConsistency"    # same queries, same results — all engines
phpunit --filter="SqliteQualityTest"         # Quality suite per engine
phpunit --filter="MultiLangConsistency"      # 7 languages, CJK, RTL, Cyrillic
php artisan illumi-search:benchmark          # Performance + quality

composer analyse                             # PHPStan level 6
```

### Test suite

| Suite | Tests | Coverage |
|-------|:-----:|----------|
| `QualityTestSuite` | 38 | Operators, modes, suggest, ranking, edge cases (reused by all built-in engines) |
| `AbstractEngineTest` | 34 | Cross-engine ranking, snippets, pagination, modes |
| `MultiLangConsistencyTest` | 14 | CJK, Arabic, Cyrillic, accents, Spanish, Portuguese |
| `CrossEngineConsistencyTest` | 6 | Same query → same results across all engines |
| Engine-specific integration | ~60 | Upsert, cache, concurrent, memory |
| Unit tests | ~600 | Methods, traits, processors, stopwords |

---

## Limitations

All limits below are for **sustained load (> 15 concurrent queries/sec)**. In real-world usage (admin panels, intranets, team apps), engines handle several times these volumes.

| Engine | Sustained load | Real-world (team/admin) | Key limitations |
|--------|:--------------:|:-----------------------:|-----------------|
| **SQLite FTS5** | ~50k docs | 300k+ docs | Local file only — index lost on cloud redeploy (Vapor, Kubernetes). No concurrent writes. |
| **PostgreSQL** | ~500k (warm) | > 1M docs | Cold start ~158ms — needs warmup (~100 queries). No native stemming. |
| **MySQL 8.0+** | ~50k docs | ~500k docs | `innodb_ft_min_token_size=1` required for CJK. MariaDB doesn't support this. |
| **FileEngine** | ~250k docs | > 1M docs | 2–29 q/sec. RAM 40–225 MB. Not for high-traffic public sites. |
| **Meilisearch** | Unlimited | Unlimited | Requires server (Docker/binary). Rebuild ~65 d/s (HTTP bound). Write-heavy workloads limited by HTTP round-trips. |
| **All** | — | — | No distributed clustering. `()` grouping not supported. |

---

## Package structure

```
illumi-search/
├── src/
│   ├── Engines/            # built-in + custom
│   ├── Concerns/           # Shared traits (tenancy, weights, rebuild)
│   ├── Text/               # Text processing pipeline (4 traits, 3 processors)
│   ├── Support/            # 15+ services (SnippetService, IndexManager, benchmarks)
│   ├── Console/Commands/   # 10 artisan commands
│   └── Http/               # REST API controller
├── tests/                  # 810+ tests
├── config/                 # Per-engine configuration
└── resources/stopwords/    # Search stopwords — 7 languages
```

---

## License

MIT

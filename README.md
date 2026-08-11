# Illumi Search — Full-Text Search for Laravel

[![Tests](https://github.com/moaines/illumi-search/actions/workflows/tests.yml/badge.svg)](https://github.com/moaines/illumi-search/actions)
[![PHP](https://img.shields.io/badge/PHP-8.2%20to%208.5-777bb4?logo=php&logoColor=white)](https://php.net)
[![Packagist](https://img.shields.io/badge/Packagist-moaines%2Fillumi--search-28a745?logo=composer)](https://packagist.org/packages/moaines/illumi-search)

**Write search code once. Switch engines by changing one `.env` value.**

Laravel full-text search with your choice of backends — SQLite, MySQL,
PostgreSQL, FileEngine, Meilisearch. Same API, same operators, same 966 tests
validating cross-engine consistency. Use your app's own database (SQLite, MySQL,
PostgreSQL — zero extra infrastructure) or add a dedicated search server
(Meilisearch — instant typo tolerance).

> **Full documentation:** [illumi-search-documentation](https://github.com/moaines/illumi-search-documentation)
> — quick start, configuration, query builder, advanced features, tenancy,
> custom engines, testing, and a full capacity benchmark report.

> **Why not just Laravel's native `whereFullText`?**
> Laravel's built-in full-text search covers basic `MATCH/AGAINST` (MySQL) and
> `to_tsvector` (PostgreSQL) — but it has no SQLite FTS5 support, no boolean
> query language, and no spellcheck. illumi-search adds a full operator set
> (`AND`/`OR`/`NOT`/`NEAR`/phrase/wildcard), spellcheck, CJK/RTL handling,
> faceted search, and cross-engine consistency.

## Quick Start (30 seconds)

```bash
composer require moaines/illumi-search
```

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
```

## Engines at a glance

| Engine | `ILLUMI_SEARCH_DRIVER` | Best for |
|--------|------------------------|----------|
| **SQLite FTS5** | `sqlite` (default) | Zero config — shops < 50k items, admin panels, intranets |
| **PostgreSQL** | `pgsql` | Multi-language, > 500k docs, best perf/scale |
| **MySQL FULLTEXT** | `mysql` | Existing MySQL projects, Latin content, ~500k docs |
| **FileEngine** | `file` | Serverless, embedded, no-DB, up to 1M+ docs |
| **Meilisearch** | `meilisearch` | Dedicated search server, typo-tolerant, millions of docs |

Switch engines by changing `.env` — your application code never changes.

## Features

Boolean operators (`AND`/`OR`/`NOT`/`NEAR`) · phrase · prefix wildcard ·
spellcheck · CJK/RTL · accent-insensitive · stopwords (7 languages) ·
multi-tenant · authorization · REST API · `<mark>` snippets · faceted search ·
aggregations · recency/popularity boost · search cache · Filament integration.

## Performance

| Metric | SQLite | FileEngine | MySQL | PostgreSQL | Meilisearch |
|--------|:------:|:----------:|:-----:|:----------:|:-----------:|
| **Search (exact)** | 741 q/s | 29 q/s | 194 q/s | 340 q/s | 218 q/s |
| **Latency p50** | 0.98 ms | 34 ms | 5 ms | 2.5 ms | 4.4 ms |
| **NDCG@5** | 0.85 | 0.89 | 0.85 | 0.85 | **0.99** |

Full capacity report (per-volume limits, cold vs warm PostgreSQL, podman
8 GiB / 4 vCPU container): [BENCHMARK_CAPACITY.md](BENCHMARK_CAPACITY.md).

## Testing

```bash
phpunit                              # 966 tests, ~1978 assertions
php artisan illumi-search:benchmark  # performance + quality
composer analyse                     # PHPStan level 6
```

## License

MIT
